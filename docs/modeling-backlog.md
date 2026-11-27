# IONOS inventory modeling backlog

The shared arc42 architecture (ARC-1) and native virtual machines (VM-1) establish the base for **NET-1, network interfaces**, **VOL-1, block volumes**, and **DB-1, managed database services**, in that order. Start DB-1 with MariaDB, followed by PostgreSQL.

## What is already available

The collector imports VDCs as `Cloud`, their LANs, NAT gateways, network load balancers and VPN gateways. NAT includes public addresses and LAN attachments. Network load balancers include listener and backend LANs and their own addresses. IPsec and WireGuard gateways include public endpoints, LAN attachments and applicable interface addresses. The optional TeemIP bridge links supported addresses to IP objects. [Current import](../README.md#itop-target-and-synchronization)

The remaining items below are proposed work. The IDs are stable references for issue descriptions. The groups express a suggested order. Each item names a visible result and an acceptance example. A resource listed in the [read plan](../collectors/resources.json) can already appear in the local report while its iTop import is still open. Check the [resource catalog](resource-catalog.md) for source coverage and the [design](design.md) for mandatory attributes before choosing a new class.

## Next task

### ARC-1 · Complete the shared cloud inventory architecture

**Deliverable.** Document the architecture of the provider-neutral cloud model, its optional TeemIP bridge, the IONOS extension and the collector in the existing twelve English AsciiDoc chapters. Use the shared document in the cloud model repository as the source for all three projects. Verify descriptions against the model definitions, collector entry points, configuration and integration tests. Identify proposed designs such as the shared database service class as planned work. [Chapter index and build instructions](https://github.com/rudnerbjoern/iTop-br-cloud-datamodel/blob/main/docs/architecture/README.md)

**Use the template help as the writing model.** For each chapter and applicable subsection, read its embedded `arc42help` guidance on content, motivation and form. Use its example tables, diagrams and scenarios to present the actual system. Keep the upstream help blocks intact and write project content outside them. For example, fill the stakeholder table with roles and their concerns, trace an incomplete import in the runtime view, and turn preservation of manual links into an observable quality scenario.

- **Chapters 1–4:** Describe stakeholder goals, constraints, business and technical context, and the reasons for the solution strategy. Show who supplies inventory, who maintains the CMDB, and which component reads or writes at each boundary.
- **Chapters 5–8:** Show building blocks and their interfaces, a successful repeat import and a partial import, deployment with and without TeemIP, and the shared rules for identity, ownership and completeness. Trace one gateway and its address from an IONOS response to the iTop relationship visible to an operator.
- **Chapters 9–12:** Record consequential decisions and their rationale, quality scenarios with verifiable outcomes, current risks and mitigations, and a glossary using the same terms as the model and collector.

**Acceptance.** Review all twelve chapters against their own help blocks. Each applicable section must answer its architectural question with project-specific content. Where a subsection does not apply, explain why. Trace the gateway example across the context, building block, runtime and deployment views and check it against the implementation. Run `npm run docs:architecture` in the cloud model repository and open both HTML editions. Verify chapter navigation, diagrams and cross-references, and confirm that project content is visible in both editions and template guidance appears only in the help edition.

## First modeling delivery

### VM-1 · Virtual machines in their VDC

The [default collector](../README.md#virtual-machines-and-reservations) imports
native VMs under their Clouds and adopts uniquely matching unowned reservations
in place. The [installed fixture](../tests/vm-import.php) checks VDC-local identity
across renames, native ownership, preserved relationships, fields, and partial
reads in the Bare and Bridge variants.

### NET-1 · VM interfaces, LAN membership and addresses

- **Depends on** VM-1 and the existing LAN import. Map each server NIC to native `LogicalInterface`. Resolve its LAN number within its VDC. Preserve each reported address, family and supplied prefix. With TeemIP, resolve the host in the customer's matching address space.
- **Accept when** a VM with two NICs on different LANs shows the correct LAN and reported IPv4 and IPv6 addresses on each NIC in the base model. Add a second VDC using the same LAN number and private address. Both VDCs must retain distinct NICs and LANs. With TeemIP, verify each host link resolves in the correct subnet or routing context. Remove the distinguishing context from the fixture and repeat the import. The reported address must remain visible on its NIC and the ambiguous host link must remain unresolved. [IP address management](design.md#ip-address-management)

### VOL-1 · Block volumes and VM attachments

- **Depends on** the VDC import for volumes and VM-1 for attachments. Use the VDC volume list for identity and the server volume list for attachment. Record name, provider ID, capacity and tier when present. During design, check if the source can supply the storage system and LUN required by native `LogicalVolume`. If either is unknown, model a cloud volume under its VDC.
- **Accept when** an attached volume and a detached volume are both visible under the VDC. Move the first volume to another VM. After import it retains its ID, its former attachment is gone and the detached volume is still present.

### DB-1 · Managed database services

**Model.** Use a shared, provider-neutral base class for managed database services wherever feasible. Check existing iTop classes first. Native `DBServer` requires a host through `SoftwareInstance.system_id`. For services whose backing host is hidden, a shared class derived from `FunctionalCI` in the generic cloud model is a candidate. Define customer organization, engine, provider resource ID and reported location once. Use the engine field to distinguish services. Introduce a subclass only for engine-specific attributes or relationships that need one. If an engine cannot use the common base, document the concrete constraint in its issue. [Existing iTop mapping analysis](design.md#other-managed-services)

**Scope.** Use one issue per engine. Import clusters or replica sets and their reported backups or snapshots. Link individual databases where the API lists them and identifies the parent. For backups, require a cluster ID from the source that resolves to the parent in the customer's inventory. If the ID is missing or ambiguous, keep the backup's parent unresolved. The MariaDB and PostgreSQL backup records in the November 26, 2026 inventory contained only location and type as projected attributes. [Database inventory coverage](resource-catalog.md#databases-and-event-streaming)

**Delivery order and acceptance by engine**

1. **MariaDB.** Establish the shared service model. Import two synthetic clusters with the same display name via the Berlin and Frankfurt endpoints. After two imports, the service list shows two MariaDB CIs with stable, distinct IDs. Include one cluster observed through both API generations with confirmed identity and verify it appears once. A backup with a verified cluster ID links to that cluster. A backup with only location and type stays unlinked even when the location matches.
2. **PostgreSQL.** Add a synthetic PostgreSQL cluster via Berlin alongside the two MariaDB fixtures. After two imports, one query on the shared base class returns all three CIs with the correct engines and stable IDs. Verify the same backup-parent rule for PostgreSQL.
3. **MongoDB.** Seed previously imported clusters, then run a successful v2 collection alongside a v1 cluster request returning HTTP 404. Repeat with HTTP 410. In both cases, reconcile the v2 data, report the v1 scope as unknown and the run as incomplete, and preserve the CIs previously observed in that scope.
4. **In-Memory DB.** Import a snapshot with a restore reported by the v1 API. Keep the restore as operational history associated with that snapshot. After a repeat import, the snapshot retains its ID and the restore creates no separate CI.

## Extend the network inventory

Each item can start from a completed gateway, LAN or load balancer unless a dependency is listed.

- **NLB-1 · Forwarding rules and targets.** Show listener IP, port, protocol, distribution algorithm and target IPs, ports and weights under each network load balancer. Accept a rule on port 443 with two targets on 8443. Remove one target and verify the rule, the other target and the load balancer's own backend addresses keep their identities.
- **VPN-1 · IPsec tunnels and WireGuard peers.** Attach each tunnel or peer to its protocol-specific gateway and show its reported remote networks. Accept two gateways with overlapping remote prefixes. Removing a peer from one gateway affects only that peer. Use inventory fields that exclude keys and configuration exports.
- **NAT-1 · NAT rules.** Show each rule under its gateway with its reported protocol, translation address and source or destination filters. Accept a rule referring to an external CIDR. Its filter remains readable and the customer's TeemIP allocations stay unchanged.
- **NET-2 · Reserved IP pools.** Model a provider reservation and its member addresses. Reuse TeemIP objects when organization and address space resolve uniquely. Accept a reservation containing multiple addresses, one already referenced by a gateway. A repeat import preserves that host ID and its gateway link.
- **NET-3 · Private Cross Connect.** Show the provider connection and its evidenced LAN endpoints. Accept two VDCs linked to the same connection. Removing one endpoint preserves the connection and its remaining endpoint.
- **SEC-1 · Security groups and interface firewalls.** Show groups and their rules under the VDC and show NIC-specific rules on the interface from NET-1. Import membership only where reported. Accept two interfaces with different rules and one external source prefix. Verify each rule appears under the correct owner.
- **ALB-1 · Application load balancers.** Model the load balancer, listener and target LANs, forwarding rules, reusable target groups and their members. Accept two rules sharing one group. Remove one rule and verify the surviving rule still leads to the same group and targets.
- **LB-1 · Classic load balancers.** Depends on NET-1. Link each classic load balancer to its reported balanced NICs. Accept a member moving between two balancers while its NIC identity stays stable.
- **LOG-1 · Flow log configurations.** Attach each configuration to its reported NIC, NAT gateway or load balancer as its parent model becomes available. Accept two parents with identically named configurations. Verify the destination and enabled state against the source for each.

## Compute, storage and managed services

- **VM-2 · Auto Scaling groups and GPUs.** Depends on VM-1. Link scaling group members to existing VMs and record reported GPU attachments on their servers. Accept a VM observed in both the compute and scaling APIs as one CI. A membership change preserves the VM.
- **VOL-2 · Snapshots, private images and mounted media.** Builds on VOL-1 and VM-1. Track customer snapshots and images, source volume references when supplied, and server media attachments. Accept a detached snapshot and one private image mounted by two servers. Unmounting the image on one server preserves the image.
- **BKP-1 · Backup units.** Represent each observed unit for its customer. Accept two units under the same contract and verify both on repeat import. Record workload protection only when a source supplies the workload-to-backup relationship.
- **NFS-1 · Managed file storage.** Model clusters and shares. Evaluate native `NAS` and `NASFileSystem` against the mandatory NAS parent. Accept equal share names on two clusters. Renaming one share preserves its identity and parent.
- **DNS-1 · DNS zones and records.** Model primary and secondary zones, their records and separately sourced reverse records. Accept two zones with the same relative record name and a record pointing outside the customer network. Preserve the correct zone and record value.
- **CERT-1 · Certificates.** Model certificates, automatic certificate resources and provider references. Accept two certificates for the same domain with distinct provider IDs. Show their individual validity periods when reported.
- **EDGE-1 · API gateways and CDN.** Model API gateways with their routes, and CDN distributions with evidenced origins. Link certificates from CERT-1 when their IDs resolve. Accept two gateways with the same route path and a distribution with an external origin.
- **K8S-1 · Managed Kubernetes.** Model clusters, node pools and observed nodes. Evaluate native `ContainerCluster` and `ContainerHost`. Use VM-1 when a backing VM can be identified. Accept a pool with desired capacity three and two observed nodes. Display both values and exactly two observed nodes.
- **REG-1 · Container registries.** Model registries, repositories and artifacts with their digests. Map eligible images to native `ContainerImage` when its required fields are present. Accept a tag moving to a new digest and preserve the registry context for both artifacts.
- **KAFKA-1 · Event streams.** Model Kafka clusters and their topics. Accept identically named topics on two clusters. Removing a topic affects only the matching cluster.
- **OBS-1 · Observability pipelines.** Model configured logging, monitoring and tracing pipelines with product and region. Accept two pipelines with the same name across services. A partial regional response preserves the last complete inventory for that scope.

## Work that needs another inventory source

- **S3-1 · Customer buckets.** The current management adapter lists regions. Add a read-only S3 bucket inventory first, then model bucket identity and ownership. Accept two customer buckets and a run without bucket-list permission. The latter leaves the known bucket inventory intact.
- **APP-1 · Running Kubernetes workloads.** After K8S-1, add a scoped Kubernetes workload reader. Link an observed deployment to its namespace, cluster and, where resolved, image digest from REG-1. Accept the same deployment name in two namespaces and verify two identities.

Provider locations, hardware templates, public images, supported engine versions and AI Model Hub entries serve as reference data. Backup plans, protected workloads and recovery activity need separate backup-service evidence before they can be added to the backlog as implementation issues.

## Taking an item into implementation

1. Open a collector issue with the backlog ID, selected read paths, parent scope, proposed iTop classes and acceptance example. Record which fields the inventory report already projects and which must be added.
2. Put reusable classes and relationships in the [generic cloud model](https://github.com/rudnerbjoern/iTop-br-cloud-datamodel), provider fields in the [IONOS extension](https://github.com/rudnerbjoern/iTop-br-ionos-cloud-datamodel), and API reads and reconciliation in this collector. An item that can use existing classes may need only a collector change.
3. Run the acceptance example twice and compare object and link IDs. Exercise an incomplete source list and a manual record in the same scope. For address work, verify the base model and the TeemIP bridge. Record the tested commits and the visible result in the issue, then link the completed issue from this backlog.
