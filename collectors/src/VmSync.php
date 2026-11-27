<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/VmReconciler.php';

/** Apply preflighted VM claims through iTop REST and a fresh native import window. */
final class VmSync
{
    public const FIELDS = 'name,org_id,virtualhost_id,ionos_vdc_id,ionos_server_id,ionos_source_key,ionos_server_type,cpu,ram';

    /** @param callable(string,int|string,string,int):array $get */
    public function __construct(
        private readonly Closure $get,
        private readonly Closure $create,
        private readonly Closure $resolveCloud,
        private readonly Closure $import,
        private readonly Closure $reportError,
    ) {
    }

    /** Resolve every non-empty, complete scope after the native Cloud source has synchronized. */
    public function apply(array $scopes, string $source, int $sourceId): void
    {
        $servers = [];
        $failed = false;
        foreach ($scopes as $vdc => $rows) {
            if ($rows === []) {
                continue;
            }
            try {
                $cloud = ($this->resolveCloud)($vdc);
            } catch (RuntimeException) {
                ($this->reportError)("Cannot verify the iTop Cloud for IONOS VDC $vdc.");
                $failed = true;
                continue;
            }
            foreach ($rows as $row) {
                $identity = $row['ionos_vdc_id'] . '/' . $row['ionos_server_id'];
                if (isset($servers[$identity])) {
                    throw new RuntimeException('Duplicate normalized IONOS server identity.');
                }
                $servers[$identity] = $row + ['virtualhost_id' => $cloud, 'ionos_source_key' => $source];
            }
        }
        if ($servers !== []) {
            $wanted = $this->preflight($servers, $sourceId);
            foreach ($wanted['errors'] as $error) {
                ($this->reportError)($error);
                $failed = true;
            }
            $this->applyPlan($servers, $wanted['rows'], $sourceId);
        }
        if ($failed) {
            throw new RuntimeException('Some IONOS server scopes or identities could not be synchronized.');
        }
    }

    private function preflight(array $servers, int $sourceId): array
    {
        [$vms, $owners] = $this->snapshot($servers, $sourceId);
        return VmReconciler::plan($servers, $vms, $owners, $sourceId);
    }

    /** Read any matching provider identities, native placement candidates, and their native owners. */
    private function snapshot(array $servers, int $sourceId): array
    {
        $clouds = implode(',', array_unique(array_column($servers, 'virtualhost_id')));
        $vdcs = "'" . implode("','", array_unique(array_column($servers, 'ionos_vdc_id'))) . "'";
        $query = "SELECT VirtualMachine WHERE virtualhost_id IN ($clouds) OR ionos_vdc_id IN ($vdcs)";
        $vms = $this->read('VirtualMachine', $query, self::FIELDS, descendants: true);
        // The current source is always checked, even if the replicas have no destination yet.
        $owners = $own = $states = [];
        $replicaFields = 'sync_source_id,dest_id,dest_class,status,status_last_error';
        foreach ($this->read('SynchroReplica', "SELECT SynchroReplica WHERE sync_source_id = $sourceId", $replicaFields) as $key => $replica) {
            $fields = $replica['fields'];
            $id = (int) $fields['dest_id'];
            if ((int) $fields['sync_source_id'] !== $sourceId) {
                throw new RuntimeException('Unexpected iTop VM synchronization ownership response.');
            }
            if ($id > 0 && $fields['dest_class'] !== 'VirtualMachine') {
                throw new RuntimeException('The IONOS VM source owns a destination outside its exact target class.');
            }
            if (isset($vms[$id])) {
                $own[$key] = $id;
                $states[$id] = $fields['status'] === 'synchronized' && (string) $fields['status_last_error'] === '';
            }
        }
        foreach (array_chunk(array_keys($vms), 250) as $ids) {
            $keys = implode(',', $ids);
            $query = "SELECT SynchroReplica WHERE dest_id IN ($keys) AND dest_class = 'VirtualMachine'";
            foreach ($this->read('SynchroReplica', $query, $replicaFields) as $key => $replica) {
                $fields = $replica['fields'];
                $id = (int) $fields['dest_id'];
                $owner = (int) $fields['sync_source_id'];
                if ($owner < 1 || !isset($vms[$id]) || $fields['dest_class'] !== 'VirtualMachine') {
                    throw new RuntimeException('Invalid native iTop VM synchronization ownership.');
                }
                if ($owner === $sourceId) {
                    if (($own[$key] ?? null) !== $id) {
                        throw new RuntimeException('iTop returned inconsistent VM synchronization ownership.');
                    }
                    unset($own[$key]);
                }
                $owners[$id][] = $owner;
            }
        }
        if ($own !== []) {
            throw new RuntimeException('iTop omitted previously observed native VM ownership.');
        }
        return [$vms, $owners, $states];
    }

    private function applyPlan(array $servers, array $plans, int $sourceId): void
    {
        if ($plans === []) {
            return;
        }
        // Confirm the target can supply the native import cutoff before creating any provisional CIs.
        $this->awaitQuietSecond($sourceId);
        $this->checkPlacement($plans);
        $this->checkPlans($servers, $plans, $sourceId);
        foreach ($plans as &$plan) {
            if ($plan['target'] === null) {
                // A retry after an interrupted create will find the scoped identity on the provisional CI.
                $plan['target'] = $this->create($plan['fields']);
            }
        }
        unset($plan);
        $this->checkPlacement($plans);
        $this->checkPlans($servers, $plans, $sourceId);
        $this->awaitQuietSecond($sourceId);
        ($this->import)($this->csv($plans), count($plans));
        $this->verify($servers, $plans, $sourceId);
    }

