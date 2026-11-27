<?php

/** Run a genuine Setup upgrade against the isolated copy-based test runner. */
declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests;

use MetaModel;
use SimpleXMLElement;

const NAT_UPGRADE_GATEWAY = 'f5b164d2-9631-4acb-a71d-32391b442ccd';

/** Record IDs, organizational and Cloud/LAN relations, IPAM references and ownership markers. */
function natUpgradeSnapshot(int $cloud, bool $bridge): array
{
    $gateway = one('SELECT CloudNatGateway WHERE cloud_id = :cloud AND provider_resource_id = :resource',
        ['cloud' => $cloud, 'resource' => NAT_UPGRADE_GATEWAY]);
    $attachment = one('SELECT CloudNatGatewayLan WHERE nat_gateway_id = :gateway', ['gateway' => $gateway->GetKey()]);
    $private = one('SELECT CloudNatGatewayLanAddress WHERE attachment_id = :attachment', ['attachment' => $attachment->GetKey()]);
    $public = one('SELECT CloudNatGatewayPublicAddress WHERE nat_gateway_id = :gateway', ['gateway' => $gateway->GetKey()]);
    $reference = $bridge ? ['ipaddress_id', 'ionos_synced_ip_id'] : ['address'];
    $rows = [
        [$gateway, ['org_id', 'cloud_id', 'provider_resource_id', 'name']],
        [$attachment, ['nat_gateway_id', 'cloud_lan_id']],
        [$private, ['attachment_id', 'prefix_length', ...$reference]],
        [$public, ['nat_gateway_id', ...$reference]],
    ];
    $snapshot = [];
    foreach ($rows as [$row, $fields]) {
        $source = (string) $row->Get('ionos_source_key');
        verify($source !== '', 'The NAT upgrade fixture must establish collector ownership.');
        $properties = ['id' => (int) $row->GetKey(), 'ionos_source_key' => $source];
        foreach ($fields as $field) {
            $properties[$field] = (string) $row->Get($field);
        }
        if ($bridge && isset($properties['ipaddress_id'])) {
            verify((int) $properties['ipaddress_id'] > 0 && $properties['ipaddress_id'] === $properties['ionos_synced_ip_id'],
                'Expected a collector-owned TeemIP reference.');
        }
        $snapshot[] = $properties;
    }
    return $snapshot;
}

/** Read old records from a fresh PHP process after iTop Setup or the candidate sync. */
function verifyUpgradedNatSnapshot(string $path): void
{
    $snapshot = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    verify(MetaModel::IsValidClass('CloudVpnGateway')
        && MetaModel::IsValidAttCode('CloudVpnGateway', 'ionos_source_key')
        && MetaModel::IsValidAttCode('CloudVpnGateway', 'public_endpoint_id') === $snapshot['bridge'],
        'Expected the candidate models to be compiled with the original bridge selection.');
    verify(natUpgradeSnapshot($snapshot['cloud'], $snapshot['bridge']) === $snapshot['rows'],
        'The NAT upgrade or repeated synchronization changed the original native records.');
}

