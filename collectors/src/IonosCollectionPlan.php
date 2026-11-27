<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
use CollectionPlan;
use Collector;
use Orchestrator;
use RuntimeException;

/** Run a REST reconciliation step on the validated snapshot in the Base synchronization pass. */
abstract class InventoryPhaseCollector extends Collector
{
    /** @var array<class-string,?Closure> Per-phase operations for this execution. */
    private static array $operations = [];

    /** Bind the operation once for the instances constructed by the Base orchestrator. */
    public static function setOperation(?Closure $operation): void
    {
        self::$operations[static::class] = $operation;
    }

    /** The parent collectors already supplied the inventory. This phase has no CSV definition. */
    public function Init(): void {}

    /** This phase writes through the same native REST connection as the VDC link import. */
    public function InitSynchroDataSource($aPlaceholders): bool { return true; }

    /** The complete network inventory was collected before orchestration. */
    public function Collect($iMaxChunkSize = 0): bool { return true; }

    /** Run the configured operation and propagate failures to the Base entry point. */
    public function Synchronize($iMaxChunkSize = 0): bool
    {
        $operation = self::$operations[static::class] ?? throw new RuntimeException('Missing network synchronization context.');
        $operation();
        return true;
    }
}

/** Prepare IPv4 host objects in TeemIP before NAT assignments are imported. */
final class NatIpv4Collector extends InventoryPhaseCollector {}

/** Prepare IPv6 host objects when the target also has IPv6 management installed. */
final class NatIpv6Collector extends InventoryPhaseCollector {}

/** Import gateways, LAN attachments and assignments once their prerequisites exist. */
final class NatGatewayCollector extends InventoryPhaseCollector {}

/** Import network load balancers after their Cloud and LANs are available. */
final class NlbCollector extends InventoryPhaseCollector {}

/** Reconcile selected regional VPN lists after Cloud and LAN synchronization. */
final class VpnCollector extends InventoryPhaseCollector {}

/** Use the Base sequence sorter and enforce the dependencies of each enabled IONOS phase. */
final class IonosCollectionPlan extends CollectionPlan
{
    /** Whether the target model represents NAT addresses with TeemIP references. */
    public static bool $useIpam;

    /** Whether the target has the IPv6 management module. */
    public static bool $useIpv6;

    public static bool $useVpn = false;

    /** Register each enabled collector at its requested position after its dependencies. */
    public function AddCollectorsToOrchestrator(): bool
    {
        $classes = [
            'BRIonosVdcCollector' => VdcCollector::class,
            'BRIonosLanCollector' => LanCollector::class,
            'BRIonosNatIpv4Collector' => NatIpv4Collector::class,
            'BRIonosNatIpv6Collector' => NatIpv6Collector::class,
            'BRIonosNatGatewayCollector' => NatGatewayCollector::class,
            'BRIonosNlbCollector' => NlbCollector::class,
            'BRIonosVpnCollector' => VpnCollector::class,
        ];
        $dependencies = [
            'BRIonosVdcCollector' => [],
            'BRIonosLanCollector' => ['BRIonosVdcCollector'],
            'BRIonosNatIpv4Collector' => ['BRIonosVdcCollector'],
            'BRIonosNatIpv6Collector' => ['BRIonosVdcCollector'],
            'BRIonosNatGatewayCollector' => ['BRIonosLanCollector'],
            'BRIonosNlbCollector' => ['BRIonosLanCollector'],
            'BRIonosVpnCollector' => ['BRIonosLanCollector'],
        ];
        if (self::$useIpam) {
            $dependencies['BRIonosNatGatewayCollector'][] = 'BRIonosNatIpv4Collector';
            if (self::$useIpv6) {
                $dependencies['BRIonosNatGatewayCollector'][] = 'BRIonosNatIpv6Collector';
            }
        }
        $registered = [];
        foreach ($this->GetSortedLaunchSequence() as $entry) {
            $name = $entry['name'] ?? '';
            if (!isset($classes[$name]) || !in_array($entry['enable'] ?? '', ['yes', 'no'], true)
                || !ctype_digit((string) $entry['rank'])) {
                throw new RuntimeException('Invalid entry in collectors_launch_sequence.');
            }
            if ($entry['enable'] === 'no' || ($name === 'BRIonosVpnCollector' && !self::$useVpn)
                || ($name === 'BRIonosNatIpv4Collector' && !self::$useIpam)
                || ($name === 'BRIonosNatIpv6Collector' && !(self::$useIpam && self::$useIpv6))) {
                continue;
            }
            if (isset($registered[$name]) || array_diff($dependencies[$name], array_keys($registered))) {
                throw new RuntimeException("Check the order and enabled prerequisites for $name in collectors_launch_sequence.");
            }
            $registered[$name] = true;
            Orchestrator::AddCollector(count($registered), $classes[$name]);
        }
        return true;
    }
}
