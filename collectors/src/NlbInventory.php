<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RuntimeException;

require_once __DIR__ . '/VdcChildScopes.php';
require_once __DIR__ . '/IpFacts.php';

/** Validate both LAN roles and the load balancer's own addresses for every observed VDC. */
final class NlbInventory
{
    /** @param list<array> $vdcs VDC rows. @param list<array> $lans LAN rows from the same inventory. */
    public static function loadBalancers(array $report, array $vdcs, array $lans): array
    {
        $lanFlags = [];
        foreach ($lans as $lan) {
            $lanFlags[$lan['cloud_id']][$lan['provider_resource_id']] = $lan['public'] === '1';
        }
        $collections = VdcChildScopes::items($report, $vdcs, 'networkloadbalancers');
        $balancers = [];
        foreach ($vdcs as $vdc) {
            $uuid = $vdc['provider_resource_id'];
            $seen = [];
            foreach ($collections[$uuid] as $item) {
                $id = $item['id'] ?? null;
                $properties = $item['attributes'] ?? [];
                $name = $properties['name'] ?? null;
                if (!is_string($id) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $id)
                    || isset($seen[strtolower($id)]) || !is_string($name) || trim($name) === '') {
                    throw new RuntimeException("Invalid or duplicate network load balancer in VDC $uuid.");
                }
                $id = strtolower($id);
                $seen[$id] = true;
                $listener = self::lan($properties['listenerLan'] ?? null, $lanFlags[$uuid] ?? []);
                $backend = self::lan($properties['targetLan'] ?? null, $lanFlags[$uuid] ?? []);
                $balancers[] = [
                    'cloud_resource_id' => $uuid, 'org_id' => $vdc['org_id'],
                    'provider_resource_id' => $id, 'name' => $name,
                    'listener_lan' => $listener, 'backend_lan' => $backend,
                    'listener_public' => $lanFlags[$uuid][$listener],
                    'listener_ips' => self::addresses($properties['ips'] ?? null, false),
                    'backend_ips' => self::addresses($properties['lbPrivateIps'] ?? null, true),
                ];
            }
        }
        return $balancers;
    }

    /** Resolve a numeric provider LAN ID against the inventory of this VDC. */
    private static function lan(mixed $id, array $lanFlags): string
    {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]*$/D', (string) $id)
            || !isset($lanFlags[$id])) {
            throw new RuntimeException('A network load balancer references an unknown LAN in its VDC.');
        }
        return (string) $id;
    }

    /** @return array<string,?int> Canonical hosts mapped to their reported prefix lengths. */
    private static function addresses(mixed $values, bool $withPrefix): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            throw new RuntimeException('A network load balancer address list is missing or malformed.');
        }
        $addresses = [];
        foreach ($values as $value) {
            $host = IpFacts::parse($value);
            if ($host === null || ($host['prefix'] !== null) !== $withPrefix
                || array_key_exists($host['address'], $addresses)) {
                throw new RuntimeException('A network load balancer has an invalid or duplicate host address or prefix.');
            }
            $addresses[$host['address']] = $host['prefix'];
        }
        return $addresses;
    }
}
