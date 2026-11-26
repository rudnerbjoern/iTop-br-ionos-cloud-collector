<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RuntimeException;

require_once __DIR__ . '/VdcChildScopes.php';

/** Map complete per-VDC LAN lists to the native synchronization columns for CloudLan. */
final class LanSync
{
    /**
     * Use the owning VDC's source UUID for the Cloud external key and its resolved customer for org_id.
     *
     * @param array<string,mixed> $report Compute inventory with one LAN collection per observed VDC.
     * @param list<array<string,mixed>> $vdcs Validated VDC synchronization rows from the same inventory.
     * @return list<array<string,string|int>> LAN rows ready for the Base CSV writer.
     */
    public static function rows(array $report, array $vdcs): array
    {
        $collections = VdcChildScopes::items($report, $vdcs, 'lans');
        $rows = [];
        foreach ($vdcs as $vdc) {
            $cloud = $vdc['provider_resource_id'];
            $seen = [];
            foreach ($collections[$cloud] as $item) {
                $id = $item['id'] ?? null;
                $name = $item['attributes']['name'] ?? '';
                $public = $item['attributes']['public'] ?? null;
                if (!is_string($id) || trim($id) === '' || isset($seen[$id]) || !is_string($name) || !is_bool($public)) {
                    throw new RuntimeException('An IONOS LAN has an invalid or duplicate ID, name or public flag.');
                }
                $seen[$id] = true;
                $rows[] = [
                    'primary_key' => $cloud . '/' . $id,
                    'cloud_id' => $cloud,
                    'provider_resource_id' => $id,
                    'name' => trim($name) === '' ? 'LAN ' . $id : $name,
                    'org_id' => $vdc['org_id'],
                    'public' => $public ? '1' : '0',
                ];
            }
        }
        return $rows;
    }
}
