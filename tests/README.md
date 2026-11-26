# Collector checks

From the repository root, run `composer install` with PHP 8.3, then
`composer test` to run the offline PHPUnit suite. To run a single test class,
use `composer test -- --filter CloudIpLinkSyncTest`. The existing
`php tests/run.php` entry point also runs this suite.
Synthetic fixtures cover numeric and opaque pagination, duplicate resources,
missing permissions, empty results, partial parents, native VDC relationship
checks and IPv4/IPv6 observations. Tests also validate release increments, the
distributed XML and the syntax of collector PHP and JSON files.

The [Docker integration test](base-integration.md) runs Combodo's actual PHP
entry point against a local HTTPS fixture.

For an end-to-end VDC, LAN and NAT gateway import using the real Base and disposable iTop 3.3,
run `tests/Invoke-VdcSyncIntegration.ps1 -BasePath <extracted-base-directory>`
from PowerShell. The runner reads the model extensions from the sibling
`iTop-br-cloud-datamodel` and `iTop-br-ionos-cloud-datamodel` repositories.
Use `-CloudDatamodelPath` and `-DatamodelPath` to override their paths.
It tests the generic model and the IONOS add-on in four combinations with
and without TeemIP, using synthetic VDCs, overlapping IDs and test credentials.
The IPv6 allocation scenarios cover prefix changes, explicit release and
preservation of manually assigned IP objects, including a manual link to the exact reported block.

The NAT fixtures in [nat-import.php](nat-import.php) create a gateway with
two public addresses and the private host `192.0.2.33/25` on LAN 1. Repeated
imports exercise a rename, a prefix change, a manual public assignment and,
with TeemIP, creation and reuse of IPAM hosts and preservation of a manually changed reference. A denied NAT list verifies that an
incomplete inventory cannot retire records. A complete withdrawal verifies
that a gateway survives while a manual assignment still needs it.

Use `composer test -- --filter Nat` for focused offline checks of NAT
inventory validation, IP preparation and NAT assignment reconciliation with both models.
