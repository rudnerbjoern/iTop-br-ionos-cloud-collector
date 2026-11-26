<?php

/** Map an authoritative IONOS VDC list to the Cloud class of one iTop installation. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/VdcResolver.php';

/** Target-specific decisions shared by source configuration and row generation. */
final class VdcSync
{
    /** Read the latest iTop installation record as the Base does, distinguishing REST failure from absence. */
    public static function canSyncIpv6Links(callable $get): bool
    {
        $caps = self::targetCapabilities($get);
        return $caps['ipam'] && $caps['ipv6'];
    }

    /** @return array{ipam:bool,ipv6:bool} Optional capabilities on the current, validated target installation. */
    public static function targetCapabilities(callable $get): array
    {
        $base = 'br-cloud-datamodel';
        $ionos = 'br-ionos-cloud-datamodel';
        $bridge = 'br-cloud-datamodel-bridge-for-teemip';
        $ipv6 = 'teemip-ipv6-mgmt';
        $installs = self::modules($get, ['name' => 'datamodel'], 'installed', 1);
        $latest = reset($installs);
        $date = $latest['fields']['installed'] ?? '';
        if (!is_string($date) || !preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $date)) {
            throw new RuntimeException('Cannot identify the current iTop installation.');
        }
        $query = "SELECT ModuleInstallation WHERE installed = '$date' AND name IN ('$base', '$ionos', '$bridge', '$ipv6')";
        $names = [];
        foreach (self::modules($get, $query, 'name,version') as $object) {
            $name = $object['fields']['name'] ?? '';
            if (!in_array($name, [$base, $ionos, $bridge, $ipv6], true) || isset($names[$name])) {
                throw new RuntimeException('Ambiguous IONOS module installation records.');
            }
            $version = $object['fields']['version'] ?? '';
            if (!is_string($version) || !preg_match('/^\d+(?:\.\d+)+(?:[-+][0-9A-Za-z.-]+)?$/D', $version)
                || version_compare($version, '0.1.0', '<')) {
                throw new RuntimeException("Incompatible iTop module $name. Version 0.1.0 or later is required.");
            }
            $names[$name] = true;
        }
        foreach ([$base, $ionos] as $required) {
            if (!isset($names[$required])) {
                throw new RuntimeException("Required iTop module $required is missing.");
            }
        }
        return ['ipam' => isset($names[$bridge]), 'ipv6' => isset($names[$ipv6])];
    }

    /**
     * Read installation metadata without allowing a failed request to downgrade the bridge variant.
     * @param array<string,string>|string $key REST search key or OQL.
     * @return array<string,array> Validated installation records.
     */
    private static function modules(callable $get, array|string $key, string $fields, int $limit = 0): array
    {
        try {
            $response = $get('ModuleInstallation', $key, $fields, $limit);
        } catch (Throwable) {
            throw new RuntimeException('Cannot read iTop module installations.');
        }
        if (!is_array($response) || ($response['code'] ?? null) !== 0 || !is_array($response['objects'] ?? null)) {
            throw new RuntimeException('Cannot read iTop module installations with the configured credentials.');
        }
        foreach ($response['objects'] as $object) {
            if (($object['code'] ?? null) !== 0 || ($object['class'] ?? null) !== 'ModuleInstallation') {
                throw new RuntimeException('Invalid module installation response from iTop.');
            }
        }
        return $response['objects'];
    }

    /**
     * Resolve every row before the Base configures a source or uploads any batch.
     * @param array<string,mixed> $report Fresh inventory starting with the compute /datacenters endpoint.
     * @return list<array<string,int|string|null>> CSV rows with the fields present on this target.
     */
    public static function rows(array $report, VdcResolver $resolver, int $org, int $provider): array
    {
        $scopes = $report['scopes'] ?? [];
        $scope = $scopes[0] ?? [];
        if (($report['complete'] ?? false) !== true || ($scope['complete'] ?? false) !== true
            || ($scope['product'] ?? '') !== 'compute' || ($scope['template'] ?? '') !== '/datacenters'
            || !is_array($scope['items'] ?? null)) {
            throw new RuntimeException('The VDC or LAN inventory is incomplete. Synchronization stopped.');
        }
        $rows = [];
        foreach ($scope['items'] as $item) {
            $uuid = $item['id'];
            $attributes = $item['attributes'];
            $name = $attributes['name'] ?? null;
            $location = $attributes['location'] ?? null;
            if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $uuid)
                || !is_string($name) || trim($name) === '' || !is_string($location)) {
                throw new RuntimeException('A VDC lacks a valid UUID, name or location.');
            }
            $access = $attributes['secAuthProtection'] ?? null;
            $row = [
                'primary_key' => $uuid, 'provider_resource_id' => $uuid, 'name' => $name,
                'org_id' => $org, 'provider_id' => $provider,
                'location_id' => $resolver->location($provider, $location),
                'ionos_sec_auth_protection' => match ($access) {
                    true => 'yes', false => 'no', null => 'unknown',
                    default => throw new RuntimeException('Invalid VDC access protection value.'),
                },
            ];
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Resolve all observed IPv6 allocations before any synchronization source is configured or written.
     * @param list<array> $items Items from the complete VDC scope validated by rows().
     * @return array<string,?int> VDC UUID to exact TeemIP block ID, or null for an explicit release.
     */
    public static function ipv6Allocations(array $items, VdcResolver $resolver, int $org): array
    {
        $allocations = [];
        foreach ($items as $item) {
            if (!array_key_exists('ipv6CidrBlock', $item['attributes'])) {
                throw new RuntimeException('The VDC response omitted its IPv6 allocation.');
            }
            $prefix = $item['attributes']['ipv6CidrBlock'];
            $allocations[$item['id']] = $prefix === null ? null : $resolver->ipv6Block($org, $prefix);
        }
        return $allocations;
    }
}