/** Install only the allowlisted candidate modules and upgrade through the stock iTop CLI. */
function verifyNatUpgrade(string $root, string $directory, string $uuid, int $cloud, int $lan, int $org, bool $bridge, array $modules): void
{
    $input = __DIR__ . '/nat-upgrade-inputs';
    verify(getenv('ITOP_TEST_MODE') === '1' && getenv('ITOP_TEST_DB_HOST') === 'test-db'
        && !MetaModel::IsValidClass('CloudVpnGateway'), 'NAT upgrade requires an isolated installation of the baseline models.');
    foreach (['br-cloud-datamodel', 'br-cloud-datamodel-bridge-for-teemip', 'br-ionos-cloud-datamodel'] as $module) {
        verify(is_file("$input/$module/module.$module.php") && is_writable($root."extensions/$module"),
            "The copy-based upgrade runner requires baseline and candidate module $module.");
    }
    verify(is_file("$input/collectors/main.php"), 'The candidate collector is missing.');
    if ($bridge) {
        $privateBlock = one("SELECT IPv4Block WHERE org_id = :org AND name = 'Manual IPv4 block'", ['org' => $org]);
        natFixtureObject('IPv4Subnet', ['org_id' => $org, 'name' => 'NAT upgrade private subnet',
            'ip' => '192.0.2.0', 'mask' => '255.255.255.0', 'block_id' => $privateBlock->GetKey()]);
        $publicBlock = natFixtureObject('IPv4Block', ['org_id' => $org, 'name' => 'NAT upgrade public block',
            'firstip' => '203.0.113.0', 'lastip' => '203.0.113.255']);
        natFixtureObject('IPv4Subnet', ['org_id' => $org, 'name' => 'NAT upgrade public subnet',
            'ip' => '203.0.113.0', 'mask' => '255.255.255.0', 'block_id' => $publicBlock]);
    }
    // Start the baseline import with complete compute data after the preceding negative tests.
    setPage($directory, $uuid, 'NAT upgrade VDC', false, 200, null);
    setLan($directory, $uuid, 'Renamed LAN', false);
    setLan($directory, SECOND_VDC, null, true);
    setEndpoint($directory, "/datacenters/".SECOND_VDC."/natgateways", []);
    foreach ([$uuid, SECOND_VDC] as $vdc) {
        setEndpoint($directory, "/datacenters/$vdc/networkloadbalancers", []);
    }
    setEndpoint($directory, "/datacenters/$uuid/natgateways", [
        ['id' => NAT_UPGRADE_GATEWAY, 'properties' => ['name' => 'NAT upgrade fixture', 'publicIps' => ['203.0.113.10'],
            'lans' => [['id' => 1, 'gatewayIps' => ['192.0.2.33/24']]]]],
    ]);
    runCollector($directory, true);
    $rows = natUpgradeSnapshot($cloud, $bridge);
    verify($rows[1]['cloud_lan_id'] === (string) $lan && $rows[2]['prefix_length'] === '24',
        'The baseline sync must link the NAT gateway to its LAN and retain the reported prefix.');
    $snapshot = "$directory/nat-upgrade-snapshot.json";
    verify(file_put_contents($snapshot, json_encode(['cloud' => $cloud, 'bridge' => $bridge, 'rows' => $rows],
        JSON_THROW_ON_ERROR)) !== false, 'Cannot store the baseline NAT assertions.');
    echo 'Baseline NAT import and ownership snapshot passed.'.PHP_EOL;

    foreach (['br-cloud-datamodel', 'br-cloud-datamodel-bridge-for-teemip', 'br-ionos-cloud-datamodel'] as $module) {
        $copy = startService(['cp', '-R', "$input/$module/.", $root."extensions/$module"]);
        verify(proc_close($copy) === 0, "Cannot deploy candidate module $module.");
    }

    $setup = $root.'setup/unattended-install/';
    $parameters = simplexml_load_file($setup.'xml_setup/upgrade.xml');
    verify($parameters instanceof SimpleXMLElement, 'Cannot read the iTop upgrade template.');
    unset($parameters->selected_modules, $parameters->selected_extensions);
    $selected = $parameters->addChild('selected_modules');
    $selected->addAttribute('type', 'array');
    foreach (array_keys($modules) as $module) {
        $selected->addChild('item', $module);
    }
    $parameters->addChild('selected_extensions')->addAttribute('type', 'array');
    $parameters->optional_steps->{'log-parameters'} = '0';
    $parameters->sample_data = '0';
    $parameters->previous_configuration_file = $root.'conf/production/config-itop.php';
    $parameters->target_env = 'production';
    $file = "$directory/upgrade.xml";
    verify($parameters->asXML($file) && chmod($file, 0600), 'Cannot write the private Setup response.');
    $process = proc_open([PHP_BINARY, $setup.'unattended-install.php', '--param-file='.$file, '--use_itop_config'], [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', "$directory/setup.log", 'w'], 2 => ['redirect', 1],
    ], $pipes, $root);
    verify(is_resource($process), 'Could not start the iTop Setup upgrade.');
    $status = proc_close($process);
    verify($status === 0, "iTop Setup upgrade exited with status $status.");
    assertNatInFreshProcess($snapshot);
    echo 'iTop Setup upgrade and post-Setup NAT snapshot passed.'.PHP_EOL;

    $plan = file_get_contents("$directory/collectors/resources.json");
    $copy = startService(['cp', '-R', "$input/collectors/.", "$directory/collectors"]);
    verify(proc_close($copy) === 0 && file_put_contents("$directory/collectors/resources.json", $plan) !== false,
        'Could not install the candidate collector with the synthetic API plan.');
    runCollector($directory, true);
    assertNatInFreshProcess($snapshot);
    echo 'NAT baseline import, iTop Setup upgrade, ID and ownership preservation and candidate resynchronization passed.'.PHP_EOL;
}

/** Keep the post-Setup audit separate from the old in-memory PHP datamodel. */
function assertNatInFreshProcess(string $snapshot): void
{
    $token = getenv('IONOS_TOKEN');
    try {
        putenv('IONOS_TOKEN');
        $process = proc_open([PHP_BINARY, __DIR__.'/integration.php', 'verify-nat-upgrade-snapshot', $snapshot], [
            0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR,
        ], $pipes);
        verify(is_resource($process) && proc_close($process) === 0, 'NAT snapshot comparison failed.');
    } finally {
        putenv('IONOS_TOKEN='.$token);
    }
}
