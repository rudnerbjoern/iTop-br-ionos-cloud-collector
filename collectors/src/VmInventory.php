<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RuntimeException;

/** Validate each VDC's complete server listing independently of the other VDCs. */
final class VmInventory
{
    public const TEMPLATE = '/datacenters/{datacenterId}/servers';

    /** Return native VM fields keyed by VDC UUID, reporting incomplete or malformed scopes. */
    public static function scopes(array $report, array $vdcs, callable $reportError): array
    {
        $scopes = [];
        foreach ($report['scopes'] as $scope) {
            if ($scope['template'] === self::TEMPLATE && $scope['product'] === 'compute') {
                $scopes[$scope['path']][] = $scope;
            }
        }
        $result = [];
        foreach ($vdcs as $vdc) {
            $vdcId = $vdc['provider_resource_id'];
            $matches = $scopes["/datacenters/$vdcId/servers"] ?? [];
            $scope = $matches[0] ?? null;
            if (count($matches) !== 1 || !$scope['complete']) {
                $status = $scope['status'] ?? 'missing';
                $reportError("Incomplete IONOS server listing for VDC $vdcId ($status).");
                continue;
            }
            $rows = [];
            try {
                foreach ($scope['items'] as $item) {
                    $id = self::uuid($item['id']);
                    $attributes = $item['attributes'];
                    $name = self::property($attributes['name'] ?? null);
                    $type = self::property($attributes['type'] ?? null);
                    if ($id === null || $name === null || $type === null || isset($rows[$id])) {
                        throw new RuntimeException('Invalid or repeated server identity, name or type.');
                    }
                    $cpu = self::measurement($attributes['cores'] ?? null);
                    $ram = self::measurement($attributes['ram'] ?? null);
                    $rows[$id] = [
                        'ionos_vdc_id' => self::uuid($vdcId), 'ionos_server_id' => $id, 'name' => $name,
                        'ionos_server_type' => $type, 'cpu' => $cpu, 'ram' => $ram !== null ? "$ram MB" : null,
                        'org_id' => $vdc['org_id'],
                    ];
                }
            } catch (RuntimeException $error) {
                $reportError("Invalid IONOS server listing for VDC $vdcId. {$error->getMessage()}");
                continue;
            }
            $result[$vdcId] = array_values($rows);
        }
        return $result;
    }

    public static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di', $value)
            ? strtolower($value) : null;
    }

    private static function property(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && trim($value) === $value
            && mb_strlen($value) <= 255 && !preg_match('/[\x00-\x1F\x7F]/', $value) ? $value : null;
    }

    private static function measurement(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_int($value) || $value <= 0) {
            throw new RuntimeException('Invalid reported CPU or RAM.');
        }
        return (string) $value;
    }
}
