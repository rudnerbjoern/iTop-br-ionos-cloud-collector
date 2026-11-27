<?php

/** Register the inventory or target-aware VDC synchronization with Combodo Data Collector Base. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Collector;
use Orchestrator;
use RuntimeException;
use Utils;

require_once __DIR__ . '/src/Run.php';

if (Utils::ReadBooleanParameter('dump_config_only', false)) {
    return;
}
/** Read the IONOS settings shared by the inventory and VDC endpoints. */
function inventoryConfig(): array
{
    $config = [];
    foreach (['ionos_token_env', 'ionos_contract_number', 'ionos_products', 'ionos_api_bases',
        'ionos_timeout', 'ionos_page_size', 'ionos_max_pages'] as $key) {
        $config[$key] = Utils::GetConfigurationValue($key, null);
    }
    return $config;
}

/** Expose the inventory-only phase to the native Combodo orchestration lifecycle. */
final class IonosCloudInventoryCollector extends Collector
{
    /** This collector produces a report without configuring a synchronization data source. */
    public function Init(): void
    {
        // Inventory writes a local report and has no iTop synchronization data source.
    }

    /** Return the display name for the Base logger. */
    public function GetName()
    {
        return 'IONOS Cloud inventory';
    }

    /** Read the installed collector release identifier when supplied by the distribution. */
    public function GetVersion()
    {
        $file = __DIR__ . '/VERSION';
        return is_file($file) ? trim(file_get_contents($file)) : 'development';
    }

    /**
     * @param int $iMaxChunkSize Base CSV chunk size. API pagination uses ionos_page_size.
     * @return bool Whether the local snapshot is complete for all requested collections.
     */
    public function Collect($iMaxChunkSize = 0)
    {
        return Run::execute(rtrim(APPROOT, '/\\'), inventoryConfig());
    }
}

Orchestrator::AddRequirement('8.3.0');
$collectOnly = Utils::ReadBooleanParameter('collect_only', false);
$configureOnly = Utils::ReadBooleanParameter('configure_only', false);
if (Utils::ReadBooleanParameter('synchro_only', false) || ($collectOnly && $configureOnly)) {
    throw new RuntimeException('Use the default command for a fresh VDC sync, or one of --collect_only and --configure_only.');
}
if ($collectOnly) {
    Orchestrator::AddCollector(1, IonosCloudInventoryCollector::class);
    return;
}

require_once __DIR__ . '/src/VdcSync.php';
require_once __DIR__ . '/src/VdcCollector.php';
require_once __DIR__ . '/src/LanSync.php';
require_once __DIR__ . '/src/LanCollector.php';
require_once __DIR__ . '/src/CloudIpLinkSync.php';
require_once __DIR__ . '/src/LinkRestClient.php';
require_once __DIR__ . '/src/NatInventory.php';
require_once __DIR__ . '/src/NatSync.php';
require_once __DIR__ . '/src/NatIpResolver.php';
require_once __DIR__ . '/src/NlbInventory.php';
require_once __DIR__ . '/src/NlbSync.php';
require_once __DIR__ . '/src/VpnSync.php';
require_once __DIR__ . '/src/VmCollector.php';

