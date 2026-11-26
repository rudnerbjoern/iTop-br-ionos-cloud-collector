<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\NatStore;
use BR\IonosCloud\Collector\NatSync;
use BR\IonosCloud\Collector\Tests\Support\RestResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../collectors/src/NatSync.php';
require_once __DIR__ . '/../Support/RestResponse.php';

/** Verify scoped NAT reconciliation against an in-memory REST target, including retries and manual edits. */
final class NatSyncTest extends TestCase
{
    private const GATEWAY = 'CloudNatGateway';
    private const LAN = 'CloudNatGatewayLan';
    private const PRIVATE_IP = 'CloudNatGatewayLanAddress';
    private const PUBLIC_IP = 'CloudNatGatewayPublicAddress';
    private const SOURCE = 'IONOS NAT - org 4 - provider 9';
    private const VDCS = [['provider_resource_id' => 'vdc-a', 'org_id' => 4]];
    private const GATEWAYS = [[
        'org_id' => 4, 'cloud_resource_id' => 'vdc-a', 'provider_resource_id' => 'gw-a', 'name' => 'Office',
        'public_ips' => ['198.51.100.50'], 'lans' => [['provider_resource_id' => '1',
            'addresses' => [['address' => '192.0.2.2', 'prefix_length' => 27]]]],
    ]];

    /** @var array<string,array<int,array>> In-memory iTop fields keyed by native class and ID. */
    private array $records = ['CloudLan' => [5 => ['cloud_id' => 15, 'provider_resource_id' => '1', 'org_id' => 4]]];
    private int $next = 20;
    private int $writes = 0;
    private ?string $failCreate = null;
    private bool $bridge = true;
    private int $preparedHosts = 0;

    /** Verify matching, repeat imports, prefix and name updates, and preservation of a manual IPAM override. */
    public function testImportIsIdempotentAndPreservesManualAssignments(): void
    {
        $this->runInventory();
        self::assertSame(4, $this->writes);
        $private = array_key_first($this->records[self::PRIVATE_IP]);
        $public = array_key_first($this->records[self::PUBLIC_IP]);
        self::assertSame(72, $this->records[self::PRIVATE_IP][$private]['ionos_synced_ip_id']);
        self::assertSame(73, $this->records[self::PUBLIC_IP][$public]['ipaddress_id']);
        $this->runInventory();
        self::assertSame(4, $this->writes);

        self::assertArrayNotHasKey('address', $this->records[self::PRIVATE_IP][$private]);
        self::assertArrayNotHasKey('address', $this->records[self::PUBLIC_IP][$public]);
        $this->records[self::PUBLIC_IP][$public]['ipaddress_id'] = 91;
        $changed = self::GATEWAYS;
        $changed[0]['name'] = 'New name';
        $changed[0]['lans'][0]['addresses'][0]['prefix_length'] = 26;
        $this->runInventory($changed);
        self::assertSame('New name', reset($this->records[self::GATEWAY])['name']);
        self::assertSame(26, $this->records[self::PRIVATE_IP][$private]['prefix_length']);
        self::assertSame(72, $this->records[self::PRIVATE_IP][$private]['ipaddress_id']);
        self::assertSame(91, $this->records[self::PUBLIC_IP][$public]['ipaddress_id']);
        self::assertEqualsCanonicalizing([73, 91], array_column($this->records[self::PUBLIC_IP], 'ipaddress_id'));
        $writes = $this->writes;
        $this->runInventory($changed);
        self::assertSame($writes, $this->writes);
        $this->runInventory([]);
        self::assertCount(1, $this->records[self::GATEWAY]);
        self::assertSame([], $this->records[self::LAN]);
        self::assertSame([$public], array_keys($this->records[self::PUBLIC_IP]));
        self::assertSame([], $this->records[self::PRIVATE_IP]);
    }

