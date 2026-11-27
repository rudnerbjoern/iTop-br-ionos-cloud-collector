<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\LanSync;
use BR\IonosCloud\Collector\Tests\Support\RestResponse;
use BR\IonosCloud\Collector\VdcResolver;
use BR\IonosCloud\Collector\VdcSync;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../collectors/src/LanSync.php';
require_once __DIR__ . '/../../collectors/src/VdcSync.php';
require_once __DIR__ . '/../Support/RestResponse.php';

/** Check target capabilities and mapping from inventory scopes to VDC and LAN rows. */
final class VdcMappingTest extends TestCase
{
    private const BASE = 'br-cloud-datamodel';
    private const IONOS = 'br-ionos-cloud-datamodel';
    private const BRIDGE = 'br-cloud-datamodel-bridge-for-teemip';
    private const IPV6 = 'teemip-ipv6-mgmt';
    private const UUID = '1b376ba8-5f5d-40bf-a82b-dfb9eefa0561';
    private const ITEM = ['id' => self::UUID, 'attributes' => ['name' => 'VDC', 'location' => 'de/txl']];
    private const VDC_SCOPE = ['product' => 'compute', 'template' => '/datacenters', 'complete' => true, 'items' => [self::ITEM]];
    private const PARENT = ['provider_resource_id' => self::UUID, 'org_id' => 27];
    private const LAN_SCOPE = [
        'product' => 'compute', 'template' => '/datacenters/{datacenterId}/lans',
        'path' => '/datacenters/' . self::UUID . '/lans', 'status' => 'present',
        'complete' => true, 'items' => [['id' => '1', 'attributes' => ['public' => false]]],
    ];

    /** @return array<string,mixed> A single native module installation record. */
    private static function module(string $name, ?string $version = null): array
    {
        return ['code' => 0, 'class' => 'ModuleInstallation', 'fields' => [
            'name' => $name, 'version' => $version ?? ($name === self::IONOS ? '0.4.0' : '0.3.0'),
            'installed' => '2026-11-26 07:15:00',
        ]];
    }

    /**
     * Supply a valid current installation and the given module lookup reply.
     * @param array<string,mixed> $reply Response for the list of installed target modules.
     */
    private static function moduleLookup(array $reply): callable
    {
        return static fn ($class, $query) => is_array($query)
            ? ['code' => 0, 'objects' => [self::module('datamodel')]] : $reply;
    }

    /** @return iterable<string,array{list<array>,bool}> Combinations of optional target modules. */
    public static function optionalModules(): iterable
    {
        $bridge = self::module(self::BRIDGE);
        $ipv6 = self::module(self::IPV6, '3.3.1');
        yield 'neither installed' => [[], false];
        yield 'only bridge' => [[$bridge], false];
        yield 'only IPv6' => [[$ipv6], false];
        yield 'both installed' => [[$bridge, $ipv6], true];
    }

    /** @param list<array> $extra Optional module installations alongside the required modules. */
    #[DataProvider('optionalModules')]
    public function testIpv6LinkSupportRequiresBridgeAndIpv6Module(array $extra, bool $supported): void
    {
        $lookup = self::moduleLookup(['code' => 0, 'objects' => [self::module(self::BASE), self::module(self::IONOS), ...$extra]]);
        self::assertSame($supported, VdcSync::canSyncIpv6Links($lookup));
    }

    /** @return iterable<string,array{array}> Incomplete, ambiguous or incompatible installation results. */
    public static function invalidModuleReplies(): iterable
    {
        $base = self::module(self::BASE);
        $ionos = self::module(self::IONOS);
        $bridge = self::module(self::BRIDGE);
        yield 'failed request' => [['code' => 1]];
        yield 'only bridge installed' => [['code' => 0, 'objects' => [$bridge]]];
        yield 'missing provider module' => [['code' => 0, 'objects' => [$base, $bridge]]];
        yield 'obsolete provider module' => [['code' => 0, 'objects' => [$base, self::module(self::IONOS, '0.1.0')]]];
        yield 'obsolete base module' => [['code' => 0, 'objects' => [self::module(self::BASE, '0.1.0'), $ionos]]];
        yield 'obsolete bridge' => [['code' => 0, 'objects' => [$base, $ionos, self::module(self::BRIDGE, '0.1.0')]]];
        yield 'missing base module' => [['code' => 0, 'objects' => [$ionos]]];
        yield 'duplicate base module' => [['code' => 0, 'objects' => [$base, $base, $ionos]]];
    }

    /** @param array<string,mixed> $reply Unusable target module reply. */
    #[DataProvider('invalidModuleReplies')]
    public function testRejectsInvalidTargetInstallations(array $reply): void
    {
        $this->expectException(RuntimeException::class);
        VdcSync::canSyncIpv6Links(self::moduleLookup($reply));
    }

