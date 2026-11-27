<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RestClient;
use RuntimeException;
use Throwable;
use Utils;

require_once __DIR__ . '/InventorySyncCollector.php';
require_once __DIR__ . '/VmSync.php';

/** Configure the VM source in Base and reconcile only the rows authorized for this import. */
final class VmCollector extends InventorySyncCollector
{
    private static array $scopes;

    private static array $inventoryErrors;

    private static \Closure $resolveCloud;

    public static function setContext(array $scopes, array $errors, string $source, callable $resolveCloud): void
    {
        parent::setSnapshot([], $source);
        self::$scopes = $scopes;
        self::$inventoryErrors = $errors;
        self::$resolveCloud = \Closure::fromCallable($resolveCloud);
    }

    public function GetSynchroDataSourceDefinitionFile(): string
    {
        return dirname(__DIR__) . '/VmCollector.json';
    }

    protected function replicaKey(array $row): ?string
    {
        return isset($row['primary_key']) && ctype_digit($row['primary_key']) ? $row['primary_key'] : null;
    }

    public function Collect($iMaxChunkSize = 0): bool
    {
        // Decisions and CSV generation require the synchronized Cloud identities.
        return true;
    }

    public function Synchronize($iMaxChunkSize = 0): bool
    {
        if ((int) $this->iSourceId < 1) {
            throw new RuntimeException('The native IONOS VM synchronization source is unavailable.');
        }
        $client = new RestClient();
        foreach (self::$inventoryErrors as $error) {
            Utils::Log(LOG_ERR, $error);
        }
        $sync = new VmSync($client->Get(...), $client->Create(...), self::$resolveCloud, $this->import(...),
            static fn (string $error) => Utils::Log(LOG_ERR, $error));
        if (array_filter(self::$scopes) !== []) {
            $this->verifySource($client);
        }
        $sync->apply(self::$scopes, $this->sSourceName, (int) $this->iSourceId);
        if (self::$inventoryErrors !== []) {
            throw new RuntimeException('Some IONOS server listings could not be synchronized.');
        }
        return true;
    }

    private function verifySource(RestClient $client): void
    {
        $expected = json_decode($this->GetSynchroDataSourceDefinition(), true, flags: JSON_THROW_ON_ERROR);
        try {
            $result = $client->Get('SynchroDataSource', (int) $this->iSourceId);
            $current = $result['objects']['SynchroDataSource::' . $this->iSourceId]['fields'] ?? null;
            if (($result['code'] ?? null) !== 0 || !is_array($current) || !is_array($current['attribute_list'] ?? null)
                || !RestClient::GetFullSynchroDataSource($current, $this->iSourceId)) {
                throw new RuntimeException('Cannot read the native VM source policies.');
            }
        } catch (Throwable) {
            throw new RuntimeException('Cannot verify the native VM synchronization source.');
        }
        foreach (['name', 'scope_class', 'scope_restriction', 'status', 'full_load_periodicity', 'delete_policy',
            'reconciliation_policy', 'action_on_zero', 'action_on_one', 'action_on_multiple'] as $field) {
            if ((string) ($current[$field] ?? null) !== (string) $expected[$field]) {
                throw new RuntimeException("Unexpected VM synchronization source policy $field.");
            }
        }
        $wanted = array_column($expected['attribute_list'], null, 'attcode');
        $seen = [];
        foreach ($current['attribute_list'] as $attribute) {
            $code = $attribute['attcode'];
            if (isset($seen[$code]) || !empty($attribute['reconcile'])
                || (bool) $attribute['update'] !== isset($wanted[$code])
                || (isset($wanted[$code]) && array_diff_assoc($wanted[$code], $attribute) !== [])) {
                throw new RuntimeException("Unexpected VM synchronization attribute policy for $code.");
            }
            $seen[$code] = true;
        }
        if (array_diff_key($wanted, $seen) !== []) {
            throw new RuntimeException('The native VM synchronization source is missing managed attributes.');
        }
    }

    private function import(string $csv, int $expectedRows): void
    {
        $login = Utils::GetLoginMode();
        try {
            $response = self::CallItopViaHttp("/synchro/synchro_import.php?login_mode=$login", [
                'data_source_id' => $this->iSourceId, 'synchronize' => '1', 'no_stop_on_import_error' => '0',
                'output' => 'summary', 'charset' => 'UTF-8', 'separator' => ';', 'csvdata' => $csv,
            ]);
        } catch (Throwable) {
            throw new RuntimeException('The native IONOS VM import request failed.');
        }
        $summary = strip_tags($response);
        $counts = [];
        foreach (['Created (before synchro)', 'Updated (before synchro)', 'Issues (before synchro)',
            'Replicas touched since last synchro', 'Objects reconciliation errors', 'Objects update errors',
            'Objects creation errors', 'Objects created'] as $field) {
            if (!preg_match('/#' . preg_quote($field, '/') . ':\s*([0-9]+)\b/', $summary, $match)) {
                throw new RuntimeException("The native VM import summary is missing $field.");
            }
            $counts[] = (int) $match[1];
        }
        [$created, $updated, $issues, $seen, $reconciliationErrors, $updateErrors, $creationErrors, $objectsCreated] = $counts;
        if ($created + $updated !== $expectedRows || $seen !== $expectedRows || $issues || $reconciliationErrors
            || $updateErrors || $creationErrors || $objectsCreated) {
            throw new RuntimeException('iTop did not process exactly the expected VMs in the native import.');
        }
    }
}
