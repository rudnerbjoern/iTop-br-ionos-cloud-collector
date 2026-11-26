<?php

/** Integrate the virtual datacenter import with Combodo Data Collector Base 1.5.1. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector {
    require_once __DIR__ . '/InventorySyncCollector.php';

    /** Supply a VDC snapshot with the attributes installed on the target. */
    abstract class VdcCollectorBase extends InventorySyncCollector
    {
        /** @var \Closure|null Apply source-owned IP associations once all parent Clouds exist. */
        private static ?\Closure $syncLinks = null;

        /**
         * Accept a fully validated VDC snapshot or a configuration-only context.
         *
         * @param list<array<string,string|int|null>> $rows Resolved rows for the selected VDCs.
         */
        public static function setContext(array $rows, string $sourceName, ?\Closure $syncLinks = null): void
        {
            parent::setSnapshot($rows, $sourceName);
            self::$syncLinks = $syncLinks;
        }

        /** Use the stable VDC UUID as its replica identity. */
        protected function replicaKey(array $row): ?string
        {
            return self::isVdcId($row['provider_resource_id']) ? $row['provider_resource_id'] : null;
        }

        /** Locate the source template independently of the PHP namespace. */
        public function GetSynchroDataSourceDefinitionFile(): string
        {
            return dirname(__DIR__) . '/VdcCollector.json';
        }

        /** Reconcile individual IPv6 links only after every VDC was synchronized successfully. */
        public function Synchronize($iMaxChunkSize = 0): bool
        {
            parent::Synchronize($iMaxChunkSize);
            if (self::$syncLinks !== null) {
                (self::$syncLinks)();
            }
            return true;
        }
    }
}

namespace {
    /** Base 1.5.1 uses get_class() literally for native CSV file names and glob patterns. */
    final class BRIonosVdcCollector extends \BR\IonosCloud\Collector\VdcCollectorBase
    {
    }

    class_alias(BRIonosVdcCollector::class, 'BR\IonosCloud\Collector\VdcCollector');
}
