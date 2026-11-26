<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\Inventory;
use BR\IonosCloud\Collector\InventoryError;
use BR\IonosCloud\Collector\IpFacts;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../collectors/src/Inventory.php';
require_once __DIR__ . '/../../collectors/src/IonosApi.php';

/** Exercise collection and projection with synthetic IONOS responses. */
final class InventoryTest extends TestCase
{
    /** Nested resources used to verify propagation of parent coverage. */
    private const NESTED_PLAN = ['compute' => [
        'bases' => ['https://example.invalid'],
        'resources' => [
            '/dc' => ['offset' => true],
            '/dc/{dcId}/vms' => ['offset' => false],
            '/dc/{dcId}/vms/{vmId}/nics' => ['offset' => false],
        ],
    ]];

    /**
     * Collect a single resource using a synthetic transport and a two-item page size.
     * @param Closure(string,string,array):array $get Fixture page provider.
     * @param array<string,mixed> $options Endpoint pagination settings.
     * @return array<string,mixed> Scope including projected items and coverage.
     */
    private function collectScope(Closure $get, array $options = ['offset' => true], int $maxPages = 8): array
    {
        $inventory = new Inventory($get, 2, $maxPages);
        $plan = ['fixture' => ['bases' => ['https://example.invalid'], 'resources' => ['/items' => $options]]];

        return $inventory->collect($plan)['scopes'][0];
    }

    /** A short page can precede the terminal empty page. */
    public function testOffsetPaginationReadsEveryPage(): void
    {
        $pages = [
            0 => ['items' => [['id' => 'a'], ['id' => 'b']]],
            2 => ['items' => [['id' => 'c']]],
            3 => ['items' => []],
        ];
        $scope = $this->collectScope(static fn ($base, $path, $query) => $pages[$query['offset']]);

        self::assertTrue($scope['complete']);
        self::assertSame(3, $scope['pages']);
        self::assertSame(['a', 'b', 'c'], array_column($scope['items'], 'id'));
    }

    /** Exported inventory only includes allowlisted properties. */
    public function testProjectionOmitsPrivateProperties(): void
    {
        $scope = $this->collectScope(static fn () => ['items' => [
            ['id' => 'a', 'properties' => ['name' => 'VM', 'password' => 'test-only']],
        ]], ['offset' => false]);

        self::assertSame(['name' => 'VM'], $scope['items'][0]['attributes']);
    }

    /** Routing rules retain inventory attributes, linked targets and IP observations. */
    public function testRoutingProjectionKeepsPortRangesTargetsAndPrivateAddresses(): void
    {
        $properties = [
            'lbPrivateIps' => ['192.0.2.3'],
            'targetPortRange' => ['start' => 443, 'end' => 443],
            'httpRules' => [['type' => 'FORWARD', 'targetGroup' => 'group', 'responseMessage' => 'private text']],
        ];
        $scope = $this->collectScope(static fn () => ['items' => [['id' => 'rule', 'properties' => $properties]]],
            ['offset' => false]);
        $rule = $scope['items'][0];

        self::assertSame(['start' => 443, 'end' => 443], $rule['attributes']['targetPortRange']);
        self::assertSame([['type' => 'FORWARD', 'targetGroup' => 'group']], $rule['attributes']['httpRules']);
        self::assertSame('lbPrivateIps', $rule['ipFacts'][0]['field']);
    }

    /** A forbidden request reports its HTTP status and incomplete coverage. */
    public function testDeniedAccessMarksScopeAsIncomplete(): void
    {
        $scope = $this->collectScope(static fn () => throw new InventoryError('forbidden', 403));

        self::assertFalse($scope['complete']);
        self::assertSame('forbidden', $scope['status']);
        self::assertSame(403, $scope['http']);
    }

    /** Rows already observed survive an error on the next page. */
    public function testFailedLaterPageRetainsPartialInventory(): void
    {
        $scope = $this->collectScope(static fn ($base, $path, $query) => $query['offset'] === 0
            ? ['items' => [['id' => 'a'], ['id' => 'b']]]
            : throw new InventoryError('rate_limited', 429));

        self::assertFalse($scope['complete']);
        self::assertSame('rate_limited', $scope['status']);
        self::assertSame(1, $scope['pages']);
        self::assertSame(['a', 'b'], array_column($scope['items'], 'id'));
    }

    /** Repeated IDs indicate that the endpoint ignored the requested offset. */
    public function testRepeatedOffsetPageLeavesCoverageIncomplete(): void
    {
        $scope = $this->collectScope(static fn () => ['items' => [['id' => 'a'], ['id' => 'b']]]);

        self::assertFalse($scope['complete']);
        self::assertSame('missing_or_repeated_id', $scope['status']);
    }

    /** Stopping at the configured page limit leaves the result incomplete. */
    public function testPageLimitLeavesCoverageIncomplete(): void
    {
        $scope = $this->collectScope(static fn () => ['items' => [['id' => 'a']]], maxPages: 1);

        self::assertFalse($scope['complete']);
        self::assertSame('page_limit', $scope['status']);
    }