    /** Provide only the organization and location of the test provider. */
    private static function providerResolver(): VdcResolver
    {
        return new VdcResolver(static fn ($class) => $class === 'Organization'
            ? RestResponse::one($class, 18) : RestResponse::one('Location', 10, ['org_id' => 18, 'name' => 'de/txl']));
    }

    /** Cloud rows leave the independently managed list of address associations untouched. */
    public function testCloudRowsOmitIpAssociations(): void
    {
        $rows = VdcSync::rows(['complete' => true, 'scopes' => [self::VDC_SCOPE]], self::providerResolver(), 27, 18);
        self::assertCount(1, $rows);
        self::assertArrayNotHasKey('ip_objects_list', $rows[0]);
        self::assertArrayNotHasKey('ipv6_block_id', $rows[0]);
        self::assertSame('unknown', $rows[0]['ionos_sec_auth_protection']);
    }

    /** Unobserved IPv6 properties cannot cause an implicit allocation release. */
    public function testMissingPrefixIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('omitted its IPv6 allocation');
        VdcSync::ipv6Allocations([self::ITEM], self::providerResolver(), 27);
    }

    /** Explicit null releases the allocation for this VDC. */
    public function testExplicitNullReleasesTheAllocation(): void
    {
        $item = self::ITEM;
        $item['attributes']['ipv6CidrBlock'] = null;
        self::assertSame([self::UUID => null], VdcSync::ipv6Allocations([$item], self::providerResolver(), 27));
    }

    /** A reported prefix resolves to the exact existing block. */
    public function testReportedPrefixMapsToExistingBlock(): void
    {
        $item = self::ITEM;
        $item['attributes']['ipv6CidrBlock'] = '2001:db8::/56';
        $resolver = new VdcResolver(static fn ($class) => $class === 'Organization'
            ? RestResponse::one($class, 27) : RestResponse::one('IPv6Block', 75, ['org_id' => 27]));
        self::assertSame([self::UUID => 75], VdcSync::ipv6Allocations([$item], $resolver, 27));
    }

    /** A reported allocation must have a matching block in the target organization. */
    public function testUnresolvedPrefixIsRejected(): void
    {
        $item = self::ITEM;
        $item['attributes']['ipv6CidrBlock'] = '2001:db8::/56';
        $resolver = new VdcResolver(static fn ($class) => $class === 'Organization'
            ? RestResponse::one($class, 27) : ['code' => 0, 'objects' => []]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected exactly one matching iTop IPv6Block');
        VdcSync::ipv6Allocations([$item], $resolver, 27);
    }

    /** An incomplete inventory must stop VDC row generation. */
    public function testIncompleteInventoryIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        VdcSync::rows(['complete' => false, 'scopes' => [self::VDC_SCOPE]], self::providerResolver(), 27, 18);
    }

    /** Scope the LAN primary key to its VDC and supply a fallback name for an unnamed LAN. */
    public function testUnnamedPrivateLanKeepsItsOwnerAndLocalIdentity(): void
    {
        $report = ['complete' => true, 'scopes' => [self::VDC_SCOPE, self::LAN_SCOPE]];
        self::assertSame([[
            'primary_key' => self::UUID . '/1', 'cloud_id' => self::UUID,
            'provider_resource_id' => '1', 'name' => 'LAN 1', 'org_id' => 27, 'public' => '0',
        ]], LanSync::rows($report, [self::PARENT]));
    }

    /** @return iterable<string,array{list<array>}> Examples of incomplete or inconsistent LAN scopes. */
    public static function invalidLanScopes(): iterable
    {
        yield 'missing scope' => [[]];
        yield 'duplicate scope' => [[self::LAN_SCOPE, self::LAN_SCOPE]];
        yield 'unknown parent' => [[array_replace(self::LAN_SCOPE, ['path' => '/datacenters/other/lans'])]];
        yield 'incomplete scope' => [[array_replace(self::LAN_SCOPE, ['complete' => false])]];
        yield 'missing public flag' => [[array_replace(self::LAN_SCOPE, ['items' => [['id' => '1', 'attributes' => []]]])]];
    }

    /** @param list<array> $scopes LAN inventory for the observed VDC. */
    #[DataProvider('invalidLanScopes')]
    public function testRejectsInvalidLanScopes(array $scopes): void
    {
        $this->expectException(RuntimeException::class);
        LanSync::rows(['complete' => true, 'scopes' => [self::VDC_SCOPE, ...$scopes]], [self::PARENT]);
    }
}
