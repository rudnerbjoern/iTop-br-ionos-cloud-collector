<?php
/** Exercise the same load balancer observation in both installed iTop variants. */
declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests;

use MetaModel;

/** Check the initial import, repeat, rename and failed collection through the real Base CLI. */
function verifyNlbImport(string $dir, string $uuid, int $cloud, int $backendLan, int $org, bool $bridge): void
{
    $resource = '12ea7840-01e1-428e-ac33-e33b99cd4971';
    $endpoint = "/datacenters/$uuid/networkloadbalancers";
    setEndpoint($dir, "/datacenters/$uuid/lans", [
        ['id' => '1', 'properties' => ['name' => 'Backend LAN', 'public' => false]],
        ['id' => '2', 'properties' => ['name' => 'Listener LAN', 'public' => true]],
    ]);
    $properties = [
        'name' => 'Fixture load balancer', 'listenerLan' => 2, 'targetLan' => 1,
        'ips' => ['203.0.113.10', '203.0.113.11'],
        'lbPrivateIps' => ['10.20.0.7/24', '10.20.0.8/24'],
    ];
    $publish = static function (array $properties) use ($dir, $endpoint, $resource): void {
        setEndpoint($dir, $endpoint, [['id' => $resource, 'type' => 'networkloadbalancer', 'properties' => $properties]]);
    };
    $getHost = static fn (string $host) => one('SELECT IPv4Address WHERE org_id = :org AND ip = :host',
        ['org' => $org, 'host' => $host]);
    if ($bridge) {
        $block = natFixtureObject('IPv4Block', ['org_id' => $org, 'name' => 'Load balancer backend block',
            'firstip' => '10.20.0.0', 'lastip' => '10.20.0.255']);
        $subnet = natFixtureObject('IPv4Subnet', ['org_id' => $org, 'name' => 'Load balancer backend subnet',
            'ip' => '10.20.0.0', 'mask' => '255.255.255.0', 'block_id' => $block]);
        manualIpLink($cloud, $subnet);
        $manualHost = natFixtureObject('IPv4Address', ['org_id' => $org, 'ip' => '10.20.0.12', 'subnet_id' => $subnet]);
    }
    $address = $bridge ? 'ipaddress_id' : 'address';
    $getBalancer = static fn () => one('SELECT CloudNetworkLoadBalancer WHERE cloud_id = :cloud AND provider_resource_id = :resource',
        ['cloud' => $cloud, 'resource' => $resource]);
    $read = static function ($balancer, string $role) use ($bridge, $address): array {
        $set = $balancer->Get($role . '_addresses_list');
        $rows = [];
        while ($assignment = $set->Fetch()) {
            $value = $assignment->Get($address);
            $host = $bridge ? MetaModel::GetObject('IPv4Address', $value)->Get('ip') : $value;
            verify(!isset($rows[$host]), "Duplicate $role assignment for $host.");
            $rows[$host] = ['id' => (int) $assignment->GetKey(), 'prefix' => $role === 'backend' ? (int) $assignment->Get('prefix_length') : null];
        }
        ksort($rows);
        return $rows;
    };
    $publish($properties);
    runCollector($dir, true);
    $initial = $getBalancer();
    $id = (int) $initial->GetKey();
    $listenerLan = one("SELECT CloudLan WHERE cloud_id = :cloud AND provider_resource_id = '2'", ['cloud' => $cloud]);
    $cloudList = MetaModel::GetObject('Cloud', $cloud)->Get('cloud_network_load_balancers_list');
    verify($cloudList->Count() === 1 && (int) $cloudList->Fetch()->GetKey() === $id
        && (int) $initial->Get('listener_lan_id') === (int) $listenerLan->GetKey()
        && (int) $initial->Get('backend_lan_id') === $backendLan, 'Expected a single load balancer with both LAN roles.');
    $listeners = $read($initial, 'listener');
    $backends = $read($initial, 'backend');
    verify(array_keys($listeners) === $properties['ips'] && array_keys($backends) === ['10.20.0.7', '10.20.0.8']
        && array_column($backends, 'prefix') === [24, 24], 'Expected four assignments and both backend prefixes.');
    if ($bridge) {
        $hosts = array_merge(array_keys($listeners), array_keys($backends));
        $ipIds = array_map(static fn ($host) => (int) $getHost($host)->GetKey(), $hosts);
        verify((int) $getHost('10.20.0.7')->Get('subnet_id') === $subnet
            && (int) $getHost('10.20.0.8')->Get('subnet_id') === $subnet, 'Backend addresses must resolve within the linked VDC subnet.');
    }
    runCollector($dir, true);
    $repeat = $getBalancer();
    verify((int) $repeat->GetKey() === $id && $read($repeat, 'listener') === $listeners && $read($repeat, 'backend') === $backends,
        'Repeat import must preserve the load balancer and all assignment IDs.');
    if ($bridge) {
        verify(array_map(static fn ($host) => (int) $getHost($host)->GetKey(), $hosts) === $ipIds,
            'Repeat import must reuse the same four TeemIP hosts.');
    }
    $manual = natFixtureObject('CloudNetworkLoadBalancerBackendAddress', ['load_balancer_id' => $id,
        $address => $bridge ? $manualHost : '10.20.0.12', 'prefix_length' => 28]);
    $properties['name'] = 'Renamed load balancer';
    $properties['lbPrivateIps'][1] = '10.20.0.9/24';
    $publish($properties);
    runCollector($dir, true);
    $renamed = $getBalancer();
    $updated = $read($renamed, 'backend');
    verify((int) $renamed->GetKey() === $id && $renamed->Get('name') === $properties['name']
        && $read($renamed, 'listener') === $listeners && array_keys($updated) === ['10.20.0.12', '10.20.0.7', '10.20.0.9']
        && $updated['10.20.0.7'] === $backends['10.20.0.7'] && $updated['10.20.0.9']['prefix'] === 24
        && $updated['10.20.0.12'] === ['id' => $manual, 'prefix' => 28], 'Rename and address replacement must retain the manual row.');
    if ($bridge) {
        verify((int) $getHost('10.20.0.8')->GetKey() === $ipIds[3],
            'Removing the assignment must preserve the TeemIP host.');
    }
    setEndpoint($dir, $endpoint, [], 403);
    $failure = runCollector($dir, false);
    $preserved = $getBalancer();
    verify(str_contains($failure, $endpoint) && (int) $preserved->GetKey() === $id
        && $preserved->Get('name') === $properties['name'] && $read($preserved, 'listener') === $listeners
        && $read($preserved, 'backend') === $updated, 'A failed NLB fetch must report the scope and preserve the imported records.');
    echo "NLB import, repeat, rename, manual assignment and failed fetch passed.\n";
}
