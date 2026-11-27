<#
.SYNOPSIS
Checks the collector against a generic-only installation and IONOS installations with and without the IPv6 bridge.
.PARAMETER BasePath
Extracted, unconfigured Combodo Data Collector Base 1.5.1 sources.
.PARAMETER DevelopmentPath
Sibling iTop 3.3 development environment containing the reserved Docker test runner.
.PARAMETER DatamodelPath
Sibling IONOS provider data model repository for the IONOS variants.
.PARAMETER CloudDatamodelPath
Sibling provider-neutral cloud data model repository, including the optional TeemIP bridge.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$BasePath,
    [string]$DevelopmentPath = "$PSScriptRoot/../../itop-development-3-3",
    [string]$DatamodelPath = "$PSScriptRoot/../../iTop-br-ionos-cloud-datamodel",
    [ValidateSet('All', 'Generic', 'GenericBridge', 'Both', 'Bare', 'Bridge')][string]$Variant = 'All',
    [string]$CloudDatamodelPath = "$PSScriptRoot/../../iTop-br-cloud-datamodel"
)
$ErrorActionPreference = 'Stop'
$base = (Resolve-Path -LiteralPath $BasePath).Path
$development = (Resolve-Path -LiteralPath $DevelopmentPath).Path
$model = (Resolve-Path -LiteralPath $CloudDatamodelPath).Path
$collector = Split-Path -Parent $PSScriptRoot
$scratch = Join-Path $development ('.tmp/vdc-sync-' + [guid]::NewGuid().ToString('N'))
$cloudBundle = Join-Path $scratch 'cloud-bundle'
$inputs = Join-Path $cloudBundle '.dev'
$null = New-Item -ItemType Directory -Path "$inputs/tests", "$inputs/base/conf", "$inputs/module"

function Copy-RuntimeBundle([string]$Source, [string]$Destination) {
    $descriptorPath = Join-Path $Source 'extension.xml'
    [xml]$descriptor = Get-Content -LiteralPath $descriptorPath -Raw
    Copy-Item -LiteralPath $descriptorPath -Destination $Destination
    foreach ($module in $descriptor.extension.modules.module) {
        $name = [string]$module.id
        if ($name -cnotmatch '^[a-z0-9]+(?:-[a-z0-9]+)*$') { throw 'Unexpected data model module ID.' }
        Copy-Item -LiteralPath (Join-Path $Source $name) -Destination $Destination -Recurse
    }
    return [string]$descriptor.extension.extension_code
}
$extensionCode = Copy-RuntimeBundle $model $cloudBundle
Copy-Item -LiteralPath "$model/.dev/tests/native.php" -Destination "$inputs/tests"
Copy-Item -LiteralPath "$PSScriptRoot/vdc-sync.php" -Destination "$inputs/tests/integration.php"
Copy-Item -LiteralPath "$PSScriptRoot/nat-import.php" -Destination "$inputs/tests/nat-import.php"
Copy-Item -LiteralPath "$PSScriptRoot/nlb-import.php", "$PSScriptRoot/vpn-import.php" -Destination "$inputs/tests"
Copy-Item -LiteralPath "$base/core", "$base/exec.php" -Destination "$inputs/base" -Recurse
Copy-Item -LiteralPath "$base/conf/params.distrib.xml" -Destination "$inputs/base/conf"
# Only the public runtime is needed. Local parameters, exports and tokens stay outside the fixture.
Copy-Item -LiteralPath "$collector/collectors/main.php", "$collector/collectors/params.distrib.xml", "$collector/collectors/VdcCollector.json",
    "$collector/collectors/LanCollector.json",
    "$collector/collectors/src" -Destination "$inputs/module" -Recurse
$variants = switch ($Variant) {
    'All' { 'Generic', 'Bare', 'Bridge', 'GenericBridge' }
    'Both' { 'Bare', 'Bridge' }
    default { $Variant }
}
if ('Bare' -in $variants -or 'Bridge' -in $variants) {
    $addon = (New-Item -ItemType Directory -Path "$scratch/ionos-bundle").FullName
    $null = Copy-RuntimeBundle (Resolve-Path -LiteralPath $DatamodelPath).Path $addon
}
foreach ($mode in $variants) {
    [IO.File]::WriteAllText("$inputs/bridge-mode", $mode)
    $options = @{
        Suite = 'Extensions'
        ExtensionPath = $cloudBundle
        ExtensionSuite = 'Integration'
        Profile = 'Vanilla'
    }
    $dependencies = @()
    if ($mode -in @('Bare', 'Bridge')) { $dependencies += $addon }
    if ($mode -in @('Bridge', 'GenericBridge')) { $dependencies += Join-Path $development 'extensions/teemip-core-ip-mgmt' }
    if ($dependencies.Count -gt 0) { $options.DependencyPath = $dependencies }
    & "$development/scripts/tests/Invoke-Tests.ps1" @options
    Copy-Item -LiteralPath "$development/.tmp/test-results/extensions/$extensionCode/integration.xml" `
        -Destination "$scratch/result-$mode.xml"
}
Write-Output "VDC synchronization reports: $scratch"
