<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RuntimeException;

require_once __DIR__ . '/IpFacts.php';

/** Validate the data and references within one complete regional gateway list. */
final class VpnInventory
{
    public const PROTOCOLS = ['/ipsecgateways' => 'ipsec', '/wireguardgateways' => 'wireguard'];

    /** @return list<array> Gateways with normalized endpoint, LAN and interface hosts. */
    public static function gateways(array $scope, array $vdcs, array $lans): array
    {
        $protocol = self::PROTOCOLS[$scope['path']];
        $gateways = [];
        foreach ($scope['items'] as $item) {
            $id = strtolower($item['id']);
            if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/D', $id) || isset($gateways[$id])) {
                throw new RuntimeException('Invalid or duplicate VPN gateway ID.');
            }
            $a = $item['attributes'];
            $name = $a['name'] ?? null;
            $port = $protocol === 'wireguard' ? ($a['listenPort'] ?? null) : null;
            $ike = $protocol === 'ipsec' ? ($a['version'] ?? null) : null;
            $description = $a['description'] ?? '';
            $tier = $a['tier'] ?? '';
            $status = $a['providerStatus'] ?? '';
            if (!is_string($name) || trim($name) === '' || !is_string($description)
                || !is_string($tier) || !is_string($status)
                || ($ike !== null && !is_string($ike) && !is_int($ike))
                || ($port !== null && (!is_int($port) || $port < 1 || $port > 65535))) {
                throw new RuntimeException("Gateway $id has invalid properties.");
            }
            $endpoint = IpFacts::parse($a['gatewayIP'] ?? null);
            if (($endpoint['family'] ?? null) !== 4 || $endpoint['prefix'] !== null) {
                throw new RuntimeException("Gateway $id has an invalid public IPv4 endpoint.");
            }

            $connections = $a['connections'] ?? null;
            if (!is_array($connections) || !array_is_list($connections) || $connections === []) {
                throw new RuntimeException("Gateway $id has missing or invalid LAN connections.");
            }
            $cloud = null;
            $attachments = [];
            foreach ($connections as $connection) {
                $vdc = $connection['datacenterId'] ?? null;
                $lan = $connection['lanId'] ?? null;
                $vdc = is_string($vdc) ? strtolower($vdc) : '';
                if (($cloud !== null && $cloud !== $vdc) || !isset($vdcs[$vdc])) {
                    throw new RuntimeException("Gateway $id has connections outside one observed VDC.");
                }
                if ((!is_int($lan) && !is_string($lan)) || !isset($lans[$vdc][$lan]) || isset($attachments[$lan])) {
                    throw new RuntimeException("Gateway $id has an unresolved or duplicate LAN connection.");
                }
                $cloud = $vdc;
                $attachments[$lan] = self::hosts($connection, 'ipv4CIDR', 'ipv6CIDR', $id);
            }
            $gateways[$id] = [
                'id' => $id, 'cloud' => $cloud, 'endpoint' => $endpoint['address'],
                'attachments' => $attachments,
                'interface' => $protocol === 'wireguard'
                    ? self::hosts($a, 'interfaceIPv4CIDR', 'interfaceIPv6CIDR', $id) : [],
                'fields' => ['name' => $name, 'description' => $description, 'protocol' => $protocol,
                    'ike_version' => $ike === null ? '' : (string) $ike, 'listen_port' => $port,
                    'ionos_tier' => $tier, 'ionos_status' => $status],
            ];
        }
        return array_values($gateways);
    }

    /** @return array<string,int> Host literals with reported prefix lengths. */
    private static function hosts(array $data, string $v4, string $v6, string $id): array
    {
        $hosts = [];
        foreach ([$v4 => 4, $v6 => 6] as $field => $family) {
            if (!isset($data[$field])) {
                continue;
            }
            $ip = IpFacts::parse($data[$field]);
            if (($ip['family'] ?? null) !== $family || $ip['prefix'] === null) {
                throw new RuntimeException("Gateway $id has an invalid $field.");
            }
            $hosts[$ip['address']] = $ip['prefix'];
        }
        return $hosts;
    }
}
