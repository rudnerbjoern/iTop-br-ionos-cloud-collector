<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\Inventory;
use BR\IonosCloud\Collector\NlbInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../collectors/src/Inventory.php';
require_once __DIR__ . '/../../collectors/src/IonosApi.php';
require_once __DIR__ . '/../../collectors/src/NlbInventory.php';

final class NlbInventoryTest extends TestCase
{
    private const VDC = 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa';
    private const NLB = 'bbbbbbbb-bbbb-4bbb-bbbb-bbbbbbbbbbbb';
    private const PROPERTIES = [
        'name' => 'Network load balancer', 'listenerLan' => 6, 'targetLan' => 7,
        'ips' => ['203.0.113.10'], 'lbPrivateIps' => ['10.20.0.7/24', '2001:0db8::8/64'],
    ];

    public function testProjectionRetainsDistinctRolesAndCanonicalPrefixes(): void
    {
        [$balancer] = self::read(self::PROPERTIES);
        self::assertSame('6', $balancer['listener_lan']);
        self::assertSame('7', $balancer['backend_lan']);
        self::assertTrue($balancer['listener_public']);
        self::assertSame(['203.0.113.10' => null], $balancer['listener_ips']);
        self::assertSame(['10.20.0.7' => 24, '2001:db8::8' => 64], $balancer['backend_ips']);
    }

    public static function malformedProperties(): iterable
    {
        yield 'listener list absent from properties' => ['ips', null];
        yield 'listener address with prefix' => ['ips', ['203.0.113.10/32']];
        yield 'backend without prefix' => ['lbPrivateIps', ['10.20.0.7']];
        yield 'backend host repeated with different prefixes' => ['lbPrivateIps', ['10.20.0.7/24', '10.20.0.7/25']];
        yield 'target LAN belongs to another VDC' => ['targetLan', 8];
    }

    #[DataProvider('malformedProperties')]
    public function testRejectsIncompleteOrMalformedProperties(string $field, mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        self::read(array_replace(self::PROPERTIES, [$field => $value]));
    }

    public function testIncompleteParentCrossCheckPreventsImport(): void
    {
        $this->expectExceptionMessage('incomplete IONOS inventory');
        self::read(self::PROPERTIES, false);
    }

    /** Project a real VDC collection and compare its NLB IDs with the embedded references. */
    private static function read(array $properties, bool $confirmed = true): array
    {
        $vdc = '/datacenters/' . self::VDC;
        $get = static fn (string $base, string $path): array => match ($path) {
            '/datacenters' => ['items' => [['id' => self::VDC]]],
            "$vdc/networkloadbalancers" => ['items' => [['id' => self::NLB, 'properties' => $properties, 'ips' => []]]],
            $vdc => ['entities' => ['networkloadbalancers' => ['items' => $confirmed ? [['id' => self::NLB]] : []]]],
        };
        $report = (new Inventory($get))->collect(['compute' => [
            'bases' => ['https://example.invalid'],
            'resources' => ['/datacenters' => ['offset' => false],
                '/datacenters/{datacenterId}/networkloadbalancers' => ['offset' => true, 'verifyEmbedded' => 'networkloadbalancers']],
        ]]);
        return NlbInventory::loadBalancers($report, [['provider_resource_id' => self::VDC, 'org_id' => 4]], [
            ['cloud_id' => self::VDC, 'provider_resource_id' => '6', 'public' => '1'],
            ['cloud_id' => self::VDC, 'provider_resource_id' => '7', 'public' => '0'],
            ['cloud_id' => self::NLB, 'provider_resource_id' => '8', 'public' => '0'],
        ]);
    }
}
