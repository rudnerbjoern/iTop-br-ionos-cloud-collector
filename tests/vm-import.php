<?php

/** Exercise VM reconciliation through the real Base CLI and native iTop import. */
declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests;

use DBObjectSearch;
use DBObjectSet;
use MetaModel;

const VM_A = '7ccabd99-9263-4412-934b-3609bf9957e1';
const VM_B = '44cb5bc9-8f13-473d-a8ad-7764466c23b5';
const VM_C = '60db2b3a-556a-456c-9b0d-a4f6f4572d2c';
const VM_D = 'ecbdfc6e-ed6b-439a-a67c-16654b705909';

function vmServer(string $id, string $name, array $metrics = []): array
{
    return ['id' => $id, 'type' => 'server', 'properties' => ['name' => $name, 'type' => 'ENTERPRISE'] + $metrics];
}

function vmByIdentity(string $vdc, string $server)
{
    return one('SELECT VirtualMachine WHERE ionos_vdc_id = :vdc AND ionos_server_id = :server', [
        'vdc' => $vdc, 'server' => $server,
    ]);
}

function vmReplicaOwners(int $vm): array
{
    $replicas = new DBObjectSet(DBObjectSearch::FromOQL(
        "SELECT SynchroReplica WHERE dest_class = 'VirtualMachine' AND dest_id = :vm"), [], ['vm' => $vm]);
    $sources = [];
    while ($replica = $replicas->Fetch()) {
        $sources[] = (int) $replica->Get('sync_source_id');
    }
    return $sources;
}

function manualVm(string $name, int $host, int $org): int
{
    return natFixtureObject('VirtualMachine', ['name' => $name, 'org_id' => $org, 'virtualhost_id' => $host]);
}

function verifyManualVm(int $id, array $owners = []): void
{
    $vm = MetaModel::GetObject('VirtualMachine', $id);
    verify((string) $vm->Get('ionos_source_key') === '' && (string) $vm->Get('ionos_server_id') === ''
        && (string) $vm->Get('ionos_vdc_id') === '' && vmReplicaOwners($id) === $owners,
        'An ineligible or ambiguous reservation changed.');
}

/** Seed a replica through the native import endpoint, including an interrupted import without execution. */
function nativeFixtureVmRow(int $sourceId, int $vm, string $name, bool $execute): void
{
    $request = curl_init('http://127.0.0.1:8082/synchro/synchro_import.php?login_mode=form');
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'auth_user' => 'admin', 'auth_pwd' => getenv('ITOP_TEST_ADMIN_PASSWORD'),
            'data_source_id' => $sourceId, 'csvdata' => "primary_key;name\n$vm;$name\n",
            'output' => $execute ? 'summary' : 'retcode', 'synchronize' => $execute ? '1' : '0',
        ]),
    ]);
    $result = curl_exec($request);
    $output = is_string($result) ? trim(strip_tags($result)) : '';
    $accepted = $execute
        ? str_contains($output, '#Created (before synchro): 1')
            && str_contains($output, '#Objects reconciliation errors: 0')
            && str_contains($output, '#Objects created: 0')
        : $output === '0';
    verify(curl_getinfo($request, CURLINFO_RESPONSE_CODE) === 200 && $accepted,
        'The native fixture import did not accept the synthetic VM row.');
}

