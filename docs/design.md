# Inventory and CMDB design

This collector answers three separate questions. Which services does IONOS offer? Which resources can a particular credential see? Which observed resources can be reconciled safely with objects in iTop? The [resource catalog](resource-catalog.md), local inventory report and proposed mappings below address these questions in that order.

## Collection boundary

The runtime is PHP 8.3 under the `BR\IonosCloud\Collector` namespace. It uses PHP cURL and JSON for the IONOS APIs. The `ionos_token_env` setting selects the bearer credential from the process or the ignored installation-root `.env`. Its default is `IONOS_TOKEN`. XML settings select products, API bases and request limits. The [deployment template](../conf/params.example.xml) also contains the native iTop connection and synchronization parameters. Credentials stay out of distributed files and inventory reports.

Combodo's external Data Collector Base loads `collectors/main.php` from its own `exec.php`. The Azure and Intune collectors register a collection plan with the Base orchestrator. IONOS registers a collector with the same orchestrator and uses `--collect_only` for a local snapshot. The standalone PHP inventory command and the Base entry point share the same inventory engine. The default Base command synchronizes VDCs and their LANs through ordered data sources for `Cloud` and `CloudLan`. The Base reads `collectors_launch_sequence` from the XML parameters. The VDC and LAN sources have ranks 1 and 2. With TeemIP, separate IPv4 and IPv6 collectors prepare host objects at ranks 7 and 8. Rank 22 imports the gateway topology through REST and links those hosts. This follows the ordering of Combodo’s vSphere collection plan. [vSphere launch sequence](https://github.com/Combodo/itop-data-collector-vsphere/blob/master/params.distrib.xml) The optional IPv6 allocation import manages individual Cloud-to-IP-object links through the iTop REST API. A source key identifies the links created by that collector configuration. The Cloud CSV omits the IP object list. Synchronization requires both the generic cloud module and the IONOS add-on. [Base entry point][base] · [Azure collection plan][azure] · [Intune collection plan][intune]

The resource plan selects official HTTPS bases and GET paths. Child URLs use encoded provider identifiers obtained from their listed parents. A pagination link never selects a new destination for an authorization header. Explicit product selection reduces the set of API calls. Counts apply only to the services, locations, identity and time represented by that selection. See the [runtime plan](../collectors/resources.json).

## Reading an inventory report

Reports live under `.tmp/inventory/<run>/inventory.json`. Each run records start and end time, selected products, the plan fingerprint and results per scope. A scope is one collection operation against one API base and concrete parent. Each row contains the provider ID and projected inventory attributes. An IP observation additionally records the source field, address family, address and any prefix. API response bodies can contain additional values that are unnecessary for CMDB reconciliation.

- **Present.** The endpoint was traversed successfully and returned rows. Check whether those rows represent customer assets, relationships or provider reference data in the resource catalog.
- **Empty.** The collection completed and returned zero items for that scope. When a complete parent list is empty, a child scope can instead record `no_parents` without making a request.
- **Unavailable.** A forbidden or unauthenticated request, a missing endpoint, a transport failure or a rate limit leaves the scope incomplete. For a v1 API, HTTP 404 or 410 leaves the inventory for that scope unknown. Consult the regional migration notice and inventory the matching v2 endpoint where available.
- **Partial.** Later pages can fail after some rows have been read. An inconsistent count, repeated identity, malformed record or unresolved continuation also leaves the scope incomplete. Previously observed rows can assist diagnosis. A missing or incomplete parent inventory prevents a full child inventory.
- **Documented only or outside selection.** No request was made for the product or resource family. The catalog of capabilities may be more extensive than the scopes in any one run.

A report can contain a mixture of outcomes. Inspect `complete` on the report and on its scopes together with `product`, `base`, `template`, `path`, `status` and the page count. Summing every row overstates asset counts because the same resource can appear in a membership list, a parent enumeration and overlapping API generations. Resources can change between API calls. A completed run establishes what was visible during its collection interval.

## Ownership and identity for synchronization

