<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
use RuntimeException;
use Throwable;

/** Reconcile the single IONOS allocation without replacing a Cloud's shared IP object list. */
final class CloudIpLinkSync
{
    private const LINK = 'lnkCloudToIPObject';

    /**
     * @param Closure $get Native Base REST core/get operation.
     * @param Closure $create Native Base REST core/create operation.
     * @param Closure $delete Native core/delete operation receiving a fully scoped link key.
     */
    public function __construct(
        private readonly Closure $get,
        private readonly Closure $create,
        private readonly Closure $delete,
    ) {}

    /**
     * First identify every current parent and read all its links. Only VDCs in this snapshot participate.
     * @param array<string,?int> $allocations VDC UUID to validated block ID, or explicit null.
     * @param Closure(string):int $resolveCloud Resolve in the configured customer and provider.
     * @param string $source Stable attribution for this customer, provider and contract.
     */
    public function apply(array $allocations, Closure $resolveCloud, string $source): void
    {
        if ($source === '') {
            throw new RuntimeException('An allocation source key is required.');
        }
        $plans = [];
        foreach ($allocations as $uuid => $block) {
            $cloud = $resolveCloud($uuid);
            $plans[] = [$cloud, $block, $this->links($cloud)];
        }
        foreach ($plans as [$cloud, $block, $links]) {
            if ($block !== null && !isset($links[$block])) {
                // Insert the new association before retiring the old one. A lost reply is safe to retry.
                try {
                    ($this->create)(self::LINK, [
                        'cloud_id' => $cloud, 'ipobject_id' => $block, 'source_key' => $source,
                    ], 'Record the IONOS VDC IPv6 allocation.');
                } catch (Throwable) {
                    // A concurrent manual insertion or a timeout may still have created the exact pair.
                }
                $current = $this->links($cloud);
                if (!isset($current[$block])) {
                    throw new RuntimeException('Could not establish the new IPv6 allocation. Previous links were retained.');
                }
            }
            foreach ($links as $target => $link) {
                if ($target === $block || $link['source_key'] !== $source) {
                    continue;
                }
                try {
                    $result = ($this->delete)($link);
                } catch (Throwable) {
                    throw new RuntimeException('Cannot confirm removal of the previous IONOS IPv6 association.');
                }
                $object = $result['objects'][self::LINK . '::' . $link['id']] ?? null;
                if (($result['code'] ?? null) !== 0 || ($object['code'] ?? null) !== 0) {
                    throw new RuntimeException('The previous IONOS IPv6 association could not be removed.');
                }
            }
        }
    }

    /**
     * Read all current associations of one Cloud, indexed by the unique IP object.
     * An absent source or an ambiguous pair must never confer ownership.
     * @return array<int,array{id:int,cloud_id:int,ipobject_id:int,source_key:?string}>
     */
    private function links(int $cloud): array
    {
        try {
            $result = ($this->get)(self::LINK, ['cloud_id' => $cloud], 'cloud_id,ipobject_id,source_key');
        } catch (Throwable) {
            throw new RuntimeException('Cannot read the Cloud IP associations.');
        }
        if (($result['code'] ?? null) !== 0 || !array_key_exists('objects', $result)) {
            throw new RuntimeException('Invalid Cloud IP association reply.');
        }
        $objects = $result['objects'];
        if ($objects === null && ($result['message'] ?? null) === 'Found: 0') {
            return [];
        }
        if (!is_array($objects) || ($result['message'] ?? null) !== 'Found: ' . count($objects)) {
            throw new RuntimeException('Incomplete Cloud IP association reply.');
        }
        $links = [];
        foreach ($objects as $key => $object) {
            $fields = $object['fields'] ?? [];
            $id = filter_var($object['key'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $target = filter_var($fields['ipobject_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (($object['code'] ?? null) !== 0 || ($object['class'] ?? null) !== self::LINK
                || !$id || $key !== self::LINK . '::' . $id || !$target || isset($links[$target])
                || (string) ($fields['cloud_id'] ?? '') !== (string) $cloud
                || !array_key_exists('source_key', $fields)
                || ($fields['source_key'] !== null && !is_string($fields['source_key']))) {
                throw new RuntimeException('Invalid or ambiguous Cloud IP association.');
            }
            $links[$target] = ['id' => $id, 'cloud_id' => $cloud, 'ipobject_id' => $target, 'source_key' => $fields['source_key']];
        }
        return $links;
    }
}
