<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\Inventory;
use BR\IonosCloud\Collector\InventoryError;
use BR\IonosCloud\Collector\VpnInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../collectors/src/IonosApi.php';
require_once __DIR__.'/../../collectors/src/Inventory.php';
require_once __DIR__.'/../../collectors/src/VpnInventory.php';

final class VpnInventoryTest extends TestCase
{
    private const BASE = 'https://vpn.de-txl.ionos.com';
    private const VDC = 'aaaa0000-0000-4000-8000-123456789abc';
    private const OTHER = 'bbbb0000-0000-4000-8000-123456789abc';

    private static function wireGuard(): array
    {
        return ['id' => 'gateway-1', 'metadata' => ['status' => 'AVAILABLE', 'publicKey' => 'discarded'],
            'properties' => ['name' => 'WireGuard fixture', 'gatewayIP' => '203.0.113.11', 'listenPort' => 51820,
                'interfaceIPv4CIDR' => '172.16.0.1/30', 'interfaceIPv6CIDR' => '2001:DB8::6/64',
                'connections' => [['datacenterId' => self::VDC, 'lanId' => '2', 'ipv4CIDR' => '10.20.0.8/24',
                    'ipv6CIDR' => '2001:db8:20::8/64', 'privateKey' => 'discarded']],
                'peers' => ['discarded']]];
    }

    private static function plan(string ...$paths): array
    {
        return ['vpn' => ['bases' => [self::BASE], 'resources' => array_fill_keys($paths, ['offset' => true])]];
    }

    public function testProjectsOnlyGatewayFieldsAndKeepsAddressRolesAndPrefixes(): void
    {
        $report = (new Inventory(static fn () => ['totalCount' => 1, 'items' => [self::wireGuard()]]))
            ->collect(self::plan('/wireguardgateways'));
        self::assertTrue($report['complete']);
        self::assertStringNotContainsString('discarded', json_encode($report, JSON_THROW_ON_ERROR));
        $rows = VpnInventory::gateways($report['scopes'][0], [self::VDC => true, self::OTHER => true],
            [self::VDC => [2 => true], self::OTHER => [2 => true]]);
        self::assertCount(1, $rows);
        self::assertSame(self::VDC, $rows[0]['cloud']);
        self::assertSame('203.0.113.11', $rows[0]['endpoint']);
        self::assertSame(['172.16.0.1' => 30, '2001:db8::6' => 64], $rows[0]['interface']);
        self::assertSame([2 => ['10.20.0.8' => 24, '2001:db8:20::8' => 64]], $rows[0]['attachments']);
        self::assertSame('AVAILABLE', $rows[0]['fields']['ionos_status']);
        self::assertSame(51820, $rows[0]['fields']['listen_port']);
    }

    public function testFailedSecondPageRetainsIndependentScopeAndPartialEvidence(): void
    {
        $requests = [];
        $read = static function (string $base, string $path, array $query) use (&$requests): array {
            self::assertSame(self::BASE, $base);
            $requests[] = [$path, $query['offset']];
            if ($path === '/ipsecgateways' && $query['offset'] === 1) {
                throw new InventoryError('forbidden', 403);
            }
            return ['totalCount' => $path === '/ipsecgateways' ? 2 : 1, 'items' => [self::wireGuard()]];
        };
        $report = (new Inventory($read, 1))->collect(self::plan('/ipsecgateways', '/wireguardgateways'));
        self::assertFalse($report['complete']);
        self::assertSame([['/ipsecgateways', 0], ['/ipsecgateways', 1], ['/wireguardgateways', 0]], $requests);
        self::assertSame([false, true], array_column($report['scopes'], 'complete'));
        self::assertSame(['forbidden', 'present'], array_column($report['scopes'], 'status'));
        self::assertSame([1, 1], array_column($report['scopes'], 'pages'));
    }

    public static function invalidAttachments(): iterable
    {
        yield 'missing' => [[], 'missing or invalid LAN connections'];
        yield 'wrong VDC' => [[['datacenterId' => self::OTHER, 'lanId' => '3']], 'unresolved or duplicate LAN connection'];
        yield 'mixed VDCs' => [[['datacenterId' => self::VDC, 'lanId' => '2'],
            ['datacenterId' => self::OTHER, 'lanId' => '2']], 'outside one observed VDC'];
        yield 'wrong address family' => [[['datacenterId' => self::VDC, 'lanId' => '2',
            'ipv4CIDR' => '2001:db8::1/64']], 'invalid ipv4CIDR'];
        yield 'network prefix missing' => [[['datacenterId' => self::VDC, 'lanId' => '2',
            'ipv6CIDR' => '2001:db8::1']], 'invalid ipv6CIDR'];
    }

    #[DataProvider('invalidAttachments')]
    public function testInvalidConnectionsIdentifyTheAffectedGateway(array $connections, string $reason): void
    {
        $wireguard = self::wireGuard();
        $wireguard['properties']['connections'] = $connections;
        $report = (new Inventory(static fn () => ['totalCount' => 1, 'items' => [$wireguard]]))
            ->collect(self::plan('/wireguardgateways'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Gateway gateway-1 .*'.preg_quote($reason, '/').'/');
        VpnInventory::gateways($report['scopes'][0], [self::VDC => true, self::OTHER => true],
            [self::VDC => [2 => true, 3 => true], self::OTHER => [2 => true]]);
    }
}
