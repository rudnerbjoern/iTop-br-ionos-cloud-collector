<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
use RuntimeException;
use Throwable;

/** Execute scoped iTop reads and require an unambiguous result for every NAT write. */
final class NatStore
{
    /** @param Closure $get Base Get transport. @param Closure $create Base Create transport. */
    public function __construct(
        private readonly Closure $get,
        private readonly Closure $create,
        private readonly Closure $update,
        private readonly Closure $delete,
    ) {}

    /**
     * Fetch a complete collection and check its parent, selected fields and native object identities.
     * @param array<string,int|string> $key Native REST conditions.
     * @param list<string> $fields Inventory fields required in addition to the key.
     * @return list<array<string,mixed>> Selected fields and the iTop primary key.
     */
    public function rows(string $class, array $key, array $fields): array
    {
        $fields = array_unique([...array_keys($key), ...$fields]);
        $reply = $this->call($this->get, $class, $key, implode(',', $fields));
        if (!array_key_exists('objects', $reply)) {
            throw new RuntimeException("Missing $class records in the REST reply.");
        }
        $objects = $reply['objects'] ?? [];
        if (!is_array($objects) || ($reply['message'] ?? '') !== 'Found: ' . count($objects)) {
            throw new RuntimeException("Incomplete $class collection.");
        }
        $rows = [];
        foreach ($objects as $reference => $object) {
            $id = filter_var($object['key'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $data = $object['fields'] ?? null;
            if (!$id || $reference !== "$class::$id" || ($object['class'] ?? '') !== $class
                || ($object['code'] ?? null) !== 0 || !is_array($data) || array_diff($fields, array_keys($data))) {
                throw new RuntimeException("Invalid $class inventory record.");
            }
            foreach ($key as $field => $value) {
                if ((string) $data[$field] !== (string) $value) {
                    throw new RuntimeException("Incorrect scope in the $class inventory.");
                }
            }
            $rows[] = ['id' => $id] + $data;
        }
        return $rows;
    }

    /**
     * Create one record and obtain its native iTop primary key.
     * @param array<string,mixed> $fields Fully scoped identity and source attribution.
     */
    public function insert(string $class, array $fields): int
    {
        $reply = $this->call($this->create, $class, $fields, 'Record IONOS NAT gateway inventory.');
        return $this->confirmation($class, $reply);
    }

    /**
     * Update a record only while its observed ownership, identity and IPAM reference still match.
     * @param array<string,mixed> $key REST conditions on the observed record.
     * @param array<string,mixed> $changes Fields to update.
     */
    public function change(string $class, array $key, array $changes): void
    {
        $reply = $this->call($this->update, $class, $key, $changes, 'Update the IONOS NAT gateway inventory.');
        if ($this->confirmation($class, $reply) !== $key['id']) {
            throw new RuntimeException("Could not confirm the $class update.");
        }
    }

    /** Delete one source-owned row after all its dependent rows have been handled. */
    public function remove(string $class, array $key): void
    {
        $reply = $this->call($this->delete, $class, $key);
        if ($this->confirmation($class, $reply) !== $key['id']) {
            throw new RuntimeException("Could not confirm the $class deletion.");
        }
    }

    /** Confirm a single successful native object result. */
    private function confirmation(string $class, array $reply): int
    {
        $objects = $reply['objects'] ?? null;
        if (!is_array($objects) || count($objects) !== 1) {
            throw new RuntimeException("Expected one affected $class record.");
        }
        $object = reset($objects);
        $id = filter_var($object['key'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || array_key_first($objects) !== "$class::$id" || ($object['class'] ?? '') !== $class
            || ($object['code'] ?? null) !== 0) {
            throw new RuntimeException("Invalid $class write confirmation.");
        }
        return $id;
    }

    /** Reject transport and operation failures without exposing provider or CMDB response payloads. */
    private function call(Closure $operation, string $class, mixed ...$arguments): array
    {
        try {
            $reply = $operation($class, ...$arguments);
        } catch (Throwable) {
            throw new RuntimeException("The iTop NAT inventory request failed for $class.");
        }
        if (!is_array($reply) || ($reply['code'] ?? null) !== 0) {
            throw new RuntimeException("The iTop NAT inventory request was rejected for $class.");
        }
        return $reply;
    }
}
