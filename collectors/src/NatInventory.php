<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RuntimeException;

require_once __DIR__ . '/LanSync.php';
require_once __DIR__ . '/IpFacts.php';

/** Validate observed NAT gateways and retain the LAN context of every private host address. */
final class NatInventory
{
    /**
     * @param array<string,mixed> $report Complete VDC, LAN and NAT inventory.
     * @param list<array> $parents Validated VDC synchronization rows for this inventory.
     * @return list<array{cloud_resource_id:string,org_id:int,provider_resource_id:string,name:string,public_ips:list<string>,lans:list<array>}>
     */
    public static function gateways(array $report, array $parents): array
    {
        $lans = [];
        foreach (LanSync::rows($report, $parents) as $lan) {
            $lans[$lan['cloud_id']][$lan['provider_resource_id']] = $lan['public'];
        }
        $collections = VdcChildScopes::items($report, $parents, 'natgateways');
        $gateways = [];
        foreach ($parents as $parent) {
            $cloud = $parent['provider_resource_id'];
            $seen = [];
            foreach ($collections[$cloud] as $item) {
                $id = $item['id'] ?? null;
                $attributes = $item['attributes'] ?? [];
                $name = $attributes['name'] ?? null;
                if (!is_string($id) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $id)
                    || isset($seen[strtolower($id)]) || !is_string($name) || trim($name) === '') {
                    throw new RuntimeException('A NAT gateway has an invalid or repeated UUID or a missing name.');
                }
                $seen[strtolower($id)] = true;
                $gateways[] = [
                    'cloud_resource_id' => $cloud, 'org_id' => $parent['org_id'],
                    'provider_resource_id' => strtolower($id), 'name' => $name,
                    'public_ips' => array_column(self::addresses($attributes['publicIps'] ?? null, false), 'address'),
                    'lans' => self::attachments($attributes['lans'] ?? null, $lans[$cloud] ?? []),
                ];
            }
        }
        return $gateways;
    }

    /** @return list<array{provider_resource_id:string,addresses:list<array>}> One attachment per private LAN in this Cloud. */
    private static function attachments(mixed $items, array $lanFlags): array
    {
        if (!is_array($items) || !array_is_list($items)) {
            throw new RuntimeException('A NAT gateway has no authoritative LAN list.');
        }
        $rows = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string) $id)
                || ($lanFlags[$id] ?? null) !== '0' || isset($rows[$id])) {
                throw new RuntimeException('A NAT attachment references an unknown, public or duplicate LAN.');
            }
            $rows[$id] = ['provider_resource_id' => (string) $id, 'addresses' => self::addresses($item['gatewayIps'] ?? null, true)];
        }
        return array_values($rows);
    }

    /** @return list<array{address:string,prefix_length:?int}> Canonical hosts and their reported private prefix lengths. */
    private static function addresses(mixed $values, bool $private): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException('A NAT gateway address list is missing or malformed.');
        }
        $rows = [];
        foreach ($values as $value) {
            $host = IpFacts::parse($value);
            if ($host === null || ($host['prefix'] !== null) !== $private || isset($rows[$host['address']])) {
                throw new RuntimeException('A NAT gateway address has an invalid host, prefix or duplicate.');
            }
            $rows[$host['address']] = ['address' => $host['address'], 'prefix_length' => $host['prefix']];
        }
        return array_values($rows);
    }
}