    /** An unobserved VDC is untouched; an explicitly observed empty one releases owned children first. */
    public function testWithdrawalsAreScopedAndPreserveOtherSources(): void
    {
        $this->runInventory();
        $this->records[self::GATEWAY][19] = [
            'cloud_id' => 52, 'provider_resource_id' => 'gw-b', 'org_id' => 4,
            'name' => 'Outside the observed VDC', 'ionos_source_key' => self::SOURCE,
        ];
        $this->runInventory([], []);
        self::assertCount(2, $this->records[self::GATEWAY]);
        $gateway = array_key_first($this->records[self::GATEWAY]);
        $this->records[self::PUBLIC_IP][99] = ['nat_gateway_id' => $gateway, 'ipaddress_id' => 74,
            'ionos_source_key' => 'another collector', 'ionos_synced_ip_id' => null];
        $changed = self::GATEWAYS;
        $changed[0]['public_ips'][] = '198.51.100.60';
        $this->runInventory($changed);
        self::assertSame('another collector', $this->records[self::PUBLIC_IP][99]['ionos_source_key']);
        $this->runInventory([]);
        self::assertCount(2, $this->records[self::GATEWAY]);
        self::assertSame([99], array_keys($this->records[self::PUBLIC_IP]));
        self::assertSame([], $this->records[self::PRIVATE_IP]);
        self::assertSame([], $this->records[self::LAN]);
        unset($this->records[self::PUBLIC_IP][99]);
        $this->runInventory([]);
        self::assertSame([19], array_keys($this->records[self::GATEWAY]));
    }

