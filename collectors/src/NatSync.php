<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
use RuntimeException;

require_once __DIR__ . '/NatStore.php';

/** Reconcile NAT resources under each completely observed Cloud, preserving manual records and IP assignments. */
final class NatSync
{
    private const GATEWAY = 'CloudNatGateway';
    private const LAN = 'CloudNatGatewayLan';
    private const PRIVATE_IP = 'CloudNatGatewayLanAddress';
    private const PUBLIC_IP = 'CloudNatGatewayPublicAddress';

    /** @var array<int,array<int,array<int,array<string,int>>>> Prepared TeemIP keys by address family, Cloud, visibility and host. */
    private array $ipIds = [];

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
        private readonly NatStore $store,
        private readonly string $source,
        private readonly ?Closure $resolveIp = null,
    ) {}

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
        $removals = [];
        foreach ($trees as $cloud => $tree) {
            $this->collection(self::GATEWAY, $cloud, $tree, $removals, $organizations[$cloud]);
        }
        foreach ($removals as [$class, $key]) {
            if (!$this->hasChildren($class, $key['id'])) {
                $this->store->remove($class, $key);
            }
        }
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

    /** Match one native parent collection by its local identity and traverse all observed and retained children. */
    private function collection(string $class, int $parent, array $wanted, array &$removals, ?int $org = null): void
    {
        [$parentField, $identity, $fields, $children] = self::MODEL[$class];
        $ipam = $identity === 'address' && $this->resolveIp !== null;
        if ($ipam) {
            $identity = 'ipaddress_id';
            $fields[] = 'ionos_synced_ip_id';
        }
        $selected = [$identity, 'ionos_source_key', ...$fields];
        $existing = [];
        foreach ($this->store->rows($class, [$parentField => $parent], $selected) as $row) {
            $value = $row[$identity];
            if ($identity === 'address' && is_string($value) && filter_var($value, FILTER_VALIDATE_IP)) {
                $value = inet_ntop(inet_pton($value));
            } elseif ($class === self::GATEWAY) {
                $value = strtolower((string) $value);
            }
            if (isset($existing[$value])) {
                throw new RuntimeException("Ambiguous $class identity in the target Cloud.");
            }
            $existing[$value] = $row;
        }
        foreach ($wanted + $existing as $value => $_node) {
            $node = $wanted[$value] ?? null;
            $row = $existing[$value] ?? null;
            $fields = $node['fields'] ?? [];
            if ($row === null) {
                if ($ipam) {
                    $fields['ionos_synced_ip_id'] = $value;
                }
                $id = $this->store->insert($class, [$parentField => $parent, $identity => $value,
                    'ionos_source_key' => $this->source] + $fields);
            } else {
                $id = $row['id'];
                if ($class === self::GATEWAY && (int) $row['org_id'] !== $org) {
                    if ($node !== null) {
                        throw new RuntimeException('The NAT gateway belongs to a different organization.');
                    }
                    continue;
                }
                $owned = $row['ionos_source_key'] === $this->source;
                $key = ['id' => $id, $parentField => $parent, $identity => $row[$identity],
                    'ionos_source_key' => $this->source];
                if ($class === self::GATEWAY) {
                    $key['org_id'] = $org;
                }
                if ($ipam && (int) $row['ionos_synced_ip_id'] !== (int) $value) {
                    // Changing the host reference changes the identity of this assignment.
                    $owned = false;
                }
                if ($owned && $node !== null) {
                    $changes = array_filter($fields, static fn ($v, $k) => (string) $row[$k] !== (string) $v,
                        ARRAY_FILTER_USE_BOTH);
                    if ($changes !== []) {
                        $this->store->change($class, $key, $changes);
                    }
                }
            }
            foreach ($children as $child) {
                $this->collection($child, $id, $node['children'][$child] ?? [], $removals);
            }
            if ($node === null && $owned) {
                $removals[] = [$class, $key];
            }
        }
    }

    /** Recheck parent deletion after processing children; manual, foreign and manually linked records protect it. */
    private function hasChildren(string $class, int $id): bool
    {
        foreach (self::MODEL[$class][3] as $child) {
            if ($this->store->rows($child, [self::MODEL[$child][0] => $id], []) !== []) {
                return true;
            }
        }
        return false;
    }
}