    /** @return iterable<string,array{array,array,string,bool}> A response, options and expected coverage. */
    public static function collectionResponses(): iterable
    {
        $offset = ['offset' => true];
        $synthetic = ['offset' => true, 'syntheticNext' => true];
        $empty = ['items' => [], 'offset' => 0, 'limit' => 2];
        yield 'empty collection' => [['items' => []], $offset, 'empty', true];
        yield 'unknown envelope' => [[], $offset, 'invalid_items', false];
        yield 'truncated total' => [['items' => [], 'totalCount' => 2], $offset, 'unresolved_continuation', false];
        yield 'unexpected continuation' => [
            ['items' => [], '_links' => ['next' => 'https://example.invalid/next']],
            $offset, 'unresolved_continuation', false,
        ];
        yield 'acknowledged synthetic next page' => [
            $empty + ['_links' => ['next' => '?offset=2']], $synthetic, 'empty', true,
        ];
        yield 'unexpected synthetic offset' => [
            $empty + ['_links' => ['next' => '?offset=20']], $synthetic, 'unresolved_continuation', false,
        ];
        yield 'foreign synthetic link' => [
            $empty + ['_links' => ['next' => 'https://other.invalid/items?offset=2']],
            $synthetic, 'unresolved_continuation', false,
        ];
        yield 'model catalog envelope' => [
            ['data' => [['id' => 'model']]], ['offset' => false, 'items' => 'data'], 'present', true,
        ];
    }

    /** The envelope and continuation determine whether a response covers its scope. */
    #[DataProvider('collectionResponses')]
    public function testCollectionResponseCoverage(array $page, array $options, string $status, bool $complete): void
    {
        $scope = $this->collectScope(static fn () => $page, $options);

        self::assertSame($status, $scope['status']);
        self::assertSame($complete, $scope['complete']);
    }

    /** The collector decodes the next token without changing its opaque bytes. */
    public function testOpaqueCursorReadsTheNextPage(): void
    {
        $scope = $this->collectScope(static function ($base, $path, $query): array {
            if (isset($query['pagination.token'])) {
                self::assertSame('next+part', $query['pagination.token']);
                return ['items' => [['id' => 'b']]];
            }
            return ['items' => [['id' => 'a']], '_links' => ['next' => $base . $path . '?pagination.token=next%2Bpart']];
        }, ['offset' => false, 'cursor' => 'pagination.token']);

        self::assertTrue($scope['complete']);
        self::assertSame(['a', 'b'], array_column($scope['items'], 'id'));
    }

    /** A cursor URL on another origin is rejected before a second request. */
    public function testForeignCursorIsNeverRequested(): void
    {
        $calls = 0;
        $scope = $this->collectScope(static function () use (&$calls): array {
            ++$calls;
            return ['items' => [['id' => 'a']], '_links' => ['next' => 'https://example.net/items?pagination.token=x']];
        }, ['offset' => false, 'cursor' => 'pagination.token']);

        self::assertSame('invalid_cursor_origin', $scope['status']);
        self::assertSame(1, $calls);
    }

    /** Child scopes inherit incomplete coverage from the parent collection. */
    public function testInaccessibleParentLeavesChildScopeIncomplete(): void
    {
        $inventory = new Inventory(static fn ($base, $path) => match ($path) {
            '/dc' => ['items' => [['id' => 'customer/a']], 'totalCount' => 1],
            '/dc/customer%2Fa/vms' => throw new InventoryError('forbidden', 403),
            default => self::fail("Unexpected path: $path"),
        });
        $report = $inventory->collect(self::NESTED_PLAN);

        self::assertFalse($report['complete']);
        self::assertSame('parent_incomplete', $report['scopes'][2]['status']);
    }

    /** A complete empty parent collection has no children to request. */
    public function testEmptyParentsRequireNoChildRequests(): void
    {
        $inventory = new Inventory(static function ($base, $path): array {
            self::assertSame('/dc', $path);
            return ['items' => []];
        });
        $report = $inventory->collect(self::NESTED_PLAN);

        self::assertTrue($report['complete']);
        self::assertSame('no_parents', $report['scopes'][2]['status']);
    }

    /** A missing resource plan cannot produce a complete report. */
    public function testEmptyPlanIsRejected(): void
    {
        $inventory = new Inventory(static fn () => self::fail('Unexpected fixture request.'));
        $this->expectException(InventoryError::class);
        $this->expectExceptionMessageMatches('/^empty_resource_plan$/D');
        $inventory->collect([]);
    }

    /** @return iterable<string,array{list<array{id:string}>,bool}> Reference lists and expected completeness. */
    public static function embeddedReferences(): iterable
    {
        yield 'matching resource IDs' => [[['id' => 'lb']], true];
        yield 'missing resource ID' => [[], false];
    }

    /** Unpaged load balancers can be corroborated by the parent datacenter. */
    #[DataProvider('embeddedReferences')]
    public function testEmbeddedReferencesVerifyUnpagedList(array $references, bool $complete): void
    {
        $scope = $this->collectScope(static fn ($base, $path) => $path === '/items'
            ? ['items' => [['id' => 'lb']]]
            : ['entities' => ['networkloadbalancers' => ['items' => $references]]],
            ['offset' => true, 'verifyEmbedded' => 'networkloadbalancers']);

        self::assertSame($complete, $scope['complete']);
    }

    /** Address observations preserve their family, normalized address, prefix and role. */
    public function testIpFactsNormalizeAddressesAndSkipInvalidValues(): void
    {
        $facts = IpFacts::fromAttributes([
            'ips' => ['10.0.0.5', '2001:0db8::1', 'not-an-address'],
            'sourceSubnet' => '10.0.0.0/24',
            'lans' => [['gatewayIps' => ['192.0.2.1/28', '2001:db8::/129']]],
        ]);

        self::assertSame([
            ['field' => 'ips', 'address' => '10.0.0.5', 'family' => 4, 'prefix' => null],
            ['field' => 'ips', 'address' => '2001:db8::1', 'family' => 6, 'prefix' => null],
            ['field' => 'sourceSubnet', 'address' => '10.0.0.0', 'family' => 4, 'prefix' => 24],
            ['field' => 'lans.gatewayIps', 'address' => '192.0.2.1', 'family' => 4, 'prefix' => 28],
        ], $facts);
    }
}
