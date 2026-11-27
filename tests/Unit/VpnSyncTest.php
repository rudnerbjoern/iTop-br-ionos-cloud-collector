<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\InventoryStore;
use BR\IonosCloud\Collector\VpnSync;
use BR\IonosCloud\Collector\Tests\Support\RestResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../collectors/src/VpnSync.php';
require_once __DIR__.'/../Support/RestResponse.php';

final class VpnSyncTest extends TestCase
{
    private const GATEWAY = 'CloudVpnGateway';
    private const ATTACHMENT = 'CloudVpnGatewayLan';
    private const IPSEC = '/ipsecgateways';
    private const WIREGUARD = '/wireguardgateways';
    private const VDC = 'aaaa0000-0000-4000-8000-123456789abc';
    private const SOURCE = 'org 4 - provider 9 - contract 510';
    private const OTHER = 'org 4 - provider 9 - contract 511';

    private int $next = 20;
    private array $records = ['CloudLan' => [6 => ['cloud_id' => 15, 'provider_resource_id' => '2', 'org_id' => 4]]];
    private array $errors = [];

    public function testScopeFailuresAndWithdrawalDoNotTouchAnIndependentScope(): void
    {
        $ipsec = self::scope(self::IPSEC, 'IPsec');
        $wireguard = self::scope(self::WIREGUARD, 'WireGuard');
        $this->runInventory([$ipsec, $wireguard]);
        $this->runInventory([$ipsec], self::OTHER);
        $initial = $this->records[self::GATEWAY];
        [$first, $second, $foreign] = array_keys($initial);
        self::assertCount(3, $initial);
        self::assertCount(3, array_unique(array_column($initial, 'source_namespace')));
        self::assertSame(['same-id', 'same-id', 'same-id'], array_column($initial, 'provider_resource_id'));

        $bad = self::scope(self::IPSEC, 'Would overwrite', '999');
        $this->expectScopeFailure([$bad, self::scope(self::WIREGUARD, 'New WireGuard')], 'unresolved or duplicate LAN');
        self::assertSame($initial[$first], $this->records[self::GATEWAY][$first]);
        self::assertSame('New WireGuard', $this->records[self::GATEWAY][$second]['name']);
        self::assertSame($initial[$foreign], $this->records[self::GATEWAY][$foreign]);

        $incomplete = self::scope(self::IPSEC, null);
        $incomplete['complete'] = false;
        $incomplete['status'] = 'forbidden';
        $this->expectScopeFailure([$incomplete], 'Incomplete inventory');
        self::assertSame($initial[$first], $this->records[self::GATEWAY][$first]);

        $this->runInventory([self::scope(self::IPSEC, null)]);
        self::assertSame([$second, $foreign], array_keys($this->records[self::GATEWAY]));
        self::assertSame([$second, $foreign], array_column($this->records[self::ATTACHMENT], 'vpn_gateway_id'));
    }

    public function testNativeLanMustResolveBeforeAnyWriteInScope(): void
    {
        $this->runInventory([self::scope(self::IPSEC, 'IPsec')]);
        $snapshot = $this->records;
        $second = self::scope(self::IPSEC, 'New gateway');
        $second['items'][0]['id'] = 'another-id';
        $second['items'][0]['attributes']['connections'][0]['lanId'] = '3';
        $changed = self::scope(self::IPSEC, 'Renamed');
        $changed['items'][] = $second['items'][0];
        $this->expectScopeFailure([$changed], 'unresolved iTop LAN');
        self::assertSame($snapshot, $this->records);
    }

    private static function scope(string $path, ?string $name, string $lan = '2'): array
    {
        return ['product' => 'vpn', 'base' => 'https://vpn.de-txl.ionos.com', 'path' => $path,
            'complete' => true, 'pages' => 1, 'status' => $name === null ? 'empty' : 'present',
            'items' => $name === null ? [] : [['id' => 'same-id', 'attributes' => [
                'name' => $name, 'gatewayIP' => '203.0.113.11',
                'connections' => [['datacenterId' => self::VDC, 'lanId' => $lan, 'ipv4CIDR' => '10.20.0.8/24']],
            ]]]];
    }

    private function expectScopeFailure(array $scopes, string $reason): void
    {
        try {
            $this->runInventory($scopes);
            self::fail('Expected the run to report a failed VPN scope.');
        } catch (RuntimeException $e) {
            self::assertSame('One or more IONOS VPN inventory scopes failed.', $e->getMessage());
        }
        self::assertStringContainsString('de-txl/ipsec', end($this->errors));
        self::assertStringContainsString($reason, end($this->errors));
    }

    private function runInventory(array $scopes, string $source = self::SOURCE): void
    {
        $get = function (string $class, array $key, string $fields): array {
            $objects = [];
            foreach ($this->records[$class] ?? [] as $id => $row) {
                foreach ($key as $field => $value) {
                    if ((string) ($row[$field] ?? '') !== (string) $value) {
                        continue 2;
                    }
                }
                $objects += RestResponse::one($class, $id, array_intersect_key($row, array_flip(explode(',', $fields))))['objects'];
            }
            return ['code' => 0, 'objects' => $objects, 'message' => 'Found: '.count($objects)];
        };
        $create = function (string $class, array $fields): array {
            $id = $this->next++;
            $this->records[$class][$id] = $fields;
            return RestResponse::one($class, $id);
        };
        $update = function (string $class, array $key, array $changes): array {
            $id = $key['id'];
            $this->records[$class][$id] = $changes + $this->records[$class][$id];
            return RestResponse::one($class, $id);
        };
        $delete = function (string $class, array $key): array {
            $id = $key['id'];
            unset($this->records[$class][$id]);
            return RestResponse::one($class, $id);
        };
        $sync = new VpnSync(new InventoryStore($get, $create, $update, $delete), $source, 4, null);
        $sync->apply(['scopes' => $scopes], [['provider_resource_id' => self::VDC]],
            [['cloud_id' => self::VDC, 'provider_resource_id' => '2'],
                ['cloud_id' => self::VDC, 'provider_resource_id' => '3']],
            static fn () => 15, function (string $message): void { $this->errors[] = $message; });
    }
}
