<?php

/** Exercise regional VPN lists through the actual Base CLI and installed iTop REST model. */
declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests;

use DBObjectSearch;
use DBObjectSet;
use MetaModel;

function verifyVpnImport(string $dir, string $uuid, int $cloud, int $otherCloud, int $org, bool $bridge): void
{
    $gatewayId = '6b3f6803-be6e-4247-8431-1031ff09f688';
    $lans = [
        ['id' => '1', 'properties' => ['name' => 'Original LAN', 'public' => false]],
        ['id' => '2', 'properties' => ['name' => 'VPN LAN 2', 'public' => false]],
        ['id' => '3', 'properties' => ['name' => 'VPN LAN 3', 'public' => false]],
    ];
    setEndpoint($dir, "/datacenters/$uuid/networkloadbalancers", []);
    setEndpoint($dir, "/datacenters/$uuid/lans", $lans);
    setEndpoint($dir, '/datacenters/'.SECOND_VDC.'/lans', [$lans[0], $lans[1]]);
    if ($bridge) {
        foreach (['10.20.0.0', '10.30.0.0', '172.16.0.0'] as $first) {
            $subnets = new DBObjectSet(DBObjectSearch::FromOQL(
                'SELECT IPv4Subnet WHERE org_id = :org AND ip = :ip'), [], ['org' => $org, 'ip' => $first]);
            if ($subnets->Count() === 1) {
                continue; // Reuse the Cloud-linked subnet created by the load balancer fixture.
            }
            $last = substr($first, 0, -1).'255';
            $block = natFixtureObject('IPv4Block', ['org_id' => $org, 'name' => "VPN block $first",
                'firstip' => $first, 'lastip' => $last]);
            natFixtureObject('IPv4Subnet', ['org_id' => $org, 'name' => "VPN subnet $first",
                'ip' => $first, 'mask' => '255.255.255.0', 'block_id' => $block]);
            manualIpLink($cloud, $block);
        }
        $block = natFixtureObject('IPv6Block', ['org_id' => $org, 'name' => 'VPN IPv6 block',
            'firstip' => '2001:db8:20::', 'lastip' => '2001:db8:20:0:ffff:ffff:ffff:ffff']);
        $subnet = natFixtureObject('IPv6Subnet', ['org_id' => $org, 'name' => 'VPN IPv6 subnet',
            'ip' => '2001:db8:20::', 'mask' => 64, 'block_id' => $block]);
        manualIpLink($cloud, $subnet);
    }
    $ipsec = ['id' => $gatewayId, 'metadata' => ['status' => 'AVAILABLE'], 'properties' => [
        'name' => 'Fixture IPsec', 'description' => 'Site-to-site fixture', 'gatewayIP' => '203.0.113.10',
        'version' => 'IKEv2', 'tier' => 'STANDARD', 'connections' => [
            ['datacenterId' => $uuid, 'lanId' => '2', 'ipv4CIDR' => '10.20.0.7/24'],
            ['datacenterId' => $uuid, 'lanId' => '3', 'ipv4CIDR' => '10.30.0.9/24'],
        ],
    ]];
    $wireguard = ['id' => $gatewayId, 'metadata' => ['status' => 'AVAILABLE'], 'properties' => [
        'name' => 'Fixture WireGuard', 'gatewayIP' => '203.0.113.11', 'listenPort' => 51820,
        'interfaceIPv4CIDR' => '172.16.0.1/30', 'tier' => 'STANDARD', 'connections' => [
            ['datacenterId' => $uuid, 'lanId' => '2', 'ipv4CIDR' => '10.20.0.8/24',
                'ipv6CIDR' => '2001:db8:20::8/64'],
        ],
    ]];
    $ipsecPath = '/ipsecgateways';
    $wireguardPath = '/wireguardgateways';
    $publish = static function () use ($dir, $ipsecPath, $wireguardPath, &$ipsec, &$wireguard): void {
        setEndpoint($dir, $ipsecPath, [$ipsec]);
        setEndpoint($dir, $wireguardPath, [$wireguard]);
    };
    $conf = simplexml_load_file("$dir/conf/params.local.xml");
    $conf->ionos_products = 'compute,vpn';
    $conf->ionos_api_bases = 'https://127.0.0.1:8443,https://vpn.de-txl.localhost:8443';
    $conf->ionos_page_size = '1';
    $conf->asXML("$dir/conf/params.local.xml");

    $gateway = static fn (string $protocol) => one(
        'SELECT CloudVpnGateway WHERE cloud_id = :cloud AND protocol = :protocol AND provider_resource_id = :resource',
        ['cloud' => $cloud, 'protocol' => $protocol, 'resource' => $gatewayId],
    );
    $lan = static fn (int $gateway, string $resource) => one(
        'SELECT CloudVpnGatewayLan AS a JOIN CloudLan AS l ON a.cloud_lan_id = l.id'
        .' WHERE a.vpn_gateway_id = :gateway AND l.cloud_id = :cloud AND l.provider_resource_id = :resource',
        ['gateway' => $gateway, 'cloud' => $cloud, 'resource' => $resource],
    );
    $key = static function (string $address) use ($bridge, $org): int|string {
        if (!$bridge) {
            return $address;
        }
        $ipv6 = str_contains($address, ':');
        $literal = $ipv6 ? implode(':', str_split(bin2hex(inet_pton($address)), 4)) : $address;
        $class = $ipv6 ? 'IPv6Address' : 'IPv4Address';
        $attribute = $ipv6 ? 'ip_text' : 'ip';
        return (int) one("SELECT $class WHERE org_id = :org AND $attribute = :ip", ['org' => $org, 'ip' => $literal])->GetKey();
    };
    $field = $bridge ? 'ipaddress_id' : 'address';
    $endpoint = $bridge ? 'public_endpoint_id' : 'public_endpoint';
    $assignment = static function (string $class, string $parent, int $id, string $host, int $prefix) use ($field, $key): int {
        $row = one("SELECT $class WHERE $parent = :parent AND $field = :host", ['parent' => $id, 'host' => $key($host)]);
        verify((int) $row->Get('prefix_length') === $prefix, "Expected the reported prefix for $host.");
        return (int) $row->GetKey();
    };
    $publish();
    runCollector($dir, true);
    $ipsecRow = $gateway('ipsec');
    $wireguardRow = $gateway('wireguard');
    $ipsecId = (int) $ipsecRow->GetKey();
    $wireguardId = (int) $wireguardRow->GetKey();
    $lan2 = (int) $lan($ipsecId, '2')->GetKey();
    $lan3 = (int) $lan($ipsecId, '3')->GetKey();
    $wgLan2 = (int) $lan($wireguardId, '2')->GetKey();
    verify($ipsecId !== $wireguardId
        && $ipsecRow->Get('source_namespace') !== $wireguardRow->Get('source_namespace'),
        'The same provider ID may occur in two protocol scopes on the same Cloud.');
    verify(MetaModel::GetObject('Cloud', $cloud)->Get('cloud_vpn_gateways_list')->Count() === 2
        && MetaModel::GetObject('Cloud', $otherCloud)->Get('cloud_vpn_gateways_list')->Count() === 0,
        'Expected both gateways under the correct Cloud.');
    verify((string) $ipsecRow->Get($endpoint) === (string) $key('203.0.113.10')
        && (string) $wireguardRow->Get($endpoint) === (string) $key('203.0.113.11')
        && $ipsecRow->Get('ike_version') === 'IKEv2' && (int) $wireguardRow->Get('listen_port') === 51820
        && $ipsecRow->Get('description') === $ipsec['properties']['description']
        && $ipsecRow->Get('ionos_tier') === 'STANDARD' && $ipsecRow->Get('ionos_status') === 'AVAILABLE'
        && $ipsecRow->Get('ionos_region') === 'de-txl', 'Expected endpoint roles, protocol details and provider metadata.');
    $lanAddress = 'CloudVpnGatewayLanAddress';
    $interface = 'CloudVpnGatewayInterfaceAddress';
    $oldIp = $key('10.20.0.7');
    $old = $assignment($lanAddress, 'attachment_id', $lan2, '10.20.0.7', 24);
    $stable = $assignment($lanAddress, 'attachment_id', $lan3, '10.30.0.9', 24);
    $wgV4 = $assignment($lanAddress, 'attachment_id', $wgLan2, '10.20.0.8', 24);
    $wgV6 = $assignment($lanAddress, 'attachment_id', $wgLan2, '2001:db8:20::8', 64);
    $wgInterface = $assignment($interface, 'vpn_gateway_id', $wireguardId, '172.16.0.1', 30);
    verify($ipsecRow->Get('interface_addresses_list')->Count() === 0
        && $wireguardRow->Get('interface_addresses_list')->Count() === 1, 'Expected only WireGuard interface assignments.');

    $ipsec['properties']['connections'] = array_reverse($ipsec['properties']['connections']);
    $publish();
    runCollector($dir, true);
    verify((int) $gateway('ipsec')->GetKey() === $ipsecId && (int) $gateway('wireguard')->GetKey() === $wireguardId
        && (int) $lan($ipsecId, '2')->GetKey() === $lan2 && (int) $lan($ipsecId, '3')->GetKey() === $lan3
        && (int) $lan($wireguardId, '2')->GetKey() === $wgLan2
        && $assignment($lanAddress, 'attachment_id', $lan2, '10.20.0.7', 24) === $old
        && $assignment($lanAddress, 'attachment_id', $lan3, '10.30.0.9', 24) === $stable
        && $assignment($lanAddress, 'attachment_id', $wgLan2, '10.20.0.8', 24) === $wgV4
        && $assignment($lanAddress, 'attachment_id', $wgLan2, '2001:db8:20::8', 64) === $wgV6
        && $assignment($interface, 'vpn_gateway_id', $wireguardId, '172.16.0.1', 30) === $wgInterface
        && $key('10.20.0.7') === $oldIp,
        'Reordering connections must retain the original CI, attachment, host and assignment IDs.');

    $manual = natFixtureObject($lanAddress, ['attachment_id' => $lan2, $field => $key('10.20.0.8'), 'prefix_length' => 25]);
    if ($bridge) {
        $shared = natFixtureObject($interface, ['vpn_gateway_id' => $wireguardId, $field => $oldIp, 'prefix_length' => 24]);
        $wireguardRow->Set($endpoint, $key('203.0.113.10'));
        $wireguardRow->DBUpdate();
    }
    $ipsec['properties']['name'] = 'Fixture IPsec renamed';
    $ipsec['properties']['connections'][1]['ipv4CIDR'] = '10.20.0.17/24';
    $publish();
    runCollector($dir, true);
    $new = $assignment($lanAddress, 'attachment_id', $lan2, '10.20.0.17', 24);
    $manualRow = MetaModel::GetObject($lanAddress, $manual);
    verify((int) $gateway('ipsec')->GetKey() === $ipsecId && $gateway('ipsec')->Get('name') === 'Fixture IPsec renamed'
        && (string) $gateway('ipsec')->Get($endpoint) === (string) $key('203.0.113.10')
        && $assignment($lanAddress, 'attachment_id', $lan3, '10.30.0.9', 24) === $stable
        && MetaModel::GetObject($lanAddress, $old, false) === null
        && (int) $manualRow->Get('prefix_length') === 25 && (string) $manualRow->Get('ionos_source_key') === '',
        'Replacing an imported host must keep the gateway, the other LAN address and the manual assignment.');
    if ($bridge) {
        verify((int) MetaModel::GetObject($interface, $shared)->Get('ipaddress_id') === $oldIp && $key('10.20.0.7') === $oldIp
            && (int) $gateway('wireguard')->Get($endpoint) === $key('203.0.113.10'),
            'Keep a shared IPAM host and a manually changed public endpoint.');
    }

    $incomplete = $ipsec;
    $incomplete['properties']['name'] = 'Incomplete IPsec';
    $second = $incomplete;
    $second['id'] = '0bf477a1-3492-45bf-983e-80788c7eeb2b';
    setEndpoint($dir, $ipsecPath, [$incomplete, $second], pageTwoStatus: 403);
    $wireguard['properties']['name'] = 'WireGuard after page failure';
    setEndpoint($dir, $wireguardPath, [$wireguard]);
    $output = runCollector($dir, false);
    verify(str_contains($output, 'de-txl/ipsec') && str_contains($output, '1 pages read'),
        'Expected an explicit second-page failure for IPsec.');
    verify($gateway('ipsec')->Get('name') === 'Fixture IPsec renamed'
        && $gateway('wireguard')->Get('name') === 'WireGuard after page failure',
        'A failed page must preserve its scope while WireGuard still reconciles.');

    $incomplete['properties']['connections'][0]['lanId'] = '999';
    setEndpoint($dir, $ipsecPath, [$incomplete]);
    $wireguard['properties']['name'] = 'WireGuard after bad LAN';
    setEndpoint($dir, $wireguardPath, [$wireguard]);
    $output = runCollector($dir, false);
    verify(str_contains($output, 'de-txl/ipsec') && str_contains($output, $gatewayId)
        && str_contains($output, 'unresolved or duplicate LAN'), 'Expected the invalid gateway reference to be reported.');
    verify($gateway('ipsec')->Get('name') === 'Fixture IPsec renamed'
        && (int) $lan($ipsecId, '2')->GetKey() === $lan2 && (int) $lan($ipsecId, '3')->GetKey() === $lan3
        && $assignment($lanAddress, 'attachment_id', $lan2, '10.20.0.17', 24) === $new
        && $assignment($lanAddress, 'attachment_id', $lan3, '10.30.0.9', 24) === $stable
        && $gateway('wireguard')->Get('name') === 'WireGuard after bad LAN',
        'An invalid LAN must preserve its scope while WireGuard still reconciles.');
    echo "VPN initial import, roles, repeat, rename, manual ownership and independent scopes passed.\n";
}