$org = filter_var(Utils::GetConfigurationValue('ionos_org_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$provider = filter_var(Utils::GetConfigurationValue('ionos_provider_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$org || !$provider) {
    throw new RuntimeException('Set ionos_org_id and ionos_provider_id to existing iTop organization IDs.');
}
if (trim(Utils::GetConfigurationValue('itop_url')) === ''
    || (Utils::GetConfigurationValue('itop_token') === '' && Utils::GetConfigurationValue('itop_login') === '')) {
    throw new RuntimeException('Set the iTop URL and target credentials before running VDC synchronization.');
}
// Base CSVs share a data directory. Keep configuration, collection and synchronization in one exclusive run.
$lock = fopen(Utils::GetDataFilePath('ionos-vdc.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Cannot acquire the VDC synchronization lock in data_path.');
}
register_shutdown_function(static function () use ($lock): void { fclose($lock); });

$client = new LinkRestClient();
$capabilities = VdcSync::targetCapabilities($client->Get(...));
$resolver = new VdcResolver($client->Get(...));
$resolver->organization($org);
$resolver->organization($provider);
$config = inventoryConfig();
$contract = trim((string) ($config['ionos_contract_number'] ?? ''));
if ($contract !== '' && !ctype_digit($contract)) {
    throw new RuntimeException('Invalid IONOS contract number.');
}
$sourceScope = "org $org - provider $provider" . ($contract === '' ? '' : " - contract $contract");
$vdcs = $lans = [];
$servers = $serverErrors = [];
$syncLinks = $syncNatIpv4 = $syncNatIpv6 = $syncNat = $syncNlb = $syncVpn = null;
$resolveCloud = static fn (string $uuid): int => $resolver->cloud($provider, $org, $uuid);
if (!$configureOnly) {
    $report = Run::read(APPROOT, $config, true);
    $compute = $report;
    $compute['scopes'] = array_values(array_filter($report['scopes'],
        static fn ($scope) => $scope['product'] === 'compute' && $scope['template'] !== VmInventory::TEMPLATE));
    foreach ($compute['scopes'] as $scope) {
        if (!$scope['complete']) {
            throw new RuntimeException("Incomplete IONOS inventory for {$scope['path']} ({$scope['status']}). Synchronization stopped.");
        }
    }
    $compute['complete'] = true;
    $vdcs = VdcSync::rows($compute, $resolver, $org, $provider);
    $servers = VmInventory::scopes($report, $vdcs, static function (string $error) use (&$serverErrors): void {
        $serverErrors[] = $error;
    });
    $lans = LanSync::rows($compute, $vdcs);
    $gateways = NatInventory::gateways($compute, $vdcs);
    $balancers = NlbInventory::loadBalancers($compute, $vdcs, $lans);
    $ipam = $capabilities['ipam'] ? new NatIpResolver($client->Get(...), $client->Create(...), $capabilities['ipv6']) : null;
    $resolveIp = $ipam === null ? null : $ipam->resolve(...);
    $store = new InventoryStore($client->Get(...), $client->Create(...), $client->updateInventoryRecord(...),
        $client->deleteInventoryRecord(...));
    $nat = new NatSync($store, "IONOS NAT - $sourceScope", $resolveIp);
    $nlb = new NlbSync($store, "IONOS NLB - $sourceScope", $resolveIp);
    if ($resolveIp !== null) {
        $syncNatIpv4 = static fn () => $nat->prepareAddresses($gateways, $resolveCloud, 4);
        if ($capabilities['ipv6']) {
            $syncNatIpv6 = static fn () => $nat->prepareAddresses($gateways, $resolveCloud, 6);
        }
    }
    $syncNat = static fn () => $nat->apply($gateways, $vdcs, $resolveCloud);
    $syncNlb = static fn () => $nlb->apply($balancers, $vdcs, $resolveCloud);
    $vpn = new VpnSync($store, $sourceScope, $org, $ipam);
    if (in_array('vpn', $report['products'], true)) {
        $syncVpn = static fn () => $vpn->apply($report, $vdcs, $lans, $resolveCloud,
            static fn (string $message) => Utils::Log(LOG_ERR, $message));
    }
    if ($capabilities['ipam'] && $capabilities['ipv6']) {
        $allocations = VdcSync::ipv6Allocations($report['scopes'][0]['items'], $resolver, $org);
        $links = new CloudIpLinkSync($client->Get(...), $client->Create(...), $client->deleteLink(...));
        $syncLinks = static fn () => $links->apply($allocations, $resolveCloud, "IONOS VDC IPv6 - $sourceScope");
    }
}
VdcCollector::setContext($vdcs, "IONOS VDCs - $sourceScope", $syncLinks);
LanCollector::setContext($lans, "IONOS LANs - $sourceScope", $resolveCloud);
VmCollector::setContext($servers, $serverErrors, "IONOS VMs - $sourceScope", $resolveCloud);
require_once __DIR__ . '/src/IonosCollectionPlan.php';
NatIpv4Collector::setOperation($syncNatIpv4);
NatIpv6Collector::setOperation($syncNatIpv6);
NatGatewayCollector::setOperation($syncNat);
NlbCollector::setOperation($syncNlb);
VpnCollector::setOperation($syncVpn);
IonosCollectionPlan::$useVpn = $syncVpn !== null || $configureOnly;
IonosCollectionPlan::$useIpam = $capabilities['ipam'];
IonosCollectionPlan::$useIpv6 = $capabilities['ipv6'];
Orchestrator::UseCollectionPlan(IonosCollectionPlan::class);
