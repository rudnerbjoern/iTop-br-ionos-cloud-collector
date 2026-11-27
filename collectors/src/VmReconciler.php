<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

/** Decide every claim before making any iTop changes. */
final class VmReconciler
{
    /** @return array{rows:array,errors:list<string>} Native targets (null for creation) and their desired fields. */
    public static function plan(array $servers, array $vms, array $owners, int $sourceId): array
    {
        $rows = $errors = $claims = [];
        $byIdentity = $byHostAndId = $byName = $broken = [];
        foreach ($vms as $id => $vm) {
            $fields = $vm['fields'];
            $providerId = VmInventory::uuid($fields['ionos_server_id']);
            $providerVdc = VmInventory::uuid($fields['ionos_vdc_id']);
            $host = (int) $fields['virtualhost_id'];
            $org = (int) $fields['org_id'];
            $ownership = $owners[$id] ?? [];
            if (in_array($sourceId, $ownership, true)
                && ($providerId === null || $providerVdc === null || (string) $fields['ionos_source_key'] === '')) {
                $broken[$host][$org][$fields['name']] = true;
            }
            if ($providerId !== null) {
                $byIdentity["$providerVdc/$providerId"][$id] = $vm;
                $byHostAndId[$host][$providerId][$id] = $vm;
            } elseif ($vm['class'] === 'VirtualMachine' && $ownership === []
                && (string) $fields['ionos_server_id'] === '' && (string) $fields['ionos_vdc_id'] === ''
                && (string) $fields['ionos_source_key'] === '') {
                $byName[$host][$org][$fields['name']][] = $id;
            }
        }
        foreach ($servers as $identity => $server) {
            $matches = $byIdentity[$identity] ?? [];
            $matches += $byHostAndId[$server['virtualhost_id']][$server['ionos_server_id']] ?? [];
            if ($matches !== []) {
                $target = array_key_first($matches);
                $vm = $matches[$target];
                $fields = $vm['fields'];
                $ownership = $owners[$target] ?? [];
                if (count($matches) !== 1 || $vm['class'] !== 'VirtualMachine'
                    || (int) $fields['virtualhost_id'] !== $server['virtualhost_id']
                    || (int) $fields['org_id'] !== $server['org_id']
                    || VmInventory::uuid($fields['ionos_vdc_id']) !== $server['ionos_vdc_id']
                    || $fields['ionos_source_key'] !== $server['ionos_source_key']
                    || ($ownership !== [] && $ownership !== [$sourceId])) {
                    $errors[] = "Conflicting IONOS server identity $identity.";
                    continue;
                }
            } else {
                if (isset($broken[$server['virtualhost_id']][$server['org_id']][$server['name']])) {
                    $errors[] = "Conflicting IONOS server identity $identity: a scoped native owner has incomplete provider identity.";
                    continue;
                }
                $reservations = $byName[$server['virtualhost_id']][$server['org_id']][$server['name']] ?? [];
                $target = $reservations[0] ?? null;
                foreach ($reservations as $reservation) {
                    $claims[$reservation][] = $identity;
                }
                if (count($reservations) > 1) {
                    $errors[] = "Multiple VM reservations match IONOS server $identity.";
                    continue;
                }
            }
            if ($target !== null) {
                // iTop's native string import resets fields on an empty CSV cell. Carry forward verified values.
                foreach (['cpu', 'ram'] as $metric) {
                    $stored = (string) $vms[$target]['fields'][$metric];
                    $server[$metric] ??= $stored === '' ? null : $stored;
                }
            }
            $rows[$identity] = ['target' => $target, 'fields' => $server];
        }
        foreach ($claims as $claimants) {
            if (count($claimants) > 1) {
                $errors[] = 'Multiple IONOS servers claim one VM reservation (' . implode(', ', $claimants) . ').';
                foreach ($claimants as $identity) {
                    unset($rows[$identity]);
                }
            }
        }
        return ['rows' => $rows, 'errors' => $errors];
    }
}
