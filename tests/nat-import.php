<?php
/** Exercise NAT inventory through the real Base CLI and the installed iTop REST API. */
declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests;

use DBObjectSearch;
use DBObjectSet;
use MetaModel;

/** Create a fixture object in the disposable installation. */
function natFixtureObject(string $class, array $fields): int
{
    $object = MetaModel::NewObject($class);
    foreach ($fields as $field => $value) {
        $object->Set($field, $value);
    }
    $object->DBInsert();
    return (int) $object->GetKey();
}

/** Test imports, manual ownership, scoped IPAM matches, retries and withdrawal. */
function verifyNatImport(string $dir, string $uuid, int $cloud, int $lan, int $org, bool $bridge): void
{
    $natUuid = 'f0ea7840-01e1-428e-ac33-e33b99cd4971';
    $endpoint = "/datacenters/$uuid/natgateways";
    $properties = [
        'name' => 'Fixture egress', 'publicIps' => ['203.0.113.10', '203.0.113.11'],
        'lans' => [['id' => 1, 'gatewayIps' => ['192.0.2.33/25']]],
    ];
    $retiredUuid = '9b23e5d2-7bcd-4a3b-9041-5b8a9cfe081f';
    $publish = static function (array $properties) use ($dir, $endpoint, $natUuid, $retiredUuid): void {
        setEndpoint($dir, $endpoint, [
            ['id' => $natUuid, 'type' => 'natgateway', 'properties' => $properties],
            ['id' => $retiredUuid, 'type' => 'natgateway', 'properties' => [
                'name' => 'Retired gateway', 'publicIps' => ['203.0.113.15'], 'lans' => [],
            ]],
        ]);
    };
    setPage($dir, $uuid, 'NAT inventory fixture', false, 200, null);
    setLan($dir, $uuid, 'Fixture private LAN', false);

    $publicParent = 'SELECT CloudNatGatewayPublicAddress WHERE nat_gateway_id = :gateway';
    $field = $bridge ? 'ipaddress_id' : 'address';
    foreach (['CloudNatGatewayPublicAddress', 'CloudNatGatewayLanAddress'] as $class) {
        verify(MetaModel::IsValidAttCode($class, 'address') === !$bridge
            && MetaModel::IsValidAttCode($class, 'ipaddress_id') === $bridge, 'Expected one active NAT address representation.');
    }
    $addressLookup = "SELECT CloudNatGatewayLanAddress WHERE attachment_id = :attachment AND $field = :host";
    $publicLookup = $publicParent." AND $field = :host";
    $key = static fn (string $host) => $bridge
        ? (int) one('SELECT IPv4Address WHERE org_id = :org AND ip = :host', ['org'=>$org, 'host'=>$host])->GetKey()
        : $host;
    $gatewayLookup = 'SELECT CloudNatGateway WHERE cloud_id = :cloud AND provider_resource_id = :resource';
    if ($bridge) {
        $publicBlock = natFixtureObject('IPv4Block', ['org_id'=>$org, 'name'=>'NAT public fixtures',
            'firstip'=>'203.0.113.0', 'lastip'=>'203.0.113.255']);
        $publicSubnet = natFixtureObject('IPv4Subnet', ['org_id'=>$org, 'name'=>'NAT public subnet',
            'ip'=>'203.0.113.0', 'mask'=>'255.255.255.0', 'block_id'=>$publicBlock]);
        $publicIp = natFixtureObject('IPv4Address', ['org_id'=>$org, 'ip'=>'203.0.113.10', 'subnet_id'=>$publicSubnet]);
        $block = one("SELECT IPv4Block WHERE org_id = :org AND name = 'Manual IPv4 block'", ['org'=>$org]);
        $privateSubnet = natFixtureObject('IPv4Subnet', ['org_id'=>$org, 'name'=>'NAT private subnet',
            'ip'=>'192.0.2.0', 'mask'=>'255.255.255.128', 'block_id'=>$block->GetKey()]);
        $manual6 = one("SELECT IPv6Block WHERE org_id = :org AND name = 'Manual IPv6 block'", ['org'=>$org]);
        $v6Subnet = natFixtureObject('IPv6Subnet', ['org_id'=>$org, 'name'=>'NAT private IPv6 subnet',
            'ip'=>'2001:db8:abcd:3412::', 'mask'=>64, 'block_id'=>$manual6->GetKey()]);
        $properties['lans'][0]['gatewayIps'][] = '2001:db8:abcd:3412::1234/64';
        $v6Lookup = "SELECT IPv6Address WHERE org_id = :org AND ip_text = '2001:0db8:abcd:3412:0000:0000:0000:1234'";
        // The collector must create the private hosts and the second public host.
        $manualIp = natFixtureObject('IPv4Address', ['org_id'=>$org, 'ip'=>'203.0.113.12', 'subnet_id'=>$publicSubnet]);
        $alternateIp = natFixtureObject('IPv4Address', ['org_id'=>$org, 'ip'=>'203.0.113.13', 'subnet_id'=>$publicSubnet]);
    }
    $publish($properties);
    runCollector($dir, true);
    $gateway = one($gatewayLookup, ['cloud'=>$cloud, 'resource'=>$natUuid]);
    $gid = (int) $gateway->GetKey();
    $retired = one($gatewayLookup, ['cloud'=>$cloud, 'resource'=>$retiredUuid]);
    $attachment = one('SELECT CloudNatGatewayLan WHERE nat_gateway_id = :g AND cloud_lan_id = :l', ['g'=>$gid, 'l'=>$lan]);
    $private = one($addressLookup, ['attachment'=>$attachment->GetKey(), 'host'=>$key('192.0.2.33')]);
    $public = one($publicLookup, ['gateway'=>$gid, 'host'=>$key('203.0.113.10')]);
    verify((int) $private->Get('prefix_length') === 25 && $gateway->Get('public_addresses_list')->Count() === 2,
        'Expected two public addresses and the reported host prefix on the LAN.');
    if ($bridge) {
        $v6 = one($v6Lookup, ['org'=>$org]);
        $v6Key = (int) $v6->GetKey();
        $v6Assignment = one($addressLookup, ['attachment'=>$attachment->GetKey(), 'host'=>$v6Key]);
        verify((int) $v6->Get('subnet_id') === $v6Subnet && (int) $v6Assignment->Get('prefix_length') === 64,
            'A discovered IPv6 host must be created and linked in the VDC subnet.');
        $privateIp = $key('192.0.2.33');
        $createdIp = $key('203.0.113.11');
        verify((int) $public->Get('ipaddress_id') === $publicIp && (int) $private->Get('ipaddress_id') === $privateIp
            && (int) MetaModel::GetObject('IPv4Address', $privateIp)->Get('subnet_id') === $privateSubnet
            && (int) MetaModel::GetObject('IPv4Address', $createdIp)->Get('subnet_id') === $publicSubnet,
            'Expected reuse of the public host and creation in the matching public and private subnets.');
        $public->Set('ipaddress_id', $alternateIp);
        $public->DBUpdate();
    }
    $manual = natFixtureObject('CloudNatGatewayPublicAddress', ['nat_gateway_id'=>$gid, $field=>$key('203.0.113.12')]);
    $properties['name'] = 'Renamed fixture gateway';
    $properties['publicIps'] = ['203.0.113.10','203.0.113.12'];
    $properties['lans'][0]['gatewayIps'][0] = '192.0.2.33/26';
    $publish($properties);
    runCollector($dir, true);
    runCollector($dir, true);
    $current = one($gatewayLookup, ['cloud'=>$cloud, 'resource'=>$natUuid]);
    $updated = one($addressLookup, ['attachment'=>$attachment->GetKey(), 'host'=>$key('192.0.2.33')]);
    verify($current->GetKey() == $gid && $current->Get('name') === $properties['name']
        && $updated->GetKey() == $private->GetKey() && (int) $updated->Get('prefix_length') === 26,
        'Expected an in-place rename and prefix update on repeated import.');
    $survivor = one($publicLookup, ['gateway'=>$gid, 'host'=>$key('203.0.113.12')]);
    verify((int) $survivor->GetKey() === $manual && (string) $survivor->Get('ionos_source_key') === ''
        && $current->Get('public_addresses_list')->Count() === ($bridge ? 3 : 2), 'An existing manual assignment must stay manual.');
    if ($bridge) {
        verify((int) one($v6Lookup, ['org'=>$org])->GetKey() === $v6Key
            && one($addressLookup, ['attachment'=>$attachment->GetKey(), 'host'=>$v6Key])->GetKey() == $v6Assignment->GetKey(),
            'Repeated imports must reuse the same IPv6 host and its LAN assignment.');
        verify((int) $updated->Get('ipaddress_id') === $privateIp,
            'Changing the reported prefix must keep the unique host associated with the VDC.');
        $public->Reload();
        verify((int) $public->Get('ipaddress_id') === $alternateIp, 'A manual IPAM reassignment was overwritten.');
    }
    setEndpoint($dir, "/datacenters/".SECOND_VDC."/natgateways", [], 403);
    $properties['name'] = 'Incomplete inventory';
    $publish($properties);
    runCollector($dir, false);
    verify(one($gatewayLookup, ['cloud'=>$cloud, 'resource'=>$natUuid])->Get('name') === 'Renamed fixture gateway',
        'A forbidden NAT page must prevent changes based on the partial inventory.');
    setEndpoint($dir, "/datacenters/".SECOND_VDC."/natgateways", []);
    setEndpoint($dir, $endpoint, []);
    runCollector($dir, true);
    verify(MetaModel::GetObject('CloudNatGatewayPublicAddress', $manual, false) !== null
        && MetaModel::GetObject('CloudNatGateway', $gid, false) !== null,
        'A withdrawn gateway with a manual child must retain that assignment.');
    verify(MetaModel::GetObject('CloudNatGateway', $retired->GetKey(), false) === null,
        'A withdrawn gateway with only imported addresses should be retired with its children.');
    if ($bridge) {
        $preserved = one($publicLookup, ['gateway'=>$gid, 'host'=>$alternateIp]);
        verify($preserved->GetKey() == $public->GetKey()
            && $key('203.0.113.11') === $createdIp && $key('192.0.2.33') === $privateIp,
            'Withdrawal must retain shared IPAM hosts and a manually reassigned reference.');

    }
    $privateSet = new DBObjectSet(DBObjectSearch::FromOQL($addressLookup), [], ['attachment'=>$attachment->GetKey(), 'host'=>$key('192.0.2.33')]);
    verify($privateSet->Count() === 0, 'The withdrawn private assignment should be retired.');
    echo "NAT import, prefix change, rename, manual ownership, repeat import and withdrawal passed.\n";
}
