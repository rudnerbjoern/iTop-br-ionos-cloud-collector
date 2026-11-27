<?php
/** Synchronize virtual datacenters and their LANs against an isolated iTop and a loopback IONOS fixture. */
declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests;

use Config;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use RuntimeException;
use SimpleXMLElement;

require_once __DIR__ . '/nat-import.php';

const SECOND_VDC = '22222222-0000-4000-8000-123456789abc';

/** Fail the test without exposing connection credentials. */
function verify(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

/** Serve pages over authenticated local TLS for the actual IONOS HTTP client. */
function serveIonos(string $directory): void
{
    $context = stream_context_create(['ssl' => ['local_cert' => "$directory/cert.pem", 'local_pk' => "$directory/key.pem"]]);
    $server = stream_socket_server('tls://127.0.0.1:8443', $error, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    verify(is_resource($server), 'Could not bind the IONOS fixture.');
    while (true) {
        $client = @stream_socket_accept($server, 60);
        if (!$client) {
            continue;
        }
        $request = fgets($client);
        $authenticated = false;
        while (($header = fgets($client)) !== false && trim($header) !== '') {
            if (trim($header) === 'Authorization: Bearer '.getenv('IONOS_TOKEN')) {
                $authenticated = true;
            }
        }
        $url = explode(' ', trim((string) $request))[1] ?? '';
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
        $pages = json_decode(file_get_contents("$directory/pages.json"), true, flags: JSON_THROW_ON_ERROR);
        $path = parse_url($url, PHP_URL_PATH);
        $response = $pages[$path] ?? ['status' => 404, 'items' => []];
        $embedded = $pages["$path/networkloadbalancers"] ?? null;
        if ($embedded !== null && ($query['depth'] ?? null) === '2') {
            $response = ['status' => 200, 'items' => [], 'entities' => ['networkloadbalancers' => [
                'items' => array_map(static fn ($item) => ['id' => $item['id']], $embedded['items']),
            ]]];
        }
        $offset = (int) ($query['offset'] ?? 0);
        $status = $authenticated ? ($offset > 0 ? ($response['pageTwoStatus'] ?? $response['status']) : $response['status']) : 403;
        $rows = str_ends_with($path, '/networkloadbalancers') ? $response['items']
            : array_slice($response['items'], $offset, isset($query['limit']) ? (int) $query['limit'] : null);
        $body = json_encode(isset($response['entities']) ? ['entities' => $response['entities']]
            : ['items' => $rows, 'totalCount' => count($response['items'])], JSON_THROW_ON_ERROR);
        fwrite($client, "HTTP/1.1 $status Fixture\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
        fclose($client);
    }
}

/** Start a local fixture service, without inheriting a console output stream. */
function startService(array $command)
{
    $process = proc_open($command, [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
    ], $pipes);
    verify(is_resource($process), 'Cannot start local fixture process.');
    return $process;
}

/** Execute the real Base CLI and compare the result to the expected success or failure. */
function runCollector(string $directory, bool $expectSuccess, string ...$flags): string
{
    $command = [PHP_BINARY, '-d', "curl.cainfo=$directory/cert.pem", "$directory/exec.php", ...$flags];
    $process = proc_open($command, [0=>['file','/dev/null','r'], 1=>['pipe','w'], 2=>['redirect',1]], $pipes, $directory);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    $output = str_replace([getenv('ITOP_TEST_ADMIN_PASSWORD'), getenv('IONOS_TOKEN'), getenv('ITOP_TEST_DB_PASSWORD')], '[redacted]', $output);
    verify(($status === 0) === $expectSuccess, "Unexpected Base exit $status. ".substr($output, -3000));
    return $output;
}

/** Return the sole object matching the supplied fixture criteria. */
function one(string $oql, array $parameters = [])
{
    $set = new DBObjectSet(DBObjectSearch::FromOQL($oql), [], $parameters);
    verify($set->Count() === 1, "Expected one object for $oql.");
    return $set->Fetch();
}

/** Return link IDs indexed by TeemIP object ID to check the list without relying on display order. */
function ipLinks(int $cloudId): array
{
    $set = new DBObjectSet(DBObjectSearch::FromOQL('SELECT lnkCloudToIPObject WHERE cloud_id = :cloud'), [], ['cloud' => $cloudId]);
    $links = [];
    while ($link = $set->Fetch()) {
        $key = (int) $link->Get('ipobject_id');
        verify(!isset($links[$key]), 'Duplicate TeemIP link.');
        $links[$key] = (int) $link->GetKey();
    }
    ksort($links);
    return $links;
}

/** Create a manual link whose identity must survive subsequent synchronizations. */
function manualIpLink(int $cloudId, int $objectId): int
{
    $link = MetaModel::NewObject('lnkCloudToIPObject');
    $link->Set('cloud_id', $cloudId);
    $link->Set('ipobject_id', $objectId);
    $link->DBInsert();
    return (int) $link->GetKey();
}

/** Replace one endpoint between synchronous collection runs, retaining the other VDC's LAN response. */
function setEndpoint(string $directory, string $path, array $items, int $status = 200, ?int $pageTwoStatus = null): void
{
    $file = "$directory/pages.json";
    $pages = is_file($file) ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : [];
    $pages[$path] = ['status' => $status, 'items' => $items, 'pageTwoStatus' => $pageTwoStatus];
    file_put_contents($file, json_encode($pages, JSON_THROW_ON_ERROR));
}

/** Supply two VDCs so a provider LAN ID may legitimately occur twice. */
function setPage(string $directory, string $uuid, string $name, bool $protection, int $status = 200,
    ?string $cidr = '2001:db8:abcd:1200::/56', string $otherName = 'Other VDC'): void
{
    $item = ['id'=>$uuid, 'type'=>'datacenter', 'metadata'=>['state'=>'BUSY'],
        'properties'=>['name'=>$name, 'description'=>'Fixture VDC', 'location'=>'de/txl',
        'secAuthProtection'=>$protection, 'ipv6CidrBlock'=>$cidr]];
    $other = $item;
    $other['id'] = SECOND_VDC;
    $other['properties']['name'] = $otherName;
    $other['properties']['ipv6CidrBlock'] = null;
    setEndpoint($directory, '/datacenters', [$item, $other], $status);
}

/** Set a LAN response with a scoped ID, optional provider name and native visibility flag. */
function setLan(string $directory, string $uuid, ?string $name, bool $public, int $status = 200): void
{
    $properties = ['public' => $public];
    if ($name !== null) {
        $properties['name'] = $name;
    }
    setEndpoint($directory, "/datacenters/$uuid/lans", [['id' => '1', 'type' => 'lan', 'properties' => $properties]], $status);
}

if (($argv[1] ?? '') === 'serve-ionos') {
    serveIonos($argv[2]);
    exit;
}

verify(getenv('ITOP_TEST_MODE') === '1' && getenv('ITOP_TEST_DB_HOST') === 'test-db'
    && getenv('ITOP_TEST_DB_NAME') === 'itop_test' && getenv('ITOP_TEST_DB_USER') === 'itop_test'
    && getenv('IONOS_TOKEN') === false, 'This fixture requires the isolated iTop runner with no live IONOS token.');
$root = '/var/www/html/';
require $root.'approot.inc.php';
require_once $root.'core/config.class.inc.php';
$itop = new Config($root.'conf/production/config-itop.php');
verify($itop->Get('db_host') === 'test-db' && $itop->Get('db_name') === 'itop_test' && $itop->Get('db_user') === 'itop_test',
    'iTop is not connected to the disposable database.');
require_once $root.'application/startup.inc.php';
if (($argv[1] ?? '') === 'verify-nat-upgrade-snapshot') {
    require_once __DIR__ . '/nat-upgrade.php';
    verifyUpgradedNatSnapshot($argv[2]);
    exit;
}

$admin = one("SELECT User WHERE login = 'admin'");
$rest = one("SELECT URP_Profiles WHERE name = 'REST Services User'");
$links = new DBObjectSet(DBObjectSearch::FromOQL('SELECT URP_UserProfile WHERE userid = :u AND profileid = :p'),
    [], ['u'=>$admin->GetKey(), 'p'=>$rest->GetKey()]);
if ($links->Count() === 0) {
    $link = MetaModel::NewObject('URP_UserProfile');
    $link->Set('userid', $admin->GetKey());
    $link->Set('profileid', $rest->GetKey());
    $link->DBInsert();
}

$inputs = '/extension/.dev';
$directory = sys_get_temp_dir().'/ionos-base-'.bin2hex(random_bytes(7));
verify(mkdir($directory, 0700) && mkdir("$directory/conf") && mkdir("$directory/collectors"), 'Cannot create private test directory.');
copy("$inputs/base/exec.php", "$directory/exec.php");
symlink("$inputs/base/core", "$directory/core");
copy("$inputs/base/conf/params.distrib.xml", "$directory/conf/params.distrib.xml");
$copy = startService(['cp', '-R', "$inputs/module/.", "$directory/collectors"]);
verify(proc_close($copy) === 0, 'Could not copy the collector package into the fixture.');
$listOptions = ['offset' => true, 'depth' => 1];
$fixturePlan = ['compute'=>['bases'=>['https://127.0.0.1:8443'],
    'resources'=>['/datacenters'=>$listOptions, '/datacenters/{datacenterId}/lans'=>$listOptions,
        '/datacenters/{datacenterId}/natgateways'=>['offset'=>false, 'depth'=>1],
        '/datacenters/{datacenterId}/networkloadbalancers'=>$listOptions + ['verifyEmbedded' => 'networkloadbalancers'],
        '/datacenters/{datacenterId}/servers'=>$listOptions]]];
$fixturePlan['vpn'] = ['bases' => ['https://vpn.de-txl.localhost:8443'], 'resources' => [
    '/ipsecgateways' => ['offset' => true], '/wireguardgateways' => ['offset' => true],
]];
file_put_contents("$directory/collectors/resources.json", json_encode($fixturePlan, JSON_THROW_ON_ERROR));
$mode = trim(file_get_contents("$inputs/bridge-mode"));
$genericOnly = in_array($mode, ['Generic', 'GenericBridge'], true);
$modules = GetModulesInfo();
verify(isset($modules['br-cloud-datamodel']) && isset($modules['br-ionos-cloud-datamodel']) === !$genericOnly,
    'The installed cloud modules differ from the selected variant.');
verify(MetaModel::IsValidAttCode('Cloud', 'ionos_sec_auth_protection') === !$genericOnly,
    'The IONOS field must be installed exclusively by its add-on.');
$providers = new DBObjectSet(DBObjectSearch::FromOQL("SELECT Organization WHERE code = 'IONOS'"));
verify($providers->Count() === ($genericOnly ? 0 : 1), 'Unexpected IONOS sample data selection.');
if ($genericOnly) {
    $provider = MetaModel::NewObject('Organization');
    $provider->Set('name', 'Generic test provider');
    $provider->DBInsert();
} else {
    $provider = $providers->Fetch();
}
$customer = MetaModel::NewObject('Organization');
$customer->Set('name', 'VDC test customer '.basename($directory));
$customer->DBInsert();
$bridge = isset($modules['br-cloud-datamodel-bridge-for-teemip']);
$expectedBridge = in_array($mode, ['Bridge', 'GenericBridge'], true);
verify($bridge === $expectedBridge, 'Installed bridge differs from requested test configuration.');
verify(MetaModel::IsValidAttCode('Cloud', 'ip_objects_list') === $expectedBridge,
    'The neutral IP object list must follow TeemIP availability independently of the IONOS add-on.');
$blockId = null;
if ($bridge) {
    $block = MetaModel::NewObject('IPv6Block');
    $block->Set('name', 'Test VDC allocation');
    $block->Set('org_id', $customer->GetKey());
    $block->Set('firstip', '2001:db8:abcd:1200::');
    $block->Set('lastip', '2001:db8:abcd:12ff:ffff:ffff:ffff:ffff');
    $block->DBInsert();
    $blockId = (int) $block->GetKey();
}
$xml = new SimpleXMLElement('<parameters/>');
$xml->itop_url = 'http://127.0.0.1:8082';
$xml->itop_login = 'admin';
$xml->itop_password = getenv('ITOP_TEST_ADMIN_PASSWORD');
$xml->ionos_products = 'compute';
$xml->ionos_org_id = (string) $customer->GetKey();
$xml->ionos_provider_id = (string) $provider->GetKey();
$xml->asXML("$directory/conf/params.local.xml");
putenv('IONOS_TOKEN='.bin2hex(random_bytes(24)));
$cert = startService(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
    '-keyout', "$directory/key.pem", '-out', "$directory/cert.pem", '-subj', '/CN=127.0.0.1',
    '-addext', 'subjectAltName=IP:127.0.0.1,DNS:vpn.de-txl.localhost', '-days', '1']);
verify(proc_close($cert) === 0, 'Could not create ephemeral TLS identity.');
$web = startService([PHP_BINARY, '-S', '127.0.0.1:8082', '-t', $root]);
$ionos = startService([PHP_BINARY, __FILE__, 'serve-ionos', $directory]);
try {
    foreach ([8082, 8443] as $port) {
        for ($attempt = 0; $attempt < 100; ++$attempt) {
            $socket = @stream_socket_client("tcp://127.0.0.1:$port", $errno, $error, 0.1);
            if ($socket) {
                fclose($socket);
                break;
            }
            usleep(30000);
        }
        verify((bool) $socket, "Fixture service did not listen on $port.");
    }
    $uuid = bin2hex(random_bytes(4)).'-0000-4000-8000-123456789abc';
    $lookup = 'SELECT Cloud WHERE provider_id = :provider AND provider_resource_id = :uuid';
    $lanLookup = "SELECT CloudLan WHERE cloud_id = :cloud AND provider_resource_id = '1'";
    setPage($directory, $uuid, 'VDC before rename', true);
    setLan($directory, $uuid, 'Internal LAN', false);
    setLan($directory, SECOND_VDC, null, true);
    setEndpoint($directory, "/datacenters/$uuid/natgateways", []);
    setEndpoint($directory, "/datacenters/".SECOND_VDC."/natgateways", []);
    setEndpoint($directory, "/datacenters/$uuid/networkloadbalancers", []);
    setEndpoint($directory, "/datacenters/".SECOND_VDC."/networkloadbalancers", []);
    setEndpoint($directory, "/datacenters/$uuid/servers", []);
    setEndpoint($directory, "/datacenters/".SECOND_VDC."/servers", []);
    if ($genericOnly) {
        $sourceSearch = DBObjectSearch::FromOQL('SELECT SynchroDataSource');
        $sourcesBefore = (new DBObjectSet($sourceSearch))->Count();
        foreach ([['--configure_only'], []] as $flags) {
            $output = runCollector($directory, false, ...$flags);
            verify(str_contains($output, 'br-ionos-cloud-datamodel'), 'Expected the collector to identify the missing IONOS module.');
            verify((new DBObjectSet($sourceSearch))->Count() === $sourcesBefore, 'The collector modified the source list on a generic-only target.');
        }
        $created = new DBObjectSet(DBObjectSearch::FromOQL($lookup), [], ['uuid' => $uuid, 'provider' => $provider->GetKey()]);
        verify($created->Count() === 0, 'The collector imported a Cloud on a generic-only target.');
        echo "Generic-only target rejected before synchronization.\n";
        return;
    }
    $foreignProvider = MetaModel::NewObject('Organization');
    $foreignProvider->Set('name', 'Other cloud provider');
    $foreignProvider->DBInsert();
    $foreignCloud = MetaModel::NewObject('Cloud');
    $foreignCloud->Set('name', 'Other provider same ID');
    $foreignCloud->Set('provider_id', $foreignProvider->GetKey());
    $foreignCloud->Set('org_id', $customer->GetKey());
    $foreignCloud->Set('provider_resource_id', $uuid);
    $foreignCloud->DBInsert();
    // Move NAT ahead of its dependencies using the real Base XML configuration.
    $configPath = "$directory/conf/params.local.xml";
    $invalid = simplexml_load_file($configPath);
    $sequence = $invalid->addChild('collectors_launch_sequence');
    $sequence->addAttribute('type', 'array');
    $earlyNat = $sequence->addChild('collector');
    $earlyNat->addChild('name', 'BRIonosNatGatewayCollector');
    $earlyNat->addChild('enable', 'yes');
    $earlyNat->addChild('rank', '0');
    $invalid->asXML($configPath);
    try {
        $failure = runCollector($directory, false, '--configure_only');
        verify(str_contains($failure, 'prerequisites for BRIonosNatGatewayCollector'), 'Expected invalid NAT phase order to be rejected.');
    } finally {
        $xml->asXML($configPath);
    }
    runCollector($directory, true, '--configure_only');
    runCollector($directory, true);
    $source = one('SELECT SynchroDataSource WHERE name = :name', [
        'name' => 'IONOS VDCs - org '.$customer->GetKey().' - provider '.$provider->GetKey(),
    ]);
    $attributes = new DBObjectSet(DBObjectSearch::FromOQL(
        'SELECT SynchroAttribute WHERE sync_source_id = :source AND attcode = :field'
    ), [], ['source' => $source->GetKey(), 'field' => 'ip_objects_list']);
    verify($attributes->Count() === 0 || !(bool) $attributes->Fetch()->Get('update'),
        'The VDC source must never overwrite the entire Cloud IP list.');
    $csv = fopen("$directory/data/BRIonosVdcCollector-1.csv", 'rb');
    verify(is_resource($csv), 'Missing Base collection output.');
    $columns = fgetcsv($csv, 0, ';', '"', '\\');
    fclose($csv);
    verify(is_array($columns) && !in_array('ip_objects_list', $columns, true) && !in_array('ipv6_block_id', $columns, true),
        'The VDC CSV must leave individual TeemIP links to the allocation import.');
    $cloud = one($lookup, ['uuid'=>$uuid, 'provider'=>$provider->GetKey()]);
    $id = $cloud->GetKey();
    $otherCloud = one($lookup, ['uuid' => SECOND_VDC, 'provider' => $provider->GetKey()]);
    $lan = one($lanLookup, ['cloud' => $id]);
    $otherLan = one($lanLookup, ['cloud' => $otherCloud->GetKey()]);
    $unrelated = one($lookup, ['uuid' => $uuid, 'provider' => $foreignProvider->GetKey()]);
    verify($unrelated->GetKey() == $foreignCloud->GetKey() && $unrelated->Get('name') === 'Other provider same ID'
        && $unrelated->Get('cloud_lans_list')->Count() === 0, 'The collector modified a Cloud owned by another provider.');
    verify($lan->GetKey() != $otherLan->GetKey() && $lan->Get('name') === 'Internal LAN'
        && $otherLan->Get('name') === 'LAN 1', 'Scoped LAN IDs and fallback names must survive the first synchronization.');
    foreach ([[$cloud, $lan], [$otherCloud, $otherLan]] as [$parentCloud, $expectedLan]) {
        $linked = $parentCloud->Get('cloud_lans_list');
        verify($linked->Count() === 1 && $linked->Fetch()->GetKey() == $expectedLan->GetKey(),
            'The Cloud LAN list must show only its own LAN.');
    }
    verify((int) $lan->Get('org_id') === (int) $cloud->Get('org_id')
        && (int) $otherLan->Get('org_id') === (int) $otherCloud->Get('org_id'),
        'Each LAN must inherit its owning Cloud organization.');
    verify((int) $lan->Get('public') === 0 && (int) $otherLan->Get('public') === 1,
        'Public and private LAN visibility must match the source.');
    verify($cloud->Get('name') === 'VDC before rename' && $cloud->Get('ionos_sec_auth_protection') === 'yes'
        && (int)$cloud->Get('org_id') === (int)$customer->GetKey() && (int)$cloud->Get('provider_id') === (int)$provider->GetKey(),
        'First synchronization must create the VDC with the configured ownership and protection.');
    verify($cloud->Get('location_id') == one('SELECT Location WHERE name = :name AND org_id = :provider',
        ['name'=>'de/txl', 'provider'=>$provider->GetKey()])->GetKey(), 'Wrong provider location.');
    if ($bridge) {
        $initial = ipLinks((int) $id);
        verify(array_keys($initial) === [$blockId], 'The first import must create only the allocated IPv6 link.');
        $manual4 = MetaModel::NewObject('IPv4Block');
        $manual4->Set('name', 'Manual IPv4 block');
        $manual4->Set('org_id', $customer->GetKey());
        $manual4->Set('firstip', '192.0.2.0');
        $manual4->Set('lastip', '192.0.2.255');
        $manual4->DBInsert();
        $manual6 = MetaModel::NewObject('IPv6Block');
        $manual6->Set('name', 'Manual IPv6 block');
        $manual6->Set('org_id', $customer->GetKey());
        $manual6->Set('firstip', '2001:db8:abcd:3400::');
        $manual6->Set('lastip', '2001:db8:abcd:34ff:ffff:ffff:ffff:ffff');
        $manual6->DBInsert();
        $manual = [
            (int) $manual4->GetKey() => manualIpLink((int) $id, (int) $manual4->GetKey()),
            (int) $manual6->GetKey() => manualIpLink((int) $id, (int) $manual6->GetKey()),
        ];
        ksort($manual);
        $combined = $manual + $initial;
        ksort($combined);
    }
    $cloud->Set('status', 'implementation');
    $cloud->DBUpdate();
    setPage($directory, $uuid, 'VDC after rename', false);
    setLan($directory, $uuid, 'Renamed LAN', true);
    runCollector($directory, true);
    $renamed = one($lookup, ['uuid'=>$uuid, 'provider'=>$provider->GetKey()]);
    verify($renamed->GetKey() == $id && $renamed->Get('name') === 'VDC after rename'
        && $renamed->Get('ionos_sec_auth_protection') === 'no', 'A second run must update the same VDC.');
    verify($renamed->Get('status') === 'implementation', 'A synchronization changed the iTop lifecycle status.');
    $renamedLan = one($lanLookup, ['cloud' => $id]);
    verify($renamedLan->GetKey() == $lan->GetKey() && $renamedLan->Get('name') === 'Renamed LAN'
        && (int) $renamedLan->Get('public') === 1, 'Renaming a LAN must update the same CI and its public flag.');
    $secondLan = one($lanLookup, ['cloud' => $otherCloud->GetKey()]);
    verify($secondLan->GetKey() == $otherLan->GetKey() && $secondLan->Get('name') === 'LAN 1',
        'Renaming one LAN must preserve the LAN with the same provider ID in the other VDC.');
    setLan($directory, $uuid, 'Partial inventory', false);
    setLan($directory, SECOND_VDC, null, true, 403);
    runCollector($directory, false);
    verify(one($lanLookup, ['cloud' => $id])->Get('name') === 'Renamed LAN',
        'A failed LAN list must stop the import before writing observed LANs in other VDCs.');
    setLan($directory, $uuid, 'Renamed LAN', true);
    setLan($directory, SECOND_VDC, null, true);
    setPage($directory, $uuid, 'Incomplete inventory', true, 403);
    runCollector($directory, false);
    verify(one($lookup, ['uuid'=>$uuid, 'provider'=>$provider->GetKey()])->Get('name') === 'VDC after rename', 'A failed source read changed the VDC.');
    if ($bridge) {
        setPage($directory, $uuid, 'Unresolved IPv6 allocation', true, 200, '2001:db8:dead:ab00::/56');
        runCollector($directory, false);
        $unchanged = one($lookup, ['uuid'=>$uuid, 'provider'=>$provider->GetKey()]);
        verify(ipLinks((int) $id) === $combined && $unchanged->Get('name') === 'VDC after rename',
            'An unresolved IPv6 allocation must preserve the previous VDC.');
        setPage($directory, $uuid, 'VDC without allocation', true, 200, null);
        runCollector($directory, true);
        $cleared = one($lookup, ['uuid' => $uuid, 'provider' => $provider->GetKey()]);
        verify(ipLinks((int) $id) === $manual && $cleared->Get('name') === 'VDC without allocation',
            'An explicitly unallocated VDC must remove only its imported link.');
        setPage($directory, $uuid, 'VDC with allocation', true);
        runCollector($directory, true);
        verify(isset(ipLinks((int) $id)[$blockId]), 'A recurring allocation must recreate its link.');
        setPage($directory, $uuid, 'VDC matching manual allocation', true, 200, '2001:db8:abcd:3400::/56');
        runCollector($directory, true);
        verify(ipLinks((int) $id) === $manual, 'A prefix change must keep the existing manual link and retire the previous imported one.');
        setPage($directory, $uuid, 'VDC after manual match', true, 200, null);
        runCollector($directory, true);
        verify(ipLinks((int) $id) === $manual, 'A matching manual link must never become importer-owned.');
    }
    if (is_dir(__DIR__ . '/nat-upgrade-inputs')) {
        require_once __DIR__ . '/nat-upgrade.php';
        verifyNatUpgrade($root, $directory, $uuid, (int) $id, (int) $lan->GetKey(), (int) $customer->GetKey(), $bridge, $modules);
        return;
    }
    verifyNatImport($directory, $uuid, (int) $id, (int) $lan->GetKey(), (int) $customer->GetKey(), $bridge);
    require_once __DIR__ . '/nlb-import.php';
    verifyNlbImport($directory, $uuid, (int) $id, (int) $lan->GetKey(), (int) $customer->GetKey(), $bridge);
    require_once __DIR__ . '/vpn-import.php';
    verifyVpnImport($directory, $uuid, (int) $id, (int) $otherCloud->GetKey(), (int) $customer->GetKey(), $bridge);
    $xml->ionos_page_size = '1';
    $xml->asXML($configPath);
    require_once __DIR__ . '/vm-import.php';
    verifyVmImport($directory, $uuid, (int) $id, (int) $otherCloud->GetKey(), (int) $customer->GetKey(), (int) $provider->GetKey());
    echo 'VDC and LAN synchronization '.($bridge ? 'with' : 'without')." TeemIP: create, rename, scope and partial-read checks passed.\n";
} finally {
    foreach ([$web, $ionos] as $service) {
        proc_terminate($service);
        proc_close($service);
    }
}
