<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\Inventory;
use BR\IonosCloud\Collector\InventoryError;
use BR\IonosCloud\Collector\VmInventory;
use BR\IonosCloud\Collector\VmReconciler;
use BR\IonosCloud\Collector\VmSync;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../collectors/src/Run.php';
require_once __DIR__ . '/../../collectors/src/VmSync.php';

final class VmSyncTest extends TestCase
{
    private const VDC = '75e9542b-a995-4e65-afad-27cd64ba18da';
    private const OTHER = '54a4aa66-9a8e-4bd9-8086-678e4247a80e';
    private const SERVER = '7dafe90e-5696-4bfb-bddf-69923e126a79';
    private const SOURCE = 'IONOS VMs - org 5 - provider 8';
    private const PATH = '/datacenters/' . self::VDC . '/servers';

    private static function fields(): array
    {
        return ['name' => 'Synthetic VM', 'org_id' => 5, 'virtualhost_id' => 20, 'ionos_vdc_id' => self::VDC,
            'ionos_server_id' => self::SERVER, 'ionos_source_key' => self::SOURCE, 'ionos_server_type' => 'ENTERPRISE',
            'cpu' => null, 'ram' => null];
    }

    private static function vm(array $override, string $class = 'VirtualMachine'): array
    {
        return ['class' => $class, 'fields' => $override + self::fields()];
    }

    private static function reply(string $class, array $fieldsById): array
    {
        $objects = [];
        foreach ($fieldsById as $key => $fields) {
            $objects["$class::$key"] = ['class' => $class, 'code' => 0, 'key' => $key, 'fields' => $fields];
        }
        return ['code' => 0, 'message' => 'Found: ' . count($objects), 'objects' => $objects ?: null];
    }

    public function testInventoryReadsAuthoritativeServerPropertiesAndIsolatesIncompletePage(): void
    {
        $options = ['offset' => true, 'serverSync' => true];
        $get = static function ($base, $path, $query): array {
            if ($path === '/datacenters') {
                return ['items' => [['id' => self::VDC], ['id' => self::OTHER]]];
            }
            if ($path === self::PATH && $query['offset'] === 1) {
                throw new InventoryError('synthetic_page_failure');
            }
            return ['items' => [['id' => self::SERVER, 'type' => 'server', 'ram' => 4096,
                'properties' => ['type' => 'CUBE', 'name' => 'Fixture server', 'cores' => 4]]],
                'totalCount' => $path === self::PATH ? 2 : 1];
        };
        $inventory = new Inventory($get, 1);
        $report = $inventory->collect(['compute' => ['bases' => ['https://api.example.test'],
            'resources' => ['/datacenters' => ['offset' => false], VmInventory::TEMPLATE => $options]]]);
        $errors = [];
        $scopes = VmInventory::scopes($report,
            [['provider_resource_id' => self::VDC, 'org_id' => 5], ['provider_resource_id' => self::OTHER, 'org_id' => 5]],
            static function ($error) use (&$errors): void { $errors[] = $error; });
        self::assertStringContainsString('synthetic_page_failure', $errors[0]);
        self::assertSame([self::OTHER], array_keys($scopes));
        $vm = $scopes[self::OTHER][0];
        self::assertSame('CUBE', $vm['ionos_server_type']);
        self::assertSame('4', $vm['cpu']);
        self::assertNull($vm['ram']);
    }

    public function testNativeOwnerWithLostIdentityBlocksAnOtherwiseEligibleReservation(): void
    {
        $identity = self::VDC . '/' . self::SERVER;
        $blank = array_fill_keys(['ionos_vdc_id', 'ionos_server_id', 'ionos_source_key'], '');
        $vms = [16 => self::vm($blank), 17 => self::vm($blank)];
        $result = VmReconciler::plan([$identity => self::fields()], $vms, [16 => [8]], sourceId: 8);
        self::assertSame([], $result['rows']);
        self::assertStringContainsString('incomplete provider identity', $result['errors'][0]);
    }

    public function testReservationMustBeExactNativeClassAndHaveNoForeignOwnerOrProviderIdentity(): void
    {
        $identity = self::VDC . '/' . self::SERVER;
        $blank = array_fill_keys(['ionos_vdc_id', 'ionos_server_id', 'ionos_source_key'], '');
        $vms = [17 => self::vm($blank, 'ExtendedVirtualMachine'), 18 => self::vm($blank),
            19 => self::vm(['ionos_source_key' => 'External identity'] + $blank)];
        $result = VmReconciler::plan([$identity => self::fields()], $vms, [18 => [9]], sourceId: 8);
        self::assertSame([], $result['errors']);
        self::assertNull($result['rows'][$identity]['target']);
    }

    public function testInterruptedCreateRecoversExistingIdentityAndWaitsForDatabaseSecondBoundary(): void
    {
        $fields = self::fields();
        $fences = 0;
        $imported = false;
        $get = static function ($class, $query) use ($fields, &$fences, &$imported): array {
            if ($class === 'VirtualMachine') {
                return self::reply($class, [40 => $fields]);
            }
            if (str_contains($query, 'NOW()')) {
                return self::reply($class, ++$fences === 1 ? [70 => ['sync_source_id' => 8]] : []);
            }
            return self::reply($class, $imported
                ? [71 => ['sync_source_id' => 8, 'dest_id' => 40, 'dest_class' => 'VirtualMachine',
                    'status' => 'synchronized', 'status_last_error' => '']] : []);
        };
        $sync = new VmSync($get,
            static fn () => self::fail('A provisional VM already exists.'),
            static fn () => 20,
            static function ($csv, $count) use (&$fences, &$imported): void {
                self::assertSame(1, $count);
                self::assertStringContainsString("\n40;", $csv);
                self::assertSame(3, $fences);
                $imported = true;
            },
            static fn ($message) => self::fail($message));
        unset($fields['virtualhost_id'], $fields['ionos_source_key']);
        $sync->apply([self::VDC => [$fields]], self::SOURCE, 8);
        self::assertTrue($imported);
    }

    public function testIncompleteNativeOwnershipReadStopsBeforeAnyMutation(): void
    {
        $fields = self::fields();
        $get = static fn ($class) => $class === 'VirtualMachine' ? self::reply($class, [40 => $fields])
            : ['code' => 0, 'message' => 'Found: 2', 'objects' => null];
        $sync = new VmSync($get, static fn () => self::fail('Unexpected provisional create.'),
            static fn () => 20, static fn () => self::fail('Unexpected import.'), static fn ($message) => self::fail($message));
        unset($fields['virtualhost_id'], $fields['ionos_source_key']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Incomplete iTop SynchroReplica reconciliation response.');
        $sync->apply([self::VDC => [$fields]], self::SOURCE, 8);
    }
}
