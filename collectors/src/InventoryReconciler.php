<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RuntimeException;

require_once __DIR__ . '/InventoryStore.php';

/** Apply scoped ownership and child-first withdrawal rules to a tree of imported records. */
final class InventoryReconciler
{
    /** @param array<string,array> $model Parent field, identity, mutable fields and dependent classes per iTop class. */
    public function __construct(
        private readonly InventoryStore $store,
        private readonly string $source,
        private readonly string $root,
        private readonly array $model,
        private readonly bool $ipam,
        private readonly array $placementFields = [],
    ) {}

    /** Upsert all desired rows before withdrawing any stale, source-owned records. */
    public function apply(array $trees, array $organizations): void
    {
        $removals = [];
        foreach ($trees as $cloud => $tree) {
            $this->collection($this->root, $cloud, $tree, $removals, $organizations[$cloud]);
        }
        foreach ($removals as [$class, $key]) {
            if (!$this->hasChildren($class, $key['id'])) {
                $this->store->remove($class, $key);
            }
        }
    }

    /** Match one native parent collection by its local identity and traverse all observed and retained children. */
    private function collection(string $class, int $parent, array $wanted, array &$removals, ?int $org = null): void
    {
        [$parentField, $identity, $fields, $children] = $this->model[$class];
        $ipam = $identity === 'address' && $this->ipam;
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
            } elseif ($class === $this->root) {
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
                if ($class === $this->root && (int) $row['org_id'] !== $org) {
                    if ($node !== null) {
                        throw new RuntimeException("The $class record belongs to a different organization.");
                    }
                    continue;
                }
                $owned = $row['ionos_source_key'] === $this->source;
                $key = ['id' => $id, $parentField => $parent, $identity => $row[$identity],
                    'ionos_source_key' => $this->source];
                if ($class === $this->root) {
                    $key['org_id'] = $org;
                }
                if ($class === $this->root && !$owned && $node !== null) {
                    foreach ($this->placementFields as $field) {
                        if ((int) $row[$field] !== (int) $fields[$field]) {
                            throw new RuntimeException("The existing $class record uses a different LAN.");
                        }
                    }
                }
                if ($ipam && (int) $row['ionos_synced_ip_id'] !== (int) $value) {
                    // Changing the host reference changes the identity of this assignment.
                    $owned = false;
                } elseif ($ipam) {
                    $key['ionos_synced_ip_id'] = $row['ionos_synced_ip_id'];
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
        foreach ($this->model[$class][3] as $child) {
            if ($this->store->rows($child, [$this->model[$child][0] => $id], []) !== []) {
                return true;
            }
        }
        return false;
    }
}
