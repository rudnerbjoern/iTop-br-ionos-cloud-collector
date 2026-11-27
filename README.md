# IONOS Cloud data collector for iTop

The PHP 8.3 collector reads IONOS Cloud inventory and produces a private snapshot
with explicit coverage for each product endpoint and parent resource. Synchronization
uses the provider-neutral iTop 3.3 cloud model and its IONOS add-on.
The inventory phase works independently of iTop.

- [Shared cloud inventory architecture](https://github.com/rudnerbjoern/iTop-br-cloud-datamodel/blob/main/docs/architecture/README.md)
- [Resource catalog and iTop mappings](docs/resource-catalog.md)
- [Design and synchronization contract](docs/design.md)
- [Offline checks](tests/README.md) and [Collector Base integration test](tests/base-integration.md)

## Local inventory

Enable PHP 8.3 with cURL, JSON and SimpleXML. Set `IONOS_TOKEN` in the process
environment or in the ignored `.env` at the repository root. See
[the environment template](.env.example). The token is used only for HTTPS GET
requests to the origins listed in [the endpoint plan](collectors/resources.json).

From the repository root run:

```sh
php scripts/inventory.php
```

The report and its coverage summary are saved under
`.tmp/inventory/<timestamp>-<run-id>/` as `inventory.json` and `coverage.md`.
Exit code 0 means every requested scope was read, 2 means a partial snapshot was
saved, and 1 means configuration or persistence failed. A 200 response can only
describe the resources visible to the supplied identity. The report includes
provider reference data, such as locations, versions and public images.
Observations can repeat across API versions and attachment relationships.

## Configuration

Copy [the deployment template](conf/params.example.xml) to
`conf/params.local.xml` under the installation root that runs the command.
The standalone inventory and the Base both read this file. The Base merges its
own defaults, the [IONOS module defaults](collectors/params.distrib.xml) and
the local file in that order. Its `--config_file=path` option selects a
different local file for a run.

### IONOS source

- `ionos_token_env` — Name of the variable holding the IONOS bearer token. Defaults to `IONOS_TOKEN`. The process value takes precedence over the installation-root `.env`.
- `ionos_contract_number` — Optional numeric contract for Cloud API v6. Regional services use the scope granted to the token.
- `ionos_products` — `all` or comma-separated product keys such as `compute,dbaas/psql`.
- `ionos_api_bases` — `all` or exact comma-separated URLs from the selected products in the [endpoint plan](collectors/resources.json). Only matching product and base pairs run.
- `ionos_timeout`, `ionos_page_size`, `ionos_max_pages` — Request timeout in seconds (1–120), requested page size (1–100) and maximum pages per collection (1–10,000).

For example, select `dbaas/psql` and `https://postgresql.de-txl.ionos.com/v2`
to inventory PostgreSQL v2 in Berlin. A filtered report covers the selected
bases only. It records those bases alongside the product keys.

### iTop target and synchronization

The template includes the native [Combodo Base parameters](https://github.com/Combodo/itop-data-collector-base/blob/1.5.1/conf/params.distrib.xml).
The standalone inventory requires only the IONOS settings. The default Base
command imports virtual datacenters, their LANs, NAT gateways and network load balancers with their addresses.

- `ionos_org_id` — Existing iTop customer organization ID for all VDCs visible to this configuration. Use one configuration and a matching source contract or token per customer.
- `ionos_provider_id` — Existing iTop provider organization ID for IONOS. Its locations must use the exact API codes, such as `de/txl`.
- `itop_url` — HTTPS root of the iTop installation, e.g. `https://cmdb.example.org/itop`.
- `itop_token` or `itop_login` and `itop_password` — iTop credentials. A populated iTop token takes precedence. Keep credentials in the private deployment configuration.
- `itop_login_mode` — Leave empty for the Base to select `token` or `form` from the credentials.
- `collectors_launch_sequence` — Base synchronization order. The defaults run VDCs (1), LANs (2),
  TeemIP IPv4 hosts (7), TeemIP IPv6 hosts (8), NAT gateways (22) and network load balancers (23).
  The bridge enables the IP phases automatically, with IPv6 hosts requiring IPv6 management. Each enabled phase requires its parents earlier in the sequence.
- `synchro_user`, `contact_to_notify` — Login allowed to run synchronization and optional email of an existing iTop contact. With token authentication, specify the login explicitly to assign this user.
- `max_chunk_size`, `itop_synchro_timeout`, `stop_on_synchro_error` — Rows per batch, request timeout in seconds and whether to stop on synchronization errors. The module defaults are 1,000, 600 and `yes`.
- `data_path`, `date_format` — Base staging directory and date format for synchronization data. Local inventory reports use `.tmp/inventory/`.
- `curl_options` — HTTPS options used by the Base for iTop. The module enables certificate and hostname verification.
- Logging — Console, syslog and iTop event levels plus console date format. Base 1.5.1 prints XML credentials with `--dump_config_only` and at debug level 7.

## Combodo Data Collector Base

Install the [official Base package](https://store.itophub.io/en_US/products/itop-data-collector-base)
in a private directory outside the web root. Copy the contents of this project's
`collectors/` into the Base installation's `collectors/`. Copy the project's
`VERSION` into `collectors/VERSION` for release identification. Supply
`IONOS_TOKEN` to this installation and run its entry point:

```sh
php exec.php --collect_only
```

This command and the standalone script read inventory independently of iTop.
A partial inventory returns a failure and saves its coverage report.

### Synchronize virtual datacenters and network inventory

Install both model extensions at version 0.2.0 or later in the target iTop.

- `iTop-br-cloud-datamodel` provides the `br-cloud-datamodel` module, the LAN, NAT gateway and network load balancer classes and the optional TeemIP bridge.
- `iTop-br-ionos-cloud-datamodel` provides the `br-ionos-cloud-datamodel` module. It adds IONOS access protection, network inventory ownership and optional provider and location samples.

The collector checks both modules before configuring either synchronization source.
Give the service account
the `REST Services User` profile and permission to read module installations
and referenced objects, administer both synchronization sources and import Clouds and LANs.
NAT inventory also requires REST read, create, update and delete access to
`CloudNatGateway`, `CloudNatGatewayLan`, `CloudNatGatewayLanAddress` and
`CloudNatGatewayPublicAddress`. Network load balancer inventory requires those
permissions for `CloudNetworkLoadBalancer`, `CloudNetworkLoadBalancerListenerAddress`
and `CloudNetworkLoadBalancerBackendAddress`. The account needs read access to the customer’s
Clouds and LANs. With the TeemIP bridge, grant read access to the customer's
addresses, subnets, blocks and Cloud-to-IP-object links, and create access to
`IPv4Address` and, when IPv6 management is installed, `IPv6Address`.
For IPv6 allocation import, grant the account access to read TeemIP IPv6 blocks
and to manage individual `lnkCloudToIPObject` records.
Fill in the iTop credentials and organization IDs in the private parameters.
The IONOS product and endpoint selection must include the Compute Cloud API v6.

- `php exec.php --configure_only` detects target modules and creates or updates both synchronization sources.
- `php exec.php` reads the VDC list and each VDC's LAN, NAT gateway and network load balancer lists. It imports Clouds and LANs through the two data sources, then reconciles their network inventory through the iTop REST API.

The Cloud source matches the IONOS provider and VDC UUID
(`provider_id` and `provider_resource_id`). It owns the UUID, name, customer, provider, location and additional
access protection. An absent protection value maps to `unknown`. Existing CIs
keep their lifecycle and business criticality. A complete empty list leaves
existing CIs in place. Incomplete VDC, LAN, NAT gateway or network load balancer lists stop the inventory import.
This collector requires fresh data for this import, so use the default command for
synchronization. A customer, provider and optional contract identify the source
independently of the presence of the bridge.

With the neutral TeemIP bridge and TeemIP IPv6 Management installed, the collector
matches the VDC prefix to an existing customer-owned IPv6 block by both boundaries
of the /56. It updates individual links in `Cloud.ip_objects_list`. It marks each
link it creates with its source key. A matching manual link already satisfies
the allocation and stays manual. A prefix change or an explicit null allocation
can remove only this source's previous link for that VDC. Manual IPv4 blocks,
IPv6 blocks and subnets stay in the list. An omitted allocation or unresolved
block stops the import. Standalone inventory records the provider prefix independently.

The LAN source resolves its parent within the configured provider, then matches
`CloudLan.cloud_id` and the LAN's local `provider_resource_id`. It imports the
LAN name, the customer organization of the Cloud and the public flag.
An unnamed LAN is displayed as `LAN <ID>`. LANs work with both installation variants.
[Upstream Base](https://github.com/Combodo/itop-data-collector-base/tree/1.5.1)

### NAT gateway inventory

Open a NAT gateway to see its public addresses and private LAN attachments.
Each attachment lists the gateway’s private host addresses and their reported
prefix lengths. Source NAT rules and flow log settings remain in the inventory
report. This import uses the existing customer, provider and contract settings.

A complete inventory can remove a withdrawn NAT record created by the same
collector configuration. Without TeemIP, assignments store the host as an IP
literal. With the bridge, each assignment references a TeemIP address. The IP
collector reuses an existing host or creates one in a uniquely matching customer
subnet. Private addresses require a subnet or its parent block linked to the
VDC. NAT withdrawal preserves manual assignments and the shared IPAM objects.
[NAT reconciliation and ownership](docs/design.md#nat-gateway-reconciliation)

### Network load balancer inventory

Open **Network load balancers** on the Cloud. The load balancer shows its
listener and backend LANs and the host addresses on each LAN. The backend
list holds the load balancer's own host addresses and their reported prefix
lengths. Updates preserve the load balancer ID, manual assignments and shared
TeemIP address objects.

The optional TeemIP bridge resolves private listener and backend hosts in
the customer's networks associated with the Cloud. Public listener hosts
resolve in the customer's IP space. Keep `BRIonosNlbCollector` enabled after
`BRIonosLanCollector` when customizing the launch sequence.
[Load balancer reconciliation](docs/design.md#network-load-balancer-reconciliation)

## Development

See [release preparation](scripts/README.md). Install the locked development dependencies and run the local checks:

```sh
npm ci --cache .tmp/npm-cache
npm run lint:md
npm run lint:spelling
composer install
composer test
```