    private function checkPlacement(array $plans): void
    {
        $checked = [];
        foreach ($plans as $plan) {
            $fields = $plan['fields'];
            $vdc = $fields['ionos_vdc_id'];
            $cloud = $checked[$vdc] ??= ($this->resolveCloud)($vdc);
            if ($cloud !== $fields['virtualhost_id']) {
                throw new RuntimeException('A verified IONOS VM parent changed during reconciliation.');
            }
        }
    }

    private function checkPlans(array $servers, array $plans, int $sourceId): void
    {
        $current = $this->preflight($servers, $sourceId)['rows'];
        if ($current !== $plans) {
            throw new RuntimeException('IONOS VM destinations or their managed fields changed during reconciliation.');
        }
    }

    /** Combined import/exec uses database NOW() at entry with full_load_periodicity=0. */
    private function awaitQuietSecond(int $sourceId): void
    {
        $query = "SELECT SynchroReplica WHERE sync_source_id = $sourceId AND status_last_seen >= NOW()";
        for ($attempt = 0; $attempt < 12; ++$attempt) {
            if ($this->read('SynchroReplica', $query, 'sync_source_id', 1) === []) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Could not exclude every previous native replica from the fresh VM import window.');
    }

    private function create(array $fields): int
    {
        try {
            $response = ($this->create)('VirtualMachine', array_filter($fields, static fn ($value) => $value !== null),
                'Create an IONOS virtual machine for native synchronization.');
        } catch (Throwable) {
            throw new RuntimeException('iTop could not create the IONOS VM.');
        }
        $objects = self::objects($response, 'VirtualMachine', '');
        if (count($objects) !== 1) {
            throw new RuntimeException('Cannot verify the iTop VM creation result.');
        }
        return array_key_first($objects);
    }

    private function csv(array $plans): string
    {
        $stream = fopen('php://memory', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Cannot prepare the native VM import.');
        }
        try {
            $fields = explode(',', self::FIELDS);
            fputcsv($stream, ['primary_key', ...$fields], ';', '"', '');
            foreach ($plans as $plan) {
                fputcsv($stream, [$plan['target'], ...array_map(static fn ($field) => $plan['fields'][$field], $fields)],
                    ';', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new RuntimeException('Cannot read the native VM import.');
            }
            return $csv;
        } finally {
            fclose($stream);
        }
    }

    private function verify(array $servers, array $plans, int $sourceId): void
    {
        [$vms, $owners, $states] = $this->snapshot($servers, $sourceId);
        foreach ($plans as $plan) {
            $id = $plan['target'];
            $record = $vms[$id] ?? [];
            if (($record['class'] ?? null) !== 'VirtualMachine' || ($owners[$id] ?? []) !== [$sourceId]
                || !($states[$id] ?? false)) {
                throw new RuntimeException('iTop did not bind the expected VM destination to its native synchronization source.');
            }
            foreach ($plan['fields'] as $field => $value) {
                if ((string) $record['fields'][$field] !== (string) $value) {
                    throw new RuntimeException("iTop did not store the expected VM value for $field.");
                }
            }
        }
    }

    private function read(string $class, string $query, string $fields, int $limit = 0, bool $descendants = false): array
    {
        try {
            $response = ($this->get)($class, $query, $fields, $limit);
        } catch (Throwable) {
            throw new RuntimeException("iTop could not read $class for VM reconciliation.");
        }
        $objects = self::objects($response, $class, $fields, $descendants);
        if (!preg_match('/^Found: ([0-9]+)$/D', $response['message'] ?? '', $match)
            || count($objects) !== ($limit > 0 ? min($limit, (int) $match[1]) : (int) $match[1])) {
            throw new RuntimeException("Incomplete iTop $class reconciliation response.");
        }
        return $objects;
    }

    private static function objects(array $response, string $class, string $fields, bool $descendants = false): array
    {
        if (($response['code'] ?? null) !== 0 || !array_key_exists('objects', $response)
            || ($response['objects'] !== null && !is_array($response['objects']))) {
            throw new RuntimeException("iTop did not provide a complete $class response for VM reconciliation.");
        }
        $result = [];
        $columns = $fields === '' ? [] : array_flip(explode(',', $fields));
        foreach ($response['objects'] ?? [] as $reference => $object) {
            $id = filter_var($object['key'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $actualClass = $object['class'] ?? null;
            if (!$id || !is_string($actualClass) || $reference !== "$actualClass::$id"
                || (!$descendants && $class !== $actualClass) || isset($result[$id])
                || ($object['code'] ?? null) !== 0 || !is_array($object['fields'] ?? null)
                || array_diff_key($columns, $object['fields'])) {
                throw new RuntimeException("iTop provided invalid $class reconciliation records.");
            }
            foreach ($columns as $field => $index) {
                $value = $object['fields'][$field];
                if ($value !== null && !is_scalar($value)) {
                    throw new RuntimeException("Unexpected field type for $class.$field during VM reconciliation.");
                }
            }
            $result[$id] = $object;
        }
        return $result;
    }
}
