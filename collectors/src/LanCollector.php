<?php

/** Import the LANs belonging to each IONOS virtual datacenter. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector {
    use Closure;
    use RuntimeException;

    require_once __DIR__ . '/InventorySyncCollector.php';

    /** Reconcile each LAN on its parent Cloud and VDC-local LAN ID. */
    abstract class LanCollectorBase extends InventorySyncCollector
    {
        /** @var Closure(string):int Resolve a parent VDC UUID after the Cloud source has synchronized. */
        private static Closure $resolveCloud;

        /** @var array<string,int> Resolved iTop Cloud IDs for this run. */
        private array $clouds = [];

        /** @var int Position of the parent key in the current CSV chunk. */
        private int $cloudColumn;

        /**
         * Accept all LANs in the selected VDCs or a configuration-only context.
         *
         * @param list<array<string,string|int>> $rows LAN rows with the parent Cloud's IONOS UUID.
         * @param Closure(string):int $resolveCloud Look up one VDC in the configured provider and customer.
         */
        public static function setContext(array $rows, string $sourceName, Closure $resolveCloud): void
        {
            parent::setSnapshot($rows, $sourceName);
            self::$resolveCloud = $resolveCloud;
        }

        /** Use the native Base lookup pass after the VDC source has created or updated the parents. */
        protected function MustProcessBeforeSynchro(): bool
        {
            return true;
        }

        /**
         * Replace the source UUID with the scoped iTop primary key before any LAN chunk is uploaded.
         *
         * @param array $aLineData Ordered cells in the raw Base CSV chunk.
         * @param int $iLineIndex Zero for the header of each chunk.
         */
        protected function ProcessLineBeforeSynchro(&$aLineData, $iLineIndex): void
        {
            if ($iLineIndex === 0) {
                $column = array_search('cloud_id', $aLineData, true);
                if ($column === false) {
                    throw new RuntimeException('The LAN import is missing its parent Cloud column.');
                }
                $this->cloudColumn = $column;
                return;
            }
            $uuid = $aLineData[$this->cloudColumn] ?? null;
            if (!self::isVdcId($uuid)) {
                throw new RuntimeException('Invalid VDC UUID in the LAN import.');
            }
            $aLineData[$this->cloudColumn] = $this->clouds[$uuid] ??= (self::$resolveCloud)($uuid);
        }

        /** Use the VDC UUID and local LAN ID together for the replica identity. */
        protected function replicaKey(array $row): ?string
        {
            $cloud = $row['cloud_id'];
            $lan = $row['provider_resource_id'];
            if (!self::isVdcId($cloud) || !is_string($lan) || $lan === '') {
                return null;
            }
            return $cloud . '/' . $lan;
        }

        /** Locate the source template independently of the PHP namespace. */
        public function GetSynchroDataSourceDefinitionFile(): string
        {
            return dirname(__DIR__) . '/LanCollector.json';
        }
    }
}

namespace {
    /** Base 1.5.1 uses get_class() literally for native CSV file names and glob patterns. */
    final class BRIonosLanCollector extends \BR\IonosCloud\Collector\LanCollectorBase
    {
    }

    class_alias(BRIonosLanCollector::class, 'BR\IonosCloud\Collector\LanCollector');
}
