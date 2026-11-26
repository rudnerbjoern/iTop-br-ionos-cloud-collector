<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\Inventory;
use BR\IonosCloud\Collector\NatInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../collectors/src/NatInventory.php';
require_once __DIR__ . '/../../collectors/src/Inventory.php';
require_once __DIR__ . '/../../collectors/src/IonosApi.php';

/** Exercise the real inventory projection and NAT mapping with synthetic per-VDC responses. */
final class NatInventoryTest extends TestCase
{
    private const CLOUD = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
    private const GATEWAY = 'cccccccc-cccc-4ccc-accc-cccccccccccc';
    private const PROPERTIES = ['name' => 'Edge', 'publicIps' => ['203.0.113.7'],
        'lans' => [['id' => 7, 'gatewayIps' => ['10.20.0.7/24', '2001:0DB8:0:0:0:0:0:7/64']]]];

    /**
     * Supply a private LAN 7 in each VDC and read both parent and child scopes using the real collection code.
     * @param array<string,list<array>> $gateways Raw gateway items keyed by VDC UUID.
     * @return array{array,list<array>} Inventory and its matching VDC rows.
     */
    private static function snapshot(array $gateways): array
    {
        $dcIds = array_keys($gateways);
        $get = static function (string $base, string $path) use ($gateways, $dcIds): array {
            if ($path === '/datacenters') {
                return ['items' => array_map(static fn ($id) => ['id' => $id], $dcIds)];
            }
            [, , $id, $child] = explode('/', $path);
            $lans = [['id' => 7, 'properties' => ['public' => false]], ['id' => 8, 'properties' => ['public' => true]]];
            if ($id === self::OTHER) {
                $lans[] = ['id' => 9, 'properties' => ['public' => false]];
            }
            return ['items' => $child === 'lans' ? $lans : $gateways[$id]];
        };
        $paths = ['/datacenters', '/datacenters/{datacenterId}/lans', '/datacenters/{datacenterId}/natgateways'];
        $report = (new Inventory($get))->collect(['compute' => ['bases' => ['https://example.invalid'],
            'resources' => array_fill_keys($paths, ['offset' => false])]]);
        $parents = array_map(static fn ($id) => ['provider_resource_id' => $id, 'org_id' => 5], $dcIds);
        return [$report, $parents];
    }

    /** Check the gateway, private LAN, host bits, address normalization and unrelated empty VDC together. */
    public function testCanonicalGatewayAndScopedPrivateAddresses(): void
    {
        $snapshot = self::snapshot([self::CLOUD => [['id' => self::GATEWAY, 'properties' => self::PROPERTIES]], self::OTHER => []]);
        self::assertSame([[
            'cloud_resource_id' => self::CLOUD, 'org_id' => 5, 'provider_resource_id' => self::GATEWAY,
            'name' => 'Edge', 'public_ips' => ['203.0.113.7'], 'lans' => [['provider_resource_id' => '7',
                'addresses' => [['address' => '10.20.0.7', 'prefix_length' => 24], ['address' => '2001:db8::7', 'prefix_length' => 64]]]],
        ]], NatInventory::gateways(...$snapshot));
    }

    /** Explicit empty lists survive the inventory projection and are authoritative for their own scope. */
    public function testEmptyCollections(): void
    {
        self::assertSame([], NatInventory::gateways(...self::snapshot([])));
        self::assertSame([], NatInventory::gateways(...self::snapshot([self::CLOUD => []])));
        $gateway = ['id' => self::GATEWAY, 'properties' => ['name' => 'Empty', 'lans' => [], 'publicIps' => []]];
        $result = NatInventory::gateways(...self::snapshot([self::CLOUD => [$gateway]]));
        self::assertSame([], $result[0]['lans']);
        self::assertSame([], $result[0]['public_ips']);
        $gateway['properties']['lans'] = [['id' => '7', 'gatewayIps' => []]];
        $result = NatInventory::gateways(...self::snapshot([self::CLOUD => [$gateway]]));
        self::assertSame([['provider_resource_id' => '7', 'addresses' => []]], $result[0]['lans']);
    }

    /** @return iterable<string,array{array}> Missing, malformed, duplicate and out-of-scope observations. */
    public static function invalidProperties(): iterable
    {
        $properties = self::PROPERTIES;
        foreach (['lans', 'publicIps', 'name'] as $field) {
            $missing = $properties;
            unset($missing[$field]);
            yield "missing $field" => [$missing];
        }
        foreach ([
            'missing private host list' => [['id' => 7]],
            'malformed entry alongside valid LAN' => [$properties['lans'][0], null],
            'malformed private address list' => [['id' => 7, 'gatewayIps' => null]],
            'private host missing prefix' => [['id' => 7, 'gatewayIps' => ['10.20.0.7']]],
            'IPv4 prefix beyond 32' => [['id' => 7, 'gatewayIps' => ['10.20.0.7/33']]],
            'IPv6 prefix beyond 128' => [['id' => 7, 'gatewayIps' => ['2001:db8::7/129']]],
            'same host with two prefixes' => [['id' => 7, 'gatewayIps' => ['10.20.0.7/24', '10.20.0.7/30']]],
            'public LAN' => [['id' => 8, 'gatewayIps' => []]],
            'LAN exists only in another VDC' => [['id' => 9, 'gatewayIps' => []]],
            'duplicate attachment' => [$properties['lans'][0], $properties['lans'][0]],
        ] as $name => $lans) {
            yield $name => [array_replace($properties, ['lans' => $lans])];
        }
        yield 'public address with mask' => [array_replace($properties, ['publicIps' => ['203.0.113.7/32']])];
        yield 'duplicate canonical public IPv6' => [array_replace($properties, ['publicIps' => ['2001:db8::1', '2001:0db8::1']])];
    }

    /** @param array<string,mixed> $properties Raw properties of an invalid gateway. */
    #[DataProvider('invalidProperties')]
    public function testRejectsInvalidGateway(array $properties): void
    {
        $this->expectException(RuntimeException::class);
        NatInventory::gateways(...self::snapshot([self::CLOUD => [['id' => self::GATEWAY, 'properties' => $properties]], self::OTHER => []]));
    }

    /** A top-level field cannot stand in for a missing provider property and release public addresses. */
    public function testOnlyProviderPropertiesAuthorizeAnEmptyList(): void
    {
        $gateway = ['id' => self::GATEWAY, 'publicIps' => [], 'properties' => ['name' => 'Edge', 'lans' => []]];
        $this->expectException(RuntimeException::class);
        NatInventory::gateways(...self::snapshot([self::CLOUD => [$gateway]]));
    }

    /** A missing child collection cannot retire the unseen VDC's NAT objects. */
    public function testMissingNatScopeForSecondVdc(): void
    {
        [$report, $parents] = self::snapshot([self::CLOUD => [], self::OTHER => []]);
        array_pop($report['scopes']);
        $this->expectException(RuntimeException::class);
        NatInventory::gateways($report, $parents);
    }

    /** Gateway UUIDs must also be unique across alternate spellings within their VDC. */
    public function testRepeatedGateway(): void
    {
        $gateway = ['id' => self::GATEWAY, 'properties' => self::PROPERTIES];
        $this->expectException(RuntimeException::class);
        NatInventory::gateways(...self::snapshot([self::CLOUD => [$gateway, array_replace($gateway, ['id' => strtoupper(self::GATEWAY)])]]));
    }
}