1. **Resolve the customer first.** Associate each configured source contract or VDC with an existing iTop `Organization`. Store IONOS as a separate provider organization. Treat missing or ambiguous customer mappings as unresolved. An account, contract, service location and iTop customer can each have different scopes.
2. **Reconcile resources by stable identity.** Use the provider's ID with its documented namespace. Include the source contract or collection instance, resource type, authoritative service scope and parent ID for parent-local identifiers. A LAN with ID `1` in one VDC has no identity relationship with LAN `1` in another VDC. For global IDs, preserve region and endpoint as observation provenance. Establish identity across v1 and v2 before merging or counting objects from both APIs.
3. **Resolve relationships before import.** Use known provider IDs and scoped parent paths. Examples include server-to-VDC, attached-volume-to-server, NIC-to-LAN and pool-to-cluster. An IP-only backend match requires a unique interface in the correct address space. Keep unresolved references as source observations. DNS names and display names alone can be ambiguous or change.
4. **Choose field authority.** Provider IDs, observed topology and allocated capacity can be source-owned. iTop teams may retain lifecycle, criticality, business service, contract links and support ownership. An IONOS power state is separate from an iTop lifecycle state.
5. **Define one synchronization policy per object type.** Use native iTop synchronization sources when implementation is approved. Require complete scopes for the configured authoritative source before allowing full-load retirement. Stop on incomplete parent coverage, an access or contract change or ambiguous mapping. A complete empty list still needs a deliberate owner-approved retirement policy. [iTop 3.3 synchronization reference][synchro]

## NAT gateway reconciliation

The synchronization run reads all pages of the VDC list and each VDC’s LAN and
NAT gateway lists before importing the topology. An explicitly empty NAT list
is authoritative for that VDC. A missing list, a failed page or an invalid
attachment stops the import. The run can reconcile a gateway against its
parent after the native Cloud and LAN data sources have succeeded.

| iTop class                     | Identity within this import               | Source fields                         |
| ------------------------------ | ----------------------------------------- | ------------------------------------- |
| `CloudNatGateway`              | Cloud and gateway UUID                    | Gateway name and provider resource ID |
| `CloudNatGatewayLan`           | Gateway and private Cloud LAN             | `lans[].id` in the gateway’s VDC      |
| `CloudNatGatewayLanAddress`    | LAN attachment and canonical host address | `gatewayIps[]` host and prefix length |
| `CloudNatGatewayPublicAddress` | Gateway and canonical host address        | `publicIps[]` host                    |

A rename updates the existing gateway. A changed private prefix updates the
existing assignment. A reported private IP belongs to its LAN attachment even
when another VDC uses the same address or LAN number.

The IONOS module stores the customer, provider and optional contract in the
`ionos_source_key` of each row the collector creates. A matching manual or
foreign-source row retains its ownership. Once a VDC’s complete inventory
confirms a withdrawal, cleanup works from address assignments back towards the
gateway. A manual child keeps its parent. A VDC outside this run supplies no
evidence for removal.

Without TeemIP the assignment key uses the canonical host in `address`.
The neutral TeemIP bridge replaces this field with the required `ipaddress_id`.
The IP collector resolves existing hosts and creates a missing host in a unique
containing subnet in the customer organization. Private hosts also require a
subnet linked to the Cloud or a subnet whose parent block is linked to the
Cloud. The private prefix reported by IONOS belongs to the assignment. The
subnet's configured prefix determines membership in TeemIP.

The NAT collector uses the prepared host IDs. A missing or ambiguous subnet
for a new host stops the IP phase. Several assignments can reference the same
host, and removing an assignment preserves its IPAM object. The technical
`ionos_synced_ip_id` records the original target of a collector-created
assignment. If someone selects a different host, that assignment stays in the
CMDB. A subsequent observation of the original host can create its own assignment.

## Native iTop and TeemIP mappings

The base data model requires the iTop 3.3 virtualization and container modules. The optional bridge links Clouds to existing blocks and subnets when the generic cloud module and TeemIP IP Management are installed. The IONOS allocation import also requires TeemIP IPv6 Management. Other TeemIP mappings depend on the corresponding IP management and interface modules in the target installation. Review the installed model before defining source mappings. [iTop virtualization][virtual] · [iTop containers][containers] · [TeemIP descriptor][teemip]

### Compute, location and connectivity