function verifyVmFailures(string $dir, string $vdc, int $host, int $otherHost, int $org, int $source,
    int $reservation, int $secondId): void
{
    $path = "/datacenters/$vdc/servers";
    $otherPath = '/datacenters/'.SECOND_VDC.'/servers';
    // Leave an old modified replica ready to run, then fail the provider's second page for its VDC.
    nativeFixtureVmRow($source, $reservation, 'Stale queued VM name', false);
    setEndpoint($dir, $path, [vmServer(VM_A, 'Unverified adopted VM', ['ram' => 8192]), vmServer(VM_B, 'Unseen VM')],
        pageTwoStatus: 403);
    setEndpoint($dir, $otherPath, [vmServer(VM_A, 'Healthy VM after other VDC failed')]);
    $output = runCollector($dir, false);
    $retained = vmByIdentity($vdc, VM_A);
    verify(str_contains($output, "Incomplete IONOS server listing for VDC $vdc")
        && $retained->Get('name') === 'Renamed fixture VM' && $retained->Get('ram') === '2048 MB'
        && vmReplicaOwners($reservation) === [$source]
        && vmByIdentity(SECOND_VDC, VM_A)->Get('name') === 'Healthy VM after other VDC failed',
        'An incomplete list or stale replica must not alter its VM, while the other VDC can synchronize.');

    $left = manualVm('Ambiguous reservations', $host, $org);
    $right = manualVm('Ambiguous reservations', $host, $org);
    $shared = manualVm('Contested reservation', $otherHost, $org);
    setEndpoint($dir, $path, [vmServer(VM_B, 'Ambiguous reservations')]);
    setEndpoint($dir, $otherPath, [vmServer(VM_C, 'Contested reservation'), vmServer(VM_D, 'Contested reservation')]);
    $output = runCollector($dir, false);
    verify(str_contains($output, 'Multiple VM reservations match') && str_contains($output, 'Multiple IONOS servers claim'),
        'Both directions of reservation ambiguity must be reported.');
    foreach ([$left, $right, $shared] as $id) {
        verifyManualVm($id);
    }
    $ambiguous = new DBObjectSet(DBObjectSearch::FromOQL(
        "SELECT VirtualMachine WHERE name IN ('Ambiguous reservations', 'Contested reservation')"));
    verify($ambiguous->Count() === 3, 'Ambiguous incoming identities must create no VMs.');

    $name = 'VM reservation excluded by scope or ownership';
    $wrongOrg = natFixtureObject('Organization', ['name' => 'Synthetic alternate VM customer']);
    $foreignOrgVm = manualVm($name, $host, $wrongOrg);
    $otherHostVm = manualVm($name, $otherHost, $org);
    $foreignOwnedVm = manualVm($name, $host, $org);
    $foreignSource = natFixtureObject('SynchroDataSource', [
        'name' => "Fixture foreign VM source $source", 'scope_class' => 'VirtualMachine', 'status' => 'production',
        'reconciliation_policy' => 'use_primary_key', 'action_on_zero' => 'error', 'action_on_one' => 'update',
        'action_on_multiple' => 'error', 'delete_policy' => 'ignore', 'full_load_periodicity' => 0,
    ]);
    nativeFixtureVmRow($foreignSource, $foreignOwnedVm, $name, true);
    verify(vmReplicaOwners($foreignOwnedVm) === [$foreignSource], 'Foreign source fixture lacks native ownership.');
    setEndpoint($dir, $path, [vmServer(VM_B, $name)]);
    setEndpoint($dir, $otherPath, []);
    runCollector($dir, true);
    $created = vmByIdentity($vdc, VM_B);
    verify(!in_array((int) $created->GetKey(), [$foreignOrgVm, $otherHostVm, $foreignOwnedVm], true),
        'The VM import adopted a reservation from another Cloud, organization or synchronization source.');
    verifyManualVm($foreignOrgVm);
    verifyManualVm($otherHostVm);
    verifyManualVm($foreignOwnedVm, [$foreignSource]);

    // Keep a same-name reservation available to detect an improper identity-to-name fallback.
    $moved = vmByIdentity($vdc, VM_A);
    $moved->Set('virtualhost_id', $otherHost);
    $moved->DBUpdate();
    $fallback = manualVm('Could claim reservation after move', $host, $org);
    setEndpoint($dir, $path, [vmServer(VM_A, 'Could claim reservation after move')]);
    $output = runCollector($dir, false);
    verify(str_contains($output, 'Conflicting IONOS server identity') && vmByIdentity($vdc, VM_A)->GetKey() == $reservation
        && (int) vmByIdentity($vdc, VM_A)->Get('virtualhost_id') === $otherHost,
        'A moved identity must be reported and left untouched.');
    verifyManualVm($fallback);
    verify(vmReplicaOwners($reservation) === [$source] && vmReplicaOwners($secondId) === [$source],
        'Rejected scope and conflict checks must retain native ownership.');
    setEndpoint($dir, $path, []);
    runCollector($dir, true);
    echo "Native VM incomplete scopes, interrupted replica, exclusions and ambiguity passed.\n";
}

