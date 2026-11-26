<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RuntimeException;

/** Check the complete collection of one VDC child resource for every observed parent. */
final class VdcChildScopes
{
    /**
     * @param array<string,mixed> $report Synchronization inventory from the compute endpoint.
     * @param list<array> $vdcs Validated parent rows.
     * @return array<string,list<array>> Child items keyed by their owning VDC's provider ID.
     */
    public static function items(array $report, array $vdcs, string $resource): array
    {
        if (($report['complete'] ?? null) !== true) {
            throw new RuntimeException('Cannot synchronize an incomplete IONOS inventory.');
        }
        $template = '/datacenters/{datacenterId}/' . $resource;
        $pending = [];
        foreach ($vdcs as $vdc) {
            $id = $vdc['provider_resource_id'];
            $path = '/datacenters/' . $id . '/' . $resource;
            if (isset($pending[$path])) {
                throw new RuntimeException('Duplicate VDC in synchronization inventory.');
            }
            $pending[$path] = $id;
        }
        $items = [];
        $noParents = false;
        foreach (array_slice($report['scopes'], 1) as $scope) {
            if (($scope['complete'] ?? null) !== true || ($scope['product'] ?? null) !== 'compute'
                || !in_array($scope['template'] ?? null, ['/datacenters/{datacenterId}/lans',
                    '/datacenters/{datacenterId}/natgateways', '/datacenters/{datacenterId}/networkloadbalancers'], true)) {
                throw new RuntimeException('Unexpected or incomplete VDC child collection.');
            }
            if ($scope['template'] !== $template) {
                continue;
            }
            $rows = $scope['items'] ?? null;
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new RuntimeException('Invalid VDC child collection.');
            }
            $path = $scope['path'] ?? '';
            if ($vdcs === [] && !$noParents && $path === '/datacenters' && $rows === [] && ($scope['status'] ?? null) === 'no_parents') {
                $noParents = true;
                continue;
            }
            if (!isset($pending[$path]) || ($scope['status'] ?? null) !== ($rows === [] ? 'empty' : 'present')) {
                throw new RuntimeException('Unknown, duplicate or inconsistent VDC child collection.');
            }
            $items[$pending[$path]] = $rows;
            unset($pending[$path]);
        }
        if ($pending !== [] || ($vdcs === [] && !$noParents)) {
            throw new RuntimeException('Missing VDC child collection.');
        }
        return $items;
    }
}
