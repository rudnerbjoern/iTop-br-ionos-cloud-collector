<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\NatIpResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../collectors/src/NatIpResolver.php';

/** Verify NAT host reuse, subnet reconciliation and creation with simulated iTop REST operations. */
final class NatIpResolverTest extends TestCase
{
    /** @return array<string,mixed> Successful core/get result with the supplied native fields. */
    private static function record(string $class, array $fields, int $id = 18): array
    {
        return ['code' => 0, 'message' => 'Found: 1', 'objects' => ["$class::$id" => ['class' => $class,
            'code' => 0, 'key' => (string) $id, 'fields' => $fields + ['org_id' => '5']]]];
    }

    /** @return array<string,mixed> Existing host in the test subnet. */
    private static function reply(string $class, string $host): array
    {
        return self::record($class, ['ip' => $host, 'subnet_id' => '17']);
    }

    /** @return array<string,mixed> Confirmed empty core/get result. */
    private static function emptyReply(): array
    {
        return ['code' => 0, 'objects' => null, 'message' => 'Found: 0'];
    }

    /** A test transport that rejects any unexpected IPAM write. */
    private static function readOnly(callable $get, bool $ipv6 = true): NatIpResolver
    {
        return new NatIpResolver($get, static fn () => self::fail('Unexpected IP creation.'), $ipv6);
    }

    /** A changed attachment prefix still resolves the same private host in this exact VDC. */
    public function testPrivateHostUsesLinkedSubnetOrParentBlock(): void
    {
        $get = static function ($class, $query, $fields, $limit): array {
            self::assertSame('IPv4Address', $class);
            self::assertSame(2, $limit);
            self::assertStringContainsString('a.org_id = 5', $query);
            self::assertStringContainsString('s.org_id = 5', $query);
            self::assertStringNotContainsString('s.mask', $query);
            self::assertStringContainsString('ls.cloud_id = 20', $query);
            self::assertStringContainsString('lb.cloud_id = 20', $query);
            self::assertStringContainsString('s.block_id', $query);
            return self::reply($class, '10.2.0.1');
        };
        $resolver = self::readOnly($get);
        self::assertSame(18, $resolver->resolve(5, 20, '10.2.0.1', 25));
        self::assertSame(18, $resolver->resolve(5, 20, '10.2.0.1', 26));
    }

    /** Reuse an existing public IPv6 host in the customer organization. */
    public function testPublicIpv6UsesExactLiteralAndOwner(): void
    {
        $get = static function ($class, $query): array {
            self::assertSame('IPv6Address', $class);
            self::assertStringContainsString("a.ip_text = '2001:0db8:0000:0000:0000:0000:0000:0007'", $query);
            self::assertStringContainsString('a.org_id = 5', $query);
            self::assertStringNotContainsString('JOIN', $query);
            return self::reply($class, '2001:0DB8:0:0:0:0:0:7');
        };
        self::assertSame(18, self::readOnly($get)->resolve(5, 20, '2001:db8::7', null));
    }

    /** @return iterable<string,array> Host creation fixtures for both address families and network scopes. */
    public static function networks(): iterable
    {
        yield 'public IPv4' => ['IPv4', '203.0.113.11', null, '203.0.113.0', '203.0.113.255'];
        yield 'private IPv4' => ['IPv4', '10.2.0.1', 25, '10.2.0.0', '10.2.0.255'];
        yield 'private IPv6' => ['IPv6', '2001:db8::7', 64, '2001:db8::', '2001:db8::ffff'];
    }

    /** An observed host is created once inside the selected subnet and reused thereafter. */
    #[DataProvider('networks')]
    public function testCreateInUniqueContainingSubnet(string $family, string $ip, ?int $prefix, string $first, string $last): void
    {
        $writes = [];
        $get = static function ($class, $query) use (&$writes, $family, $ip, $prefix, $first, $last): array {
            if ($class === $family . 'Address') {
                return $writes === [] ? self::emptyReply() : self::reply($class, $ip);
            }
            self::assertSame($family . 'Subnet', $class);
            self::assertStringContainsString('s.org_id = 5', $query);
            self::assertSame($prefix !== null, str_contains($query, 'ls.cloud_id = 20'));
            self::assertSame($prefix !== null, str_contains($query, 'lb.cloud_id = 20'));
            self::assertStringContainsString($family === 'IPv6' ? 's.lastip_text' : 'INET_ATON(s.broadcastip)', $query);
            self::assertStringNotContainsString('s.mask', $query);
            return self::record($class, ['ip' => $first, ($family === 'IPv6' ? 'lastip' : 'broadcastip') => $last], 17);
        };
        $create = static function ($class, $fields) use (&$writes): array {
            $writes[] = [$class, $fields];
            return self::reply($class, $fields['ip']);
        };
        $resolver = new NatIpResolver($get, $create, true);
        self::assertSame(18, $resolver->resolve(5, 20, $ip, $prefix));
        self::assertSame(18, $resolver->resolve(5, 20, $ip, $prefix));
        self::assertSame([[$family . 'Address', ['org_id' => 5, 'subnet_id' => 17, 'ip' => $ip, 'status' => 'allocated']]], $writes);
    }