function verifyVmImport(string $dir, string $vdc, int $host, int $otherHost, int $org, int $provider): void
{
    $name = 'Fixture same-name VM';
    $path = "/datacenters/$vdc/servers";
    $otherPath = '/datacenters/'.SECOND_VDC.'/servers';
    $source = 'IONOS VMs - org '.$org.' - provider '.$provider;
    $sourceId = (int) one('SELECT SynchroDataSource WHERE name = :name', ['name' => $source])->GetKey();
    setPage($dir, $vdc, 'Equal cloud display name', false, 200, null, 'Equal cloud display name');
    $reservation = natFixtureObject('VirtualMachine', ['name' => $name, 'org_id' => $org,
        'virtualhost_id' => $host, 'business_criticity' => 'high']);
    $interface = natFixtureObject('LogicalInterface', ['name' => 'Existing VM interface', 'virtualmachine_id' => $reservation]);
    setEndpoint($dir, $path, [vmServer(VM_A, $name), vmServer(VM_B, 'Second page fixture')], pageTwoStatus: 403);
    setEndpoint($dir, $otherPath, [vmServer(VM_A, $name)]);
    $output = runCollector($dir, false);
    verify(str_contains($output, "Incomplete IONOS server listing for VDC $vdc"), 'Expected the failed server page to be reported.');
    verifyManualVm($reservation);
    setEndpoint($dir, $path, [vmServer(VM_A, $name, ['cores' => 4, 'ram' => 2048])]);
    setEndpoint($dir, $otherPath, [vmServer(VM_A, $name)]);
    runCollector($dir, true);
    $first = vmByIdentity($vdc, VM_A);
    $second = vmByIdentity(SECOND_VDC, VM_A);
    $secondId = (int) $second->GetKey();
    verify((int) $first->GetKey() === $reservation && $secondId !== $reservation
        && $first->Get('ram') === '2048 MB' && $first->Get('cpu') === '4' && $first->Get('ionos_server_type') === 'ENTERPRISE'
        && (string) $second->Get('ram') === '' && (string) $second->Get('cpu') === '', 'VM metrics or reservation binding differ.');
    foreach ([[$host, $first], [$otherHost, $second]] as [$cloud, $vm]) {
        $cloudObject = MetaModel::GetObject('Cloud', $cloud);
        $listed = $cloudObject->Get('virtualmachine_list');
        verify($listed->Count() === 1 && $listed->Fetch()->GetKey() == $vm->GetKey()
            && $vm->Get('ionos_source_key') === $source && (int) $vm->Get('org_id') === $org
            && vmReplicaOwners((int) $vm->GetKey()) === [$sourceId], 'Incorrect VM placement or synchronization ownership.');
    }
    runCollector($dir, true);
    setEndpoint($dir, $path, [vmServer(VM_A, 'Renamed fixture VM')]);
    runCollector($dir, true);
    $first = vmByIdentity($vdc, VM_A);
    verify((int) $first->GetKey() === $reservation && $first->Get('name') === 'Renamed fixture VM'
        && (int) $first->Get('virtualhost_id') === $host && $first->Get('cpu') === '4' && $first->Get('ram') === '2048 MB'
        && $first->Get('business_criticity') === 'high' && (int) vmByIdentity(SECOND_VDC, VM_A)->GetKey() === $secondId,
        'Renaming must preserve identity, placement, omitted metrics, and unmanaged fields.');
    verify((int) MetaModel::GetObject('LogicalInterface', $interface)->Get('virtualmachine_id') === $reservation,
        'Adopting a reservation must preserve its existing relationship.');
    setEndpoint($dir, $path, []);
    setEndpoint($dir, $otherPath, []);
    runCollector($dir, true);
    verify(vmReplicaOwners($reservation) === [$sourceId] && vmReplicaOwners($secondId) === [$sourceId],
        'A complete empty response must retain VMs and their synchronization ownership.');
    echo "Native VM import, reservation adoption, placement, rename and metrics passed.\n";
    verifyVmFailures($dir, $vdc, $host, $otherHost, $org, $sourceId, $reservation, $secondId);
}
