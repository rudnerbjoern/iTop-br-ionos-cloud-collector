<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
use RuntimeException;

require_once __DIR__ . '/InventoryReconciler.php';

/** Reconcile NAT resources under each completely observed Cloud, preserving manual records and IP assignments. */
final class NatSync
{
    private const GATEWAY = 'CloudNatGateway';
    private const LAN = 'CloudNatGatewayLan';
    private const PRIVATE_IP = 'CloudNatGatewayLanAddress';
    private const PUBLIC_IP = 'CloudNatGatewayPublicAddress';

    /** @var array<int,array<int,array<int,array<string,int>>>> Prepared TeemIP keys by address family, Cloud, visibility and host. */
    private array $ipIds = [];
    private readonly InventoryReconciler $reconciler;

    /** Parent field, identity within that parent, mutable inventory fields and dependent classes. */
    private const MODEL = [
        self::GATEWAY => ['cloud_id', 'provider_resource_id', ['name', 'org_id'], [self::LAN, self::PUBLIC_IP]],
        self::LAN => ['nat_gateway_id', 'cloud_lan_id', [], [self::PRIVATE_IP]],
        self::PRIVATE_IP => ['attachment_id', 'address', ['prefix_length'], []],
        self::PUBLIC_IP => ['nat_gateway_id', 'address', [], []],
    ];

    /**
     * @param Closure(int,int,string,?int):int|null $resolveIp Find or create the TeemIP host used as the assignment identity.
     */
    public function __construct(
        private readonly InventoryStore $store,
        string $source,
        private readonly ?Closure $resolveIp = null,
    ) {
        $this->reconciler = new InventoryReconciler($store, $source, self::GATEWAY, self::MODEL, $resolveIp !== null);
    }

    /**
     * Prepare TeemIP host identities in the IP collector phase before importing any NAT assignments.
     * @param list<array> $gateways Validated NAT inventory.
     * @param Closure(string):int $resolveCloud Resolve a Cloud within the configured organization and provider.
     */
    public function prepareAddresses(array $gateways, Closure $resolveCloud, int $family): void
    {
        if ($this->resolveIp === null) {
            return;
        }
        if (!in_array($family, [4, 6], true)) {
            throw new RuntimeException('Invalid NAT address collector family.');
        }
        unset($this->ipIds[$family]);
        $prepared = $clouds = [];
        foreach ($gateways as $gateway) {
            $uuid = $gateway['cloud_resource_id'];
            $cloud = $clouds[$uuid] ??= $resolveCloud($uuid);
            $org = $gateway['org_id'];
            foreach ($gateway['public_ips'] as $host) {
                if (self::addressFamily($host) === $family) {
                    $prepared[$cloud][0][$host] ??= ($this->resolveIp)($org, $cloud, $host, null);
                }
            }
            foreach ($gateway['lans'] as $lan) {
                foreach ($lan['addresses'] as $address) {
                    $host = $address['address'];
                    if (self::addressFamily($host) === $family) {
                        $prepared[$cloud][1][$host] ??= ($this->resolveIp)($org, $cloud, $host, $address['prefix_length']);
                    }
                }
            }
        }
        $this->ipIds[$family] = $prepared;
    }

    /**
     * Resolve the complete desired tree before upserts. Defer source-owned removals until all upserts succeed.
     * @param list<array> $gateways Validated NatInventory output.
     * @param list<array> $vdcs VdcSync rows including Clouds with an explicitly empty NAT collection.
     * @param Closure(string):int $resolveCloud Resolve the UUID within the configured provider and organization.
     */
    public function apply(array $gateways, array $vdcs, Closure $resolveCloud): void
    {
        $clouds = $organizations = $trees = [];
        foreach ($vdcs as $vdc) {
            $uuid = $vdc['provider_resource_id'];
            $clouds[$uuid] = $resolveCloud($uuid);
            $organizations[$clouds[$uuid]] = $vdc['org_id'];
            $trees[$clouds[$uuid]] = [];
        }
        foreach ($gateways as $gateway) {
            $org = $gateway['org_id'];
            $cloud = $clouds[$gateway['cloud_resource_id']];
            $attachments = $public = [];
            foreach ($gateway['public_ips'] as $address) {
                $public[$this->addressKey($cloud, $address, false)] = [];
            }
            foreach ($gateway['lans'] as $lan) {
                $rows = $this->store->rows('CloudLan', [
                    'cloud_id' => $cloud, 'provider_resource_id' => $lan['provider_resource_id'],
                ], ['org_id']);
                if (count($rows) !== 1 || (int) $rows[0]['org_id'] !== $org) {
                    throw new RuntimeException('Cannot identify the NAT attachment LAN in its owning Cloud and organization.');
                }
                $addresses = [];
                foreach ($lan['addresses'] as $host) {
                    $key = $this->addressKey($cloud, $host['address'], true);
                    $addresses[$key] = ['fields' => ['prefix_length' => $host['prefix_length']]];
                }
                $attachments[$rows[0]['id']] = ['children' => [self::PRIVATE_IP => $addresses]];
            }
            $trees[$cloud][$gateway['provider_resource_id']] = [
                'fields' => ['org_id' => $org, 'name' => $gateway['name']],
                'children' => [self::LAN => $attachments, self::PUBLIC_IP => $public],
            ];
        }
        $this->reconciler->apply($trees, $organizations);
    }

    /** Identify the address family of a canonical host in the validated NAT inventory. */
    private static function addressFamily(string $host): int
    {
        return str_contains($host, ':') ? 6 : 4;
    }

    /** Return the natural host key for the installed model, shared by assignments referring to the same IP. */
    private function addressKey(int $cloud, string $address, bool $private): int|string
    {
        if ($this->resolveIp === null) {
            return $address;
        }
        return $this->ipIds[self::addressFamily($address)][$cloud][(int) $private][$address]
            ?? throw new RuntimeException('Missing TeemIP host in the NAT import. Run the IP address collector first.');
    }
}