    /** A lost response from an already committed create is recovered by a scoped read. */
    public function testConfirmsConcurrentOrUncertainCreation(): void
    {
        $written = false;
        $get = static function ($class) use (&$written): array {
            if ($class === 'IPv4Subnet') {
                return self::record($class, ['ip' => '10.2.0.0', 'broadcastip' => '10.2.0.255'], 17);
            }
            return $written ? self::reply($class, '10.2.0.1') : self::emptyReply();
        };
        $create = static function () use (&$written): never {
            $written = true;
            throw new RuntimeException('Response lost.');
        };
        self::assertSame(18, (new NatIpResolver($get, $create, true))->resolve(5, 20, '10.2.0.1', 24));
    }

    /** A refused creation must stop the import before an unlinked assignment can be produced. */
    public function testRejectedCreationWithNoMatchingHostStopsImport(): void
    {
        $get = static fn (string $class): array => $class === 'IPv4Subnet'
            ? self::record($class, ['ip' => '203.0.113.0', 'broadcastip' => '203.0.113.255'], 17)
            : self::emptyReply();
        $resolver = new NatIpResolver($get, static fn () => ['code' => 1], true);
        $this->expectExceptionMessage('Could not confirm IPv4Address 203.0.113.11 in IPv4Subnet 17');
        $resolver->resolve(5, 20, '203.0.113.11', null);
    }

    /** An IPv4-only target cannot represent an IPv6 assignment. */
    public function testUnsupportedIpv6DoesNotCallTransport(): void
    {
        $this->expectExceptionMessage('IPv6 management is required');
        self::readOnly(static fn () => self::fail('Unexpected lookup.'), false)->resolve(5, 20, '2001:db8::7', 64);
    }

    /** Reject ambiguous, absent or unrelated subnet results before the first write. */
    #[DataProvider('invalidSubnets')]
    public function testRequiresAnUnambiguousMatchingSubnet(array $subnets, string $expectedError): void
    {
        $this->expectExceptionMessage($expectedError);
        self::readOnly(static fn ($class) => $class === 'IPv4Address' ? self::emptyReply() : $subnets)
            ->resolve(5, 20, '10.2.0.1', 24);
    }

    /** @return iterable<string,array> Subnet search failures and identity mismatches. */
    public static function invalidSubnets(): iterable
    {
        yield 'no containing subnet' => [self::emptyReply(), 'No matching IPv4Subnet'];
        yield 'conflicting subnets' => [self::ambiguous('IPv4Subnet'), 'Multiple matching IPv4Subnet'];
        yield 'unrelated subnet from REST' => [
            self::record('IPv4Subnet', ['ip' => '10.3.0.0', 'broadcastip' => '10.3.0.255']), 'does not contain',
        ];
    }

    /** @return array<string,mixed> An ambiguous query, truncated at the requested limit of two. */
    private static function ambiguous(string $class): array
    {
        $reply = self::reply($class, '10.2.0.1');
        $reply['objects']["$class::19"] = array_replace($reply['objects']["$class::18"], ['key' => '19']);
        $reply['message'] = 'Found: 3';
        return $reply;
    }

    /** @return iterable<string,array{array}> Invalid or ambiguous host lookup responses. */
    public static function invalidResponses(): iterable
    {
        yield 'REST error' => [['code' => 1]];
        yield 'unverified empty response' => [['code' => 0, 'objects' => null]];
        yield 'truncated response' => [['code' => 0, 'objects' => null, 'message' => 'Found: 1']];
        yield 'ambiguous hosts' => [self::ambiguous('IPv4Address')];
        yield 'wrong class' => [self::reply('IPv6Address', '10.2.0.1')];
        yield 'wrong host' => [self::reply('IPv4Address', '10.2.0.3')];
        $reply = self::reply('IPv4Address', '10.2.0.1');
        $reply['objects']['IPv4Address::18']['fields']['org_id'] = '6';
        yield 'wrong owner' => [$reply];
    }

    /** @param array<string,mixed> $reply Malformed or ambiguous REST response. */
    #[DataProvider('invalidResponses')]
    public function testRejectsInvalidReplies(array $reply): void
    {
        $this->expectException(RuntimeException::class);
        self::readOnly(static fn () => $reply)->resolve(5, 20, '10.2.0.1', 24);
    }
}
