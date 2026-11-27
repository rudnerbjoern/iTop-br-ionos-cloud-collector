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

For end-to-end Cloud network imports using the real Base and disposable iTop 3.3,
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

The same [network load balancer fixture](nlb-import.php) runs in the `Bare`
and `Bridge` variants. It verifies both LAN roles, two listener addresses,
two backend addresses with prefixes, stable IDs on repeat import, a rename and
address replacement, a manual assignment and a denied inventory fetch. The
bridge variant also checks TeemIP host reuse and retention after withdrawal.
Use `-Variant Both` to run these two variants alone, or
`composer test -- --filter Nlb` for offline checks.

The [VPN fixture](vpn-import.php) runs the regional HTTPS lists against the
installed iTop target in the same two variants. It checks public endpoints,
LAN-side host and prefix pairs, WireGuard interface addresses, duplicate source
IDs in different protocols, duplicate LAN IDs in different Clouds, repeat
imports, a rename and manual ownership. It simulates a second-page failure and
an unresolved LAN while a complete list for the other protocol reconciles.
Run `composer test -- --filter Vpn` for focused offline checks.

## NAT model upgrade

The [NAT upgrade fixture](nat-upgrade.php) is opt-in for a disposable runner
that copies runtime modules into a writable installation. Build a separate
test package using the pre-VPN generic and IONOS model snapshots and pre-VPN
collector runtime. Keep the current `vdc-sync.php` and `nat-upgrade.php`
together in the test package as `integration.php` and `nat-upgrade.php`.
Include the normal Base and bridge-mode inputs. From the candidate revisions,
copy the three model module directories and `collectors` into
`.dev/tests/nat-upgrade-inputs/`. Run the test package in both `Bare` and
`Bridge`. The fixture imports NAT using the baseline collector, takes an
ID and ownership snapshot, installs the candidate modules through unattended
iTop Setup, and checks the snapshot in a new process before and after
rerunning NAT with the candidate collector.

The standard locally bind-mounted module paths are read-only. Use the
copy-based test runner for the upgrade fixture so it can replace only the
disposable installation's module files.