    /** Reuse manual parents without adopting them and retry a partial import without duplicating successful records. */
    public function testRetryWithoutBridgeAndManualParent(): void
    {
        $this->bridge = false;
        $this->records[self::GATEWAY][11] = ['cloud_id' => 15, 'provider_resource_id' => 'gw-a',
            'org_id' => 4, 'name' => 'Manual name', 'ionos_source_key' => ''];
        $this->failCreate = self::PUBLIC_IP;
        try {
            $this->runInventory();
            self::fail('Expected a failed public address creation.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(self::PUBLIC_IP, $e->getMessage());
        }
        self::assertSame(2, $this->writes);
        $this->failCreate = null;
        $this->runInventory();
        $this->runInventory();
        self::assertSame(3, $this->writes);
        self::assertSame('Manual name', $this->records[self::GATEWAY][11]['name']);
        self::assertArrayNotHasKey('ipaddress_id', reset($this->records[self::PRIVATE_IP]));
        self::assertSame('198.51.100.50', reset($this->records[self::PUBLIC_IP])['address']);
        $this->runInventory([]);
        self::assertSame([11], array_keys($this->records[self::GATEWAY]));
    }

    /** A gateway moved to another organization is protected even when absent from the provider snapshot. */
    public function testForeignGatewayAndItsChildrenArePreservedOnWithdrawal(): void
    {
        $this->runInventory();
        $id = array_key_first($this->records[self::GATEWAY]);
        $this->records[self::GATEWAY][$id]['org_id'] = 8;
        $this->runInventory([]);
        self::assertSame(4, $this->writes);
        self::assertCount(1, $this->records[self::PRIVATE_IP]);
    }

    /** Missing and cross-tenant LANs must fail before recording even the gateway parent. */
    public function testLanScopeMustResolveBeforeWriting(): void
    {
        $this->records['CloudLan'][5]['org_id'] = 9;
        try {
            $this->runInventory();
            self::fail('Expected rejection of the foreign LAN.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('owning Cloud', $e->getMessage());
        }
        self::assertSame(0, $this->writes);
    }

    /** Each distinct host is prepared once even when two gateways use it in the same VDC. */
    public function testSharedHostsArePreparedBeforeAssignments(): void
    {
        $gateways = self::GATEWAYS;
        $second = $gateways[0];
        $second['provider_resource_id'] = 'gw-b';
        $gateways[] = $second;

        $this->runInventory($gateways);
        self::assertSame(2, $this->preparedHosts);
        self::assertSame([73, 73], array_column($this->records[self::PUBLIC_IP], 'ipaddress_id'));
        $this->runInventory([$second]);
        self::assertCount(1, $this->records[self::PUBLIC_IP]);
        self::assertSame(73, reset($this->records[self::PUBLIC_IP])['ipaddress_id']);
    }

    /** Separate IPv4 and IPv6 phases each handle only their own hosts and pass both sets of IDs to NAT. */
    public function testIndependentIpFamiliesPreservePreparedHosts(): void
    {
        $gateways = self::GATEWAYS;
        $gateways[0]['public_ips'][] = '2001:db8::1';
        $gateways[0]['lans'][0]['addresses'][] = ['address' => 'fd00::2', 'prefix_length' => 64];
        $store = new NatStore($this->get(...), $this->create(...), $this->update(...), $this->delete(...));
        $hosts = [];
        $resolve = static function (int $org, int $cloud, string $address, ?int $prefix) use (&$hosts): int {
            $hosts[] = $address;
            return count($hosts);
        };
        $cloud = static fn () => 15;
        $nat = new NatSync($store, self::SOURCE, $resolve);
        $nat->prepareAddresses($gateways, $cloud, 4);
        self::assertSame(['198.51.100.50', '192.0.2.2'], $hosts);
        $nat->prepareAddresses($gateways, $cloud, 6);
        self::assertSame(['198.51.100.50', '192.0.2.2', '2001:db8::1', 'fd00::2'], $hosts);
        $nat->apply($gateways, self::VDCS, $cloud);
        self::assertSame([1, 3], array_column($this->records[self::PUBLIC_IP], 'ipaddress_id'));
        self::assertSame([2, 4], array_column($this->records[self::PRIVATE_IP], 'ipaddress_id'));
        self::assertCount(4, $hosts);
    }

    /** A skipped IP phase must never result in a literal, unlinked row in the bridge model. */
    public function testMissingIpPhaseStopsBeforeAnyNatWrite(): void
    {
        try {
            $this->runInventory(prepare: false);
            self::fail('Expected a missing prepared host.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('IP address collector first', $error->getMessage());
        }
        self::assertSame(0, $this->preparedHosts);
        self::assertSame(0, $this->writes);
    }

    /** An incomplete target list must never be treated as a complete empty inventory. */
    public function testRejectsTruncatedTargetReply(): void
    {
        $store = new NatStore(static fn () => ['code' => 0, 'message' => 'Found: 2', 'objects' => []],
            $this->create(...), $this->update(...), $this->delete(...));
        $this->expectException(RuntimeException::class);
        $store->rows(self::GATEWAY, ['cloud_id' => 15], ['ionos_source_key']);
    }

    /** Apply one observation to the same target across multiple runs. */
    private function runInventory(array $gateways = self::GATEWAYS, array $vdcs = self::VDCS, bool $prepare = true): void
    {
        $store = new NatStore($this->get(...), $this->create(...), $this->update(...), $this->delete(...));
        $resolve = function (int $org, int $cloud, string $address, ?int $prefix): int {
            ++$this->preparedHosts;
            return match ($address) {
                '192.0.2.2' => 72,
                '198.51.100.50' => 73,
                '198.51.100.60' => 74,
            };
        };
        $nat = new NatSync($store, self::SOURCE, $this->bridge ? $resolve : null);
        $cloud = static fn () => 15;
        if ($prepare) {
            $nat->prepareAddresses($gateways, $cloud, 4);
            $nat->prepareAddresses($gateways, $cloud, 6);
        }
        $nat->apply($gateways, $vdcs, $cloud);
    }

    /** Expose only requested fields, rejecting accidental reads of the optional IPAM field. */
    private function get(string $class, array $key, string $fields): array
    {
        $objects = [];
        foreach ($this->records[$class] ?? [] as $id => $row) {
            if ($this->recordMatches($id, $row, $key)) {
                $values = [];
                foreach (explode(',', $fields) as $field) {
                    self::assertNotSame($this->bridge ? 'address' : 'ipaddress_id', $field);
                    $values[$field] = $row[$field] ?? null;
                }
                $objects += RestResponse::one($class, $id, $values)['objects'];
            }
        }
        return ['code' => 0, 'objects' => $objects ?: null, 'message' => 'Found: ' . count($objects)];
    }

    /** Mimic native target allocation and an injectable write failure. */
    private function create(string $class, array $fields): array
    {
        if ($this->failCreate === $class) {
            return ['code' => 1];
        }
        $id = $this->next++;
        $this->writes++;
        $this->records[$class][$id] = $fields;
        return RestResponse::one($class, $id);
    }

    /** Enforce the observed identity, source and current target when updating. */
    private function update(string $class, array $key, array $changes): array
    {
        $id = $key['id'];
        self::assertTrue($this->recordMatches($id, $this->records[$class][$id], $key));
        $this->records[$class][$id] = $changes + $this->records[$class][$id];
        $this->writes++;
        return RestResponse::one($class, $id);
    }

    /** Enforce the same scope when deleting. */
    private function delete(string $class, array $key): array
    {
        $id = $key['id'];
        self::assertTrue($this->recordMatches($id, $this->records[$class][$id], $key));
        unset($this->records[$class][$id]);
        $this->writes++;
        return RestResponse::one($class, $id);
    }

    /** Compare a REST condition to native fields using the same string form as iTop. */
    private function recordMatches(int $id, array $row, array $conditions): bool
    {
        $row['id'] = $id;
        foreach ($conditions as $field => $value) {
            if ((string) ($row[$field] ?? '') !== (string) $value) {
                return false;
            }
        }
        return true;
    }
}
