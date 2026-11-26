<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\Tests\Support\RestResponse;
use BR\IonosCloud\Collector\VdcResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../collectors/src/VdcResolver.php';
require_once __DIR__ . '/../Support/RestResponse.php';

/** Verify VDC lookups against native iTop core/get replies. */
final class VdcResolverTest extends TestCase
{
    private const UUID = 'cbe1028b-907f-4254-bbc9-c05d70f02f80';
    private const CLOUD_FIELDS = ['provider_id' => '18', 'provider_resource_id' => self::UUID, 'org_id' => '27'];

    /** Check request keys, tenant boundaries and reuse of a previously verified organization. */
    public function testResolvesReferencesWithScopedQueries(): void
    {
        $blockKey = "SELECT IPv6Block WHERE org_id = 27 AND firstip_text = '2001:0db8:abcd:1200:0000:0000:0000:0000'"
            . " AND lastip_text = '2001:0db8:abcd:12ff:ffff:ffff:ffff:ffff'";
        $expected = [
            ['Organization', 18, 'id', 2],
            ['Location', ['org_id' => 18, 'name' => 'de/fra/2'], 'name,org_id', 2],
            ['Organization', 27, 'id', 2],
            ['IPv6Block', $blockKey, 'org_id', 2],
            ['Cloud', ['provider_id' => 18, 'provider_resource_id' => self::UUID], 'provider_id,provider_resource_id,org_id', 2],
        ];
        $responses = [
            RestResponse::one('Organization', 18),
            RestResponse::one('Location', 10, ['name' => 'de/fra/2', 'org_id' => '18']),
            RestResponse::one('Organization', 27),
            RestResponse::one('IPv6Block', 75, ['org_id' => '27']),
            RestResponse::one('Cloud', 82, self::CLOUD_FIELDS),
        ];
        $queries = [];
        $resolver = new VdcResolver(static function (...$args) use (&$queries, &$responses): array {
            $queries[] = $args;
            return array_shift($responses);
        });

        self::assertSame(18, $resolver->organization(18));
        self::assertSame(10, $resolver->location(18, 'de/fra/2'));
        self::assertSame(75, $resolver->ipv6Block(27, '2001:DB8:ABCD:1200::/56'));
        self::assertSame(82, $resolver->cloud(18, 27, self::UUID));
        self::assertSame($expected, $queries);
    }

    /** @return iterable<string,array{string,list<int|string>}> Invalid lookup arguments. */
    public static function invalidLookupArguments(): iterable
    {
        yield 'nonpositive organization' => ['organization', [0]];
        yield 'padded location' => ['location', [18, ' de/fra/2']];
        yield 'wrong prefix length' => ['ipv6Block', [27, '2001:db8::/64']];
        yield 'host bits set' => ['ipv6Block', [27, '2001:db8::1/56']];
        yield 'IPv4 address' => ['ipv6Block', [27, '127.0.0.1/56']];
        yield 'malformed suffix' => ['ipv6Block', [27, '2001:db8::/56/x']];
        yield 'empty prefix' => ['ipv6Block', [27, '']];
    }

    /** @param list<int|string> $arguments Lookup arguments rejected before transport. */
    #[DataProvider('invalidLookupArguments')]
    public function testRejectsMalformedInputBeforeSendingRequests(string $method, array $arguments): void
    {
        $calls = 0;
        $resolver = new VdcResolver(static function () use (&$calls): array {
            ++$calls;
            return [];
        });
        $this->expectException(RuntimeException::class);
        try {
            $resolver->$method(...$arguments);
        } finally {
            self::assertSame(0, $calls);
        }
    }

    /** @return iterable<string,array{array}> Unusable REST replies. */
    public static function invalidOrganizationResponses(): iterable
    {
        $valid = RestResponse::one('Organization', 18);
        $ambiguous = $valid;
        $ambiguous['objects'] += RestResponse::one('Organization', 19)['objects'];
        $failedObject = $valid;
        $failedObject['objects']['Organization::18']['code'] = 100;
        $invalidKey = $valid;
        $invalidKey['objects']['Organization::18']['key'] = '18invalid';

        yield 'empty response' => [[]];
        yield 'null result set' => [['code' => 0, 'objects' => null]];
        yield 'REST error' => [['code' => 1, 'message' => 'private fixture']];
        yield 'multiple results' => [$ambiguous];
        yield 'object error' => [$failedObject];
        yield 'invalid key' => [$invalidKey];
        yield 'wrong class' => [RestResponse::one('Location', 18)];
        yield 'wrong ID' => [RestResponse::one('Organization', 19)];
    }

    /** @param array<string,mixed> $response An unsuccessful or inconsistent iTop reply. */
    #[DataProvider('invalidOrganizationResponses')]
    public function testRejectsUnusableOrganizationResponses(array $response): void
    {
        $resolver = new VdcResolver(static fn () => $response);
        try {
            $resolver->organization(18);
        } catch (RuntimeException $error) {
            self::assertStringNotContainsString('private fixture', $error->getMessage());
            return;
        }
        self::fail('Resolver accepted an invalid organization response.');
    }

    /** Transport exceptions can contain sensitive HTTP content. */
    public function testTransportFailureHidesResponseDetails(): void
    {
        $resolver = new VdcResolver(static fn () => throw new RuntimeException('private fixture'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^iTop core\/get failed for Organization\.$/D');
        $resolver->organization(18);
    }

    /** @return iterable<string,array{string,int}> Mismatches in returned provider locations. */
    public static function mismatchedLocations(): iterable
    {
        yield 'case differs' => ['DE/FRA/2', 18];
        yield 'different provider' => ['de/fra/2', 19];
    }

    /** The location must belong to the provider and match the exact IONOS code. */
    #[DataProvider('mismatchedLocations')]
    public function testRejectsMismatchedLocation(string $name, int $owner): void
    {
        $resolver = new VdcResolver(static fn ($class) => $class === 'Organization'
            ? RestResponse::one($class, 18) : RestResponse::one($class, 10, ['name' => $name, 'org_id' => $owner]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from the requested provider or code');
        $resolver->location(18, 'de/fra/2');
    }

    /** A matching prefix in another tenant cannot be associated with the VDC. */
    public function testRejectsBlockOwnedByAnotherTenant(): void
    {
        $resolver = new VdcResolver(static fn ($class) => $class === 'Organization'
            ? RestResponse::one($class, 27) : RestResponse::one($class, 75, ['org_id' => 18]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belongs to a different tenant');
        $resolver->ipv6Block(27, '2001:db8::/56');
    }

    /** @return iterable<string,array{string,int|string}> Returned properties inconsistent with the expected VDC. */
    public static function mismatchedCloudFields(): iterable
    {
        yield 'wrong provider' => ['provider_id', 19];
        yield 'wrong customer' => ['org_id', 28];
        yield 'wrong resource' => ['provider_resource_id', 'another-resource'];
    }

    /** The owning tenant, provider and cloud resource identity must all match. */
    #[DataProvider('mismatchedCloudFields')]
    public function testRejectsMismatchedCloud(string $field, int|string $value): void
    {
        $resolver = new VdcResolver(static fn () => RestResponse::one('Cloud', 82, [$field => $value] + self::CLOUD_FIELDS));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('differs from the expected VDC or its owner');
        $resolver->cloud(18, 27, self::UUID);
    }
}
