<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
use RuntimeException;

require_once __DIR__ . '/InventoryReconciler.php';
require_once __DIR__ . '/VpnInventory.php';

/** Reconcile each observed regional VPN list independently after Cloud and LAN synchronization. */
final class VpnSync
{
    private const GATEWAY = 'CloudVpnGateway';
    private const LAN = 'CloudVpnGatewayLan';
    private const LAN_ADDRESS = 'CloudVpnGatewayLanAddress';
    private const INTERFACE_ADDRESS = 'CloudVpnGatewayInterfaceAddress';

    public function __construct(
        private readonly InventoryStore $store,
        private readonly string $source,
        private readonly int $organization,
        private readonly ?NatIpResolver $ipam,
    ) {}

    /** Report every affected scope, and signal the failed run after applying independent complete lists. */
    public function apply(array $report, array $vdcRows, array $lanRows, Closure $resolveCloud, Closure $reportError): void
    {
        $vdcs = $lans = [];
        foreach ($vdcRows as $row) {
            $vdcs[strtolower($row['provider_resource_id'])] = true;
        }
        foreach ($lanRows as $row) {
            $lans[strtolower($row['cloud_id'])][$row['provider_resource_id']] = true;
        }
        $failed = false;
        foreach ($report['scopes'] as $scope) {
            if ($scope['product'] !== 'vpn') {
                continue;
            }
            $region = explode('.', parse_url($scope['base'], PHP_URL_HOST))[1];
            $protocol = VpnInventory::PROTOCOLS[$scope['path']];
            try {
                if (!$scope['complete']) {
                    throw new RuntimeException("Incomplete inventory ({$scope['status']}, {$scope['pages']} pages read).");
                }
                $gateways = VpnInventory::gateways($scope, $vdcs, $lans);
                $this->scope($gateways, $resolveCloud, $region, $protocol);
            } catch (RuntimeException $e) {
                $reportError("IONOS VPN $region/$protocol: " . $e->getMessage());
                $failed = true;
            }
        }
        if ($failed) {
            throw new RuntimeException('One or more IONOS VPN inventory scopes failed.');
        }
    }

    /** Resolve every LAN and IPAM context before changing VPN records within this scope. */
    private function scope(array $gateways, Closure $resolveCloud, string $region, string $protocol): void
    {
        $namespace = "IONOS VPN - {$this->source} - $region/$protocol";
        $org = $this->organization;
        $scope = ['source_namespace' => $namespace, 'org_id' => $org];
        $trees = $orgs = $placements = $known = $current = [];
        foreach ($this->store->rows(self::GATEWAY, $scope, ['provider_resource_id', 'cloud_id', 'ionos_source_key']) as $row) {
            $id = strtolower($row['provider_resource_id']);
            $cloud = (int) $row['cloud_id'];
            if (isset($current[$id]) || $cloud < 1) {
                throw new RuntimeException('Ambiguous or invalid VPN gateway identity in iTop.');
            }
            $current[$id] = $cloud;
            if ($row['ionos_source_key'] === $namespace) {
                $trees[$cloud] = [];
                $orgs[$cloud] = $org;
            }
        }

        foreach ($gateways as $gateway) {
            $id = $gateway['id'];
            $cloud = $known[$gateway['cloud']] ??= $resolveCloud($gateway['cloud']);
            $orgs[$cloud] = $org;
            if (isset($current[$id]) && $current[$id] !== $cloud) {
                throw new RuntimeException("Gateway $id changed its owning VDC.");
            }
            foreach ($gateway['attachments'] as $lan => $_hosts) {
                $rows = $this->store->rows('CloudLan', ['cloud_id' => $cloud, 'provider_resource_id' => $lan], ['org_id']);
                if (count($rows) !== 1 || (int) $rows[0]['org_id'] !== $org) {
                    throw new RuntimeException("Gateway $id has an unresolved iTop LAN in its owning Cloud.");
                }
                $placements[$cloud][$lan] = $rows[0]['id'];
            }
            if ($this->ipam !== null) {
                try {
                    $this->ipam->validate($org, $cloud, $gateway['endpoint'], null, false);
                    foreach ([$gateway['interface'], ...array_values($gateway['attachments'])] as $hosts) {
                        foreach ($hosts as $ip => $prefix) {
                            $this->ipam->validate($org, $cloud, $ip, $prefix, true);
                        }
                    }
                } catch (RuntimeException $e) {
                    throw new RuntimeException("Gateway $id: " . $e->getMessage());
                }
            }
        }

        $endpointField = $this->ipam === null ? 'public_endpoint' : 'public_endpoint_id';
        foreach ($gateways as $gateway) {
            $cloud = $known[$gateway['cloud']];
            $attachments = [];
            foreach ($gateway['attachments'] as $lan => $hosts) {
                $attachments[$placements[$cloud][$lan]] = [
                    'children' => [self::LAN_ADDRESS => $this->addresses($org, $cloud, $hosts)],
                ];
            }
            $endpoint = $gateway['endpoint'];
            $fields = $gateway['fields'] + ['org_id' => $org, 'ionos_region' => $region];
            if ($this->ipam !== null) {
                $endpoint = $this->ipam->resolve($org, $cloud, $endpoint, null, false);
                $fields['ionos_synced_ip_id'] = $endpoint;
            }
            $trees[$cloud][$gateway['id']] = [
                'fields' => $fields + [$endpointField => $endpoint],
                'children' => [self::LAN => $attachments,
                    self::INTERFACE_ADDRESS => $this->addresses($org, $cloud, $gateway['interface'])],
            ];
        }
        $fields = ['name', 'description', 'org_id', 'protocol', 'ike_version', 'listen_port',
            'ionos_tier', 'ionos_status', 'ionos_region', $endpointField];
        if ($this->ipam !== null) {
            $fields[] = 'ionos_synced_ip_id';
        }
        $model = [
            self::GATEWAY => ['cloud_id', 'provider_resource_id', $fields, [self::LAN, self::INTERFACE_ADDRESS]],
            self::LAN => ['vpn_gateway_id', 'cloud_lan_id', [], [self::LAN_ADDRESS]],
            self::LAN_ADDRESS => ['attachment_id', 'address', ['prefix_length'], []],
            self::INTERFACE_ADDRESS => ['vpn_gateway_id', 'address', ['prefix_length'], []],
        ];
        $sync = new InventoryReconciler($this->store, $namespace, self::GATEWAY, $model,
            $this->ipam !== null, rootScope: $scope, rootManagedIp: $this->ipam !== null ? $endpointField : null);
        $sync->apply($trees, $orgs);
    }

    /** @return array<int|string,array> Desired address assignments keyed by literal or native IP ID. */
    private function addresses(int $org, int $cloud, array $hosts): array
    {
        $addresses = [];
        foreach ($hosts as $ip => $prefix) {
            $key = $this->ipam === null ? $ip : $this->ipam->resolve($org, $cloud, $ip, $prefix, true);
            $addresses[$key] = ['fields' => ['prefix_length' => $prefix]];
        }
        return $addresses;
    }
}
