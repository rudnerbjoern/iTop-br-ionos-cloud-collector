<?php

/** Share the native CSV and data source lifecycle for complete IONOS inventories. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Collector;
use RestClient;
use RuntimeException;
use Utils;

/** Manage the declared attributes of one target class from validated source rows. */
abstract class InventorySyncCollector extends Collector
{
    /** @var array<class-string,array{name:string,rows:list<array>}> Snapshot per registered collector. */
    private static array $contexts = [];

    /** @var int Index of the next validated row. */
    private int $position = 0;

    /** @var bool Whether this process successfully collected all currently prepared rows. */
    private bool $collected = false;

    /**
     * Accept a complete snapshot or an empty configuration-only context.
     *
     * @param list<array<string,string|int|null>> $rows Validated records for this source.
     */
    protected static function setSnapshot(array $rows, string $sourceName): void
    {
        if (!array_is_list($rows) || trim($sourceName) === '' || $sourceName !== trim($sourceName)) {
            throw new RuntimeException('Invalid IONOS synchronization context.');
        }
        self::$contexts[static::class] = ['name' => $sourceName, 'rows' => $rows];
    }

    /** Return the expected native replica key for a row, or null if its provider identity is invalid. */
    abstract protected function replicaKey(array $row): ?string;

    /** Recognize an IONOS VDC UUID. */
    protected static function isVdcId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/Di', $id) === 1;
    }

    /** Initialize the native field map and check every row before the Base can configure iTop. */
    public function Init(): void
    {
        parent::Init();
        $fields = ['primary_key' => true] + $this->aFields;
        $seen = [];
        foreach (self::$contexts[static::class]['rows'] as $row) {
            if (!is_array($row) || array_diff_key($fields, $row) || array_diff_key($row, $fields)
                || !is_string($row['primary_key']) || $row['primary_key'] !== $this->replicaKey($row)
                || isset($seen[strtolower($row['primary_key'])])) {
                throw new RuntimeException('The inventory has inconsistent columns or replica identities.');
            }
            $seen[strtolower($row['primary_key'])] = true;
        }
    }

    /** Render the data source with exactly the attributes installed in the target. */
    public function GetSynchroDataSourceDefinition($aPlaceHolders = []): string
    {
        $context = self::$contexts[static::class] ?? throw new RuntimeException('IONOS synchronization context is missing.');
        $this->sSynchroDataSourceDefinitionFile = $this->GetSynchroDataSourceDefinitionFile();
        $definition = json_decode(file_get_contents($this->sSynchroDataSourceDefinitionFile), true, flags: JSON_THROW_ON_ERROR);
        $definition['name'] = $context['name'];
        $definition['user_id'] = (int) ($aPlaceHolders['$synchro_user$'] ?? 0);
        $definition['notify_contact_id'] = (int) ($aPlaceHolders['$contact_to_notify$'] ?? 0);
        return json_encode($definition, JSON_THROW_ON_ERROR);
    }

    /** Return the collector version packaged alongside the Base. */
    public function GetVersion(): string
    {
        $path = dirname(__DIR__) . '/VERSION';
        return is_file($path) ? trim(file_get_contents($path)) : 'development';
    }

    /** Clear old Base CSV chunks and refuse to continue if any survived the cleanup. */
    protected function Prepare(): bool
    {
        $this->position = 0;
        $this->collected = false;
        parent::Prepare();
        foreach (['-*.csv', '.raw-*.csv'] as $pattern) {
            if (glob(Utils::GetDataFilePath(get_class($this) . $pattern)) !== []) {
                throw new RuntimeException('Could not clear the previous IONOS import files.');
            }
        }
        return true;
    }

    /** Return the next validated row or signal the end of this snapshot. */
    protected function Fetch(): array|false
    {
        return self::$contexts[static::class]['rows'][$this->position++] ?? false;
    }

    /** Record success only after the Base has written and closed all chunks. */
    public function Collect($iMaxChunkSize = 0): bool
    {
        $this->collected = parent::Collect($iMaxChunkSize);
        return $this->collected;
    }

    /** Synchronize fresh chunks only. A confirmed empty inventory leaves existing CIs in place. */
    public function Synchronize($iMaxChunkSize = 0): bool
    {
        if (!$this->collected) {
            throw new RuntimeException('IONOS synchronization requires a successful collection in this process.');
        }
        if (self::$contexts[static::class]['rows'] !== [] && !parent::Synchronize($iMaxChunkSize)) {
            throw new RuntimeException('IONOS synchronization failed.');
        }
        return true;
    }

    /**
     * Correct any unmanaged attribute that the target still marks for update or reconciliation.
     * iTop enables all writable attributes when it initially generates a source's attribute list.
     */
    protected function DataSourcesAreEquivalent($expected, $current): bool
    {
        if (($current['scope_class'] ?? null) !== $expected['scope_class']) {
            throw new RuntimeException('The IONOS source name is already assigned to a different target class.');
        }
        $managed = array_column($expected['attribute_list'], 'attcode');
        foreach ($current['attribute_list'] as $attribute) {
            if (!in_array($attribute['attcode'], $managed, true)
                && (!empty($attribute['update']) || !empty($attribute['reconcile']))) {
                return false;
            }
        }
        return parent::DataSourcesAreEquivalent($expected, $current);
    }

    /**
     * Explicitly disable unmanaged fields in the current source.
     *
     * @param array $current Generated native SynchroAttribute metadata for this source.
     * @param array $expected Managed attributes declared in the selected JSON definition.
     */
    protected function UpdateSDSAttributes($current, $expected, $comment, ?RestClient $client = null): bool
    {
        $managed = array_column($expected, 'attcode');
        foreach ($current as $attribute) {
            if (!in_array($attribute['attcode'], $managed, true)
                && (($attribute['update'] ?? null) != '0' || ($attribute['reconcile'] ?? null) != '0')) {
                $expected[] = [
                    'attcode' => $attribute['attcode'], 'finalclass' => $attribute['finalclass'],
                    'update' => '0', 'reconcile' => '0',
                ];
            }
        }
        return parent::UpdateSDSAttributes($current, $expected, $comment, $client);
    }
}