- **VDC → `Cloud`.** This concrete `VirtualHost` subclass inherits the VM list. Use `org_id` for the customer, `provider_id` for IONOS and, where the provider region maps to a managed location, `location_id` for that provider's `Location`. A region can serve many VDCs. [Cloud model][virtual]
- **Server → `VirtualMachine`.** Its required `virtualhost_id` can point directly to the VDC's `Cloud`. Preserve provider identity across renames. Populate comparable CPU and memory values using documented units. **NIC → `LogicalInterface`** requires `virtualmachine_id`. The TeemIP adapter supplies interface integration. [Virtualization model][virtual] · [TeemIP virtualization adapter][adapter]
- **LAN → `CloudLan`.** Resolve `cloud_id` within the IONOS provider using the VDC UUID. Reconcile on the resulting Cloud and the local LAN `provider_resource_id`. Use the customer organization of the parent Cloud. The native `VLAN` class requires `vlan_tag` and `org_id`. A provider's LAN number alone does not prove the network's IEEE 802.1Q tag. A native `VLAN` can be linked after an explicit, validated mapping. [Configuration management model][core]
- **Storage.** `LogicalVolume` requires `name`, `lun_id` and `storagesystem_id`. The Cloud API volume ID and capacity alone do not identify a real storage system or LUN. Keep an independently observed volume and server attachment until that gap is resolved. `NASFileSystem` requires `name` and `nas_id`. An NFS share is a candidate once the native NAS parent is established. [Storage model][storage]
- **Network appliances.** `NetworkDevice` with its device type is a native candidate for basic appliance inventory. iTop supplies the Load Balancer type. The NAT gateway import uses the four classes described above. Network and application load balancers expose distinct LAN roles, address lists, rules and target membership in the inventory report. A proposal for extra classes or relationships should describe exactly which business question the native model cannot answer. [Network device model][core] · [Network resource catalog](resource-catalog.md#networking-dns-and-security)

### Container classes and relationships

- **Cluster → `ContainerCluster`.** A managed cluster is a candidate for this concrete subclass of abstract `ContainerVirtualHost`. The target inherits `FunctionalCI` name and organization. Use the cluster's provider ID as the source key. Node pools describe scheduling, placement and capacity. A pool and a cluster must retain separate identities.
- **Node → `ContainerHost`.** The required `system_id` targets a `FunctionalCI` and the native selector admits `Server`, `VirtualMachine` and `Cloud`. Select the actual backing VM or server when its identity is known. A `Cloud` target is possible where the deployment semantics justify it. A region alone is insufficient. `containercluster_id` links the host to a cluster in the same organization. `role` distinguishes master, worker and standalone hosts. A pool may reference several nodes and may reference a VDC independently of the cluster.
- **Registry artifact → `ContainerImage`** when confirmed as a container image. iTop requires `name`, `publisher`, `version` and `image`. A source needs a justified value for each. Reconcile immutable artifacts using the registry, repository and manifest digest. Record mutable tags as observations. A repository namespace can be used as the publisher only after an ownership mapping. An artifact with an unknown publisher or ambiguous media type remains unresolved. `ContainerImageType` and `Software` are optional links. `ContainerImage` derives directly from `cmdbAbstractObject`.
- **Deployment → `ContainerApplication`** when workload evidence becomes available. Its required `containervirtualhost_id` points to a `ContainerVirtualHost`. Native iTop computes the inherited `system_id` from this value. The native `lnkContainerApplicationToImage` relation links an actual application to the images it uses. The infrastructure and registry APIs do not establish this runtime use. [iTop 3.3 container classes and selectors][containers]

### IP address management

- **Normalize facts before reconciliation.** `ipFacts` retains literals, address family, origin field and optional prefix. Preserve whether an address was assigned to a NIC, exposed by a gateway, listed as a listener, used by a backend or present in a firewall rule. Matching text in two fields does not automatically identify the owner of that address.
- **IPv4 and IPv6 addresses.** Use `IPv4Address` and `IPv6Address`. Resolve an existing customer address space and, where available, its subnet and network context. Compare canonical addresses. The `org_id` on `IPObject` is mandatory. The native models support subnet references on addresses. Overlapping private space across VDCs or routing domains can make even an organization plus address ambiguous. Keep the scope unresolved until the subnet or VRF can distinguish the address.
- **Subnets and blocks.** `IPv4Subnet` and `IPv6Subnet` require a same-family parent `block_id`, IP and mask. Choose `IPv4Block` or `IPv6Block` only for an evidenced administrative allocation. A single host address with a prefix, a NAT source or destination filter, a backup connection or a list of reserved addresses does not establish an administratively owned subnet or block. Create native ranges only with evidence of the interval and parent.
- **Links.** Reuse `lnkIPInterfaceToIPAddress` between an existing IP interface and address. TeemIP's native `lnkIPSubnetToVLAN` and VRF relations support explicit subnet associations. A VM's `managementip_id` is a reference to an identified management IP. A VM with four assigned addresses still needs evidence of which address serves that role. [TeemIP IPv4 model][ip4] · [TeemIP IPv6 model][ip6] · [TeemIP virtualization adapter][adapter]

### Other managed services

- **Databases.** `DBServer` is a `SoftwareInstance` with a required host. `DatabaseSchema` has a required `dbserver_id`. These are possible targets for software discovered on a known system and a database owned by that server. A managed database cluster with an opaque backing host remains a cluster observation. A database engine version can enrich a matching object without inventing a server. [Configuration management model][core]
- **DNS, circuits and networks.** TeemIP `Domain` is a candidate for a customer-owned DNS domain. A hosted secondary zone or DNS record is a different entity from domain registration. TeemIP `WANLink` can describe a validated carrier circuit and `VRF` a verified routing context. A service product name, VPN endpoint or private LAN alone cannot substantiate those relationships. [TeemIP network model][network]
- **Other resource families.** Observe the service instance or configuration with its native IONOS identity for certificates, distributions, scaling groups, backups, pipelines, topics and gateways. Reference catalogs such as versions, regions and model offerings are useful for enrichment. A product-specific CI proposal needs a defined lifecycle, owner, relationship and intended CMDB use. [Resource catalog](resource-catalog.md)

## Evidence required before enabling CMDB writes

Use an isolated iTop 3.3 installation to test the base model on its own and again with TeemIP to test the optional relationships. Use synthetic provider records to exercise duplicate names, identity across renames, overlapping private addresses, missing container fields and a page failure after a successful page. A write-capable adapter needs a source definition, organization resolution, stable external keys, field policies and a tested retirement policy. The synchronization sources import Clouds and their LANs. The subsequent REST step imports the NAT gateway inventory. Other resource families remain local inventory.

[base]: https://github.com/Combodo/itop-data-collector-base/blob/1.5.1/exec.php
[azure]: https://github.com/Combodo/itop-data-collector-ms-azure/blob/master/main.php
[intune]: https://github.com/Combodo/itop-data-collector-ms-intune/blob/master/main.php
[synchro]: https://www.itophub.io/wiki/page?id=3_3_0:advancedtopics:data_synchronization
[virtual]: https://github.com/Combodo/iTop/blob/3.3.0/datamodels/2.x/itop-virtualization-mgmt/datamodel.itop-virtualization-mgmt.xml
[containers]: https://github.com/Combodo/iTop/blob/3.3.0/datamodels/2.x/itop-container-mgmt/datamodel.itop-container-mgmt.xml
[core]: https://github.com/Combodo/iTop/blob/3.3.0/datamodels/2.x/itop-config-mgmt/datamodel.itop-config-mgmt.xml
[storage]: https://github.com/Combodo/iTop/blob/3.3.0/datamodels/2.x/itop-storage-mgmt/datamodel.itop-storage-mgmt.xml
[teemip]: https://github.com/TeemIP/teemip-core-ip-mgmt/blob/3.3.1/extension.xml
[ip4]: https://github.com/TeemIP/teemip-core-ip-mgmt/blob/3.3.1/teemip-ip-mgmt/datamodel.teemip-ip-mgmt.xml
[ip6]: https://github.com/TeemIP/teemip-core-ip-mgmt/blob/3.3.1/teemip-ipv6-mgmt/datamodel.teemip-ipv6-mgmt.xml
[adapter]: https://github.com/TeemIP/teemip-core-ip-mgmt/blob/3.3.1/teemip-virtualization-mgmt-adaptor/datamodel.teemip-virtualization-mgmt-adaptor.xml
[network]: https://github.com/TeemIP/teemip-core-ip-mgmt/blob/3.3.1/teemip-network-mgmt/datamodel.teemip-network-mgmt.xml
