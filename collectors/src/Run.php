<?php

/** Resolve non-secret settings and persist an inventory report outside the repository index. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

require_once __DIR__ . '/IonosApi.php';
require_once __DIR__ . '/Inventory.php';
require_once __DIR__ . '/Summary.php';

/** Shared inventory entry point for the standalone PHP CLI and the Combodo collector. */
final class Run
{
    /**
     * @param string $root Private installation or checkout containing local configuration and output.
     * @param array<string,mixed> $config Non-secret settings from the collector parameter files.
     * @return bool True only when all selected scopes were read completely.
     * @throws InventoryError On invalid configuration or persistence failure.
     */
    public static function execute(string $root, array $config): bool
    {
        $report = self::read($root, $config);
        return self::save($root, $report);
    }

    /**
     * Collect inventory, optionally narrowing it to the endpoints used for synchronization.
     * @param array<string,mixed> $config Inventory configuration shared by both entry points.
     * @return array<string,mixed> Snapshot including selection and completeness evidence.
     */
    public static function read(string $root, array $config, bool $syncOnly = false): array
    {
        $all = json_decode(file_get_contents(__DIR__ . '/../resources.json'), true, flags: JSON_THROW_ON_ERROR);
        $selection = trim((string)($config['ionos_products'] ?? 'all'));
        $products = $all;
        if ($selection !== 'all') {
            $names = array_map('trim',explode(',',$selection));
            if (array_diff($names,array_keys($all))) {
                throw new InventoryError('unknown_product');
            }
            $products = array_intersect_key($all,array_flip($names));
        }
        $baseSelection = trim((string) ($config['ionos_api_bases'] ?? 'all'));
        if ($baseSelection !== 'all') {
            $requested = array_map('trim', explode(',', $baseSelection));
            $known = array_merge(...array_values(array_column($products, 'bases')));
            if (array_diff($requested, $known)) {
                throw new InventoryError('unknown_api_base');
            }
            foreach ($products as $product => $plan) {
                $bases = array_values(array_intersect($plan['bases'], $requested));
                if ($bases) {
                    $products[$product]['bases'] = $bases;
                } else {
                    unset($products[$product]);
                }
            }
        }
        if ($syncOnly) {
            $compute = $products['compute'] ?? [];
            $paths = ['/datacenters', '/datacenters/{datacenterId}/lans', '/datacenters/{datacenterId}/natgateways',
                '/datacenters/{datacenterId}/networkloadbalancers'];
            if (count($compute['bases'] ?? []) !== 1 || array_diff($paths, array_keys($compute['resources'] ?? []))) {
                throw new InventoryError('vdc_endpoint_outside_selection');
            }
            $compute['resources'] = array_intersect_key($compute['resources'], array_flip($paths));
            $products = ['compute' => $compute];
        }
        $pageSize = (int)($config['ionos_page_size'] ?? 100);
        $timeout = (int)($config['ionos_timeout'] ?? 20);
        if ($timeout < 1 || $timeout > 120) {
            throw new InventoryError('invalid_timeout');
        }
        $token = IonosApi::tokenFromEnvironment($root . '/.env', (string) ($config['ionos_token_env'] ?? 'IONOS_TOKEN'));
        $contract = trim((string)($config['ionos_contract_number'] ?? ''));
        $api = new IonosApi($token, array_merge(...array_values(array_column($products, 'bases'))), $timeout, $contract);
        $inventory = new Inventory($api->get(...), $pageSize, (int)($config['ionos_max_pages'] ?? 500));
        $started = gmdate(DATE_ATOM);
        $report = $inventory->collect($products);
        $report['startedAt'] = $started;
        $report['products'] = array_keys($products);
        $report['apiBases'] = array_map(static fn (array $plan): array => $plan['bases'], $products);
        $report['cloudApiContract'] = $contract === '' ? null : $contract;
        $report['planSha256'] = hash_file('sha256', __DIR__ . '/../resources.json');
        return $report;
    }

    /** Persist a private snapshot and report whether all its scopes were read. */
    private static function save(string $root, array $report): bool
    {
        $directory = $root . '/.tmp/inventory/' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(5));
        if (!mkdir($directory, 0700, true)) {
            throw new InventoryError('output_directory_failed');
        }
        $json = json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . "\n";
        $tmp = $directory . '/inventory.tmp';
        $summary = Summary::markdown($report);
        if (file_put_contents($directory . '/coverage.md', $summary, LOCK_EX) !== strlen($summary)) {
            throw new InventoryError('coverage_write_failed');
        }
        if (file_put_contents($tmp,$json,LOCK_EX) !== strlen($json) || !rename($tmp,$directory.'/inventory.json')) {
            throw new InventoryError('inventory_write_failed');
        }
        echo 'Inventory report: .tmp/inventory/', basename($directory), "/inventory.json\n";
        $counts = array_count_values(array_column($report['scopes'],'status'));
        ksort($counts);
        echo 'Scope results: ', json_encode($counts,JSON_THROW_ON_ERROR), "\n";
        return $report['complete'];
    }
}
