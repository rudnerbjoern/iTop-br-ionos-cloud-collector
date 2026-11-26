# IONOS Cloud resource catalog

Use this catalog to identify IONOS Cloud products, their inventory interfaces and candidate objects for the CMDB. A product being available in the catalog does not establish that the customer owns an instance. Account-specific evidence belongs in the local inventory report.

The scope is the IONOS CLOUD enterprise platform documented in the product guides and Developer Reference. The November 2026 source review includes both documented database API generations and their regional migration status. It covers platform components, customer resources and provider catalogs at the resource-type level. Retail hosting plans and domain registration belong to other IONOS product catalogs. [IONOS Cloud product guides][products] · [Service catalog][services] · [Developer Reference][reference]

## How to read coverage

- **Collected** means the release plan contains a read-only list operation. Its result is specific to the principal, product, endpoint, parent and time of collection. A new API version, region or product requires an updated plan.
- **Reference** means a provider lists a location, model, version, template or supported capability. Such an entry can enrich a customer resource. The entry alone does not justify creating a customer-owned CI.
- **Documented only** means the feature appears in the provider documentation but the collector does not produce a dedicated inventory for it. The reason is given below. A missing collection is unknown.
- **Proposed mapping** describes a possible iTop destination. The inventory command writes a local report. The synchronization command currently imports virtual datacenters as `Cloud`, their LANs as `CloudLan` and their NAT gateways with their public addresses and private LAN attachments.

The [executable catalog](../collectors/resources.json) enumerates concrete HTTPS bases, nested GET paths and pagination options. Some services use a global API and return the location on each object. Others have one base per region. The plan lists each supported service region individually. For example, the VDC resource path starts at `https://api.ionos.com/cloudapi/v6/datacenters`, whereas PostgreSQL v2 uses regional bases such as `https://postgresql.de-txl.ionos.com/v2`. Select regions from the applicable service definition. The global list of IONOS locations is insufficient for deriving service endpoints. [Cloud API v6][compute] · [PostgreSQL v2][pg2] · [IONOS SDK revision][sdk]

### Endpoint regions

The following groups describe the regional coverage of the checked-in read plan. A hostname identifies the service control plane. A resource's actual placement comes from its own attributes or a related location record. Control planes serving several regions can therefore return resources from outside the hostname's region.

- **Central endpoints** serve the Cloud API v6, the container registry, VM Auto Scaling, Object Storage management and the v1 MongoDB and PostgreSQL APIs. The response or provider location catalog supplies location context.
- **Single listed regional endpoints** are `de-fra` for CDN, DNS and Certificate Manager, and `de-txl` for AI Model Hub and the SDK's API Gateway endpoint.
- **Nine listed endpoint regions** are `de-fra`, `de-txl`, `es-vit`, `fr-par`, `gb-bhx`, `gb-lhr`, `us-ewr`, `us-las` and `us-mci` for In-Memory DB v1 and v2, MariaDB v2, PostgreSQL v2, Event Streams, Logging, Monitoring, Tracing and VPN.
- **Eight listed endpoint regions** for NFS and MariaDB v1 are `de-fra`, `de-txl`, `es-vit`, `fr-par`, `gb-lhr`, `us-ewr`, `us-las` and `us-mci`.
- **Seven listed endpoint regions** for MongoDB v2 are `de-fra`, `es-vit`, `fr-par`, `gb-bhx`, `gb-lhr`, `us-ewr` and `us-mci`. [Product reference][reference] · [Endpoint declarations](../collectors/resources.json)

## Compute and virtual data centers

- **Locations and VDCs.** Collected globally through `/locations` and `/datacenters`. Locations are provider reference data. One customer VDC maps naturally to one native `Cloud`. Its owning organization, the provider organization and the provider's location have separate meanings. Two VDCs at the same location remain distinct. [Cloud API v6][compute] · [iTop virtualization][virtual]
- **Servers.** Dedicated Core servers, vCPU servers, Cubes, GPU VMs and confidential VMs are compute offerings. Inventory enumerates VDC servers, their resource IDs, available hardware properties and the GPUs the API associates with them. Preserve the server type and placement attributes present in the response. Represent a virtual server as `VirtualMachine` attached to its VDC. Provider-managed underlying hardware has no independently evidenced physical CMDB identity here. [Compute products][computeproducts] · [Cloud API v6][compute]
- **Attachments.** A server can reference attached volumes, CD-ROM images and multiple network interfaces. Each of these lists has the server and VDC in its path. The VDC volume and the server attachment refer to the same volume identity. Retain the attachment as a relationship. [Cloud API v6][compute]
- **Templates and images.** Hardware templates describe purchasable configurations. The image catalog can include public provider images and private customer images. Public visibility, ownership and a specific server's media attachment answer different questions. A boot image is separate from an OCI container image. [Compute products][computeproducts] · [Images and snapshots][images]
- **VM Auto Scaling.** Collected groups and their server membership describe the scaling resource and observed members. An eligible VM can appear in both the compute and scaling APIs. Join it by a verified identifier. Scaling actions are operational history. [VM Auto Scaling][scaling]

## Networking, DNS and security

- **VDC networks.** Collected LANs, NICs, NIC firewall rules, security groups and their rules preserve VDC or interface scope. A node observed on a LAN does not establish its routing table. A `CloudLan` belongs to one `Cloud` and retains its VDC-local provider ID. A `VLAN` requires a real VLAN tag. [VDC networking][net] · [Network security groups][nsg] · [iTop configuration model][core]
- **IP address management.** The collector lists reserved IP blocks and extracts IPv4, IPv6, host and prefix observations from supported network resources. A reserved pool can contain multiple addresses. A rule's source network can be an arbitrary external network. Advertised pool membership, host address assignment, subnet boundaries and a filter expression have separate meanings in TeemIP. [VDC networking][net] · [TeemIP core][teemip]
- **Private Cross Connect.** Collected cross-connect objects (`/pccs`) describe connectivity across VDCs. Keep the provider object and any evidenced LAN endpoint relationships. A native TeemIP `WANLink` requires a validated circuit mapping. [Cross Connect][cross]
- **NAT gateways.** The collector imports each gateway under its VDC, its public address assignments, private LAN attachments and the host addresses with prefixes on those attachments. Source NAT rules and flow log configurations are separate inventory observations. [NAT class mapping](design.md#nat-gateway-reconciliation) A VM's dependence on this gateway requires evidence that it uses a route to it. [NAT Gateway][nat]
- **Load balancing.** The Cloud API exposes classic load balancers with balanced NICs, network load balancers with listener and target LANs plus forwarding rules, and application load balancers with forwarding rules and target groups. Preserve the distinction between a rule's target IP and a confirmed backend CI. Target-group inventory and the associated flow log configurations remain separate records. [Network Load Balancer][nlb] · [Application Load Balancer][alb] · [Cloud API v6][compute]
- **VPN Gateway.** The regional service lists IPsec gateways and tunnels, and WireGuard gateways and peers. Use the gateway as parent identity. Cryptographic material and peer configuration exports are outside the projected attributes. [VPN Gateway][vpn]
- **Cloud DNS.** Primary and secondary zones contain DNS records. Reverse records have a separate collection. An address record can point to an external system or a shared endpoint. The zone and record alone do not prove ownership of that system. [Cloud DNS][dns]
- **CDN, API gateways and certificates.** Collected distributions, gateways with routes, certificates, automatic certificates and certificate providers are distinct resources. The SDK lists the API Gateway service. A DNS lookup failure on its published endpoint leaves that run's coverage unknown. Product retirement requires a separate provider announcement. Listener addresses, origin selection and certificate bindings should be imported only when the corresponding reference is available. [CDN][cdn] · [API Gateway SDK][apigatewaysdk] · [Certificate Manager][cert]
- **Security services and networking configuration.** DDoS Protect is a documented protection service. A protection offering does not imply a discrete customer appliance. SSH Key Manager, token management and IAM federation are documented only. The collector omits key material, credentials, user memberships and permission exports. Collected flow log objects describe configurations. Their log payloads stay on the data plane. [DDoS Protect][ddos] · [SSH Key Manager][ssh] · [IAM federation][iam] · [Flow Logs][flow]

## Storage and backup

- **Block storage.** Enumerate volumes at the VDC level to include detached volumes. Read attachment lists under servers for usage. Capture snapshots as separate resources. Preserve a source-volume reference only if the API supplies one. Storage tiers such as HDD and SSD are resource attributes. [Block Storage][block] · [Images and snapshots][images]
- **Backup service.** Backup units are collected through the Cloud API. Backup plans, agent installations, protected workloads, individual recovery points and restore activity in the backup product are documented only. A backup unit alone cannot prove coverage of a server. Database-specific backup and snapshot objects have their own APIs. [Backup Service][backup] · [Cloud API v6][compute]
- **Network File Storage.** Collected regional NFS clusters and shares expose a cluster-to-share relationship. Evaluate iTop `NAS` and `NASFileSystem` when a real NAS CI can supply the required parent of the file system. Capture the managed share independently while its backing device is unresolved. [Network File Storage][nfs] · [iTop storage model][storage]
- **Object Storage.** The bearer-token management adapter lists available storage regions. Contract-owned and user-owned S3 buckets, and features such as versioning, policies, public access, lifecycle rules and replication, belong to distinct S3 API surfaces. Bucket enumeration uses the S3 `ListBuckets` operation with S3 access credentials and Signature Version 4 signing. The bearer-token plan collects storage regions only. An empty region response is never a bucket count. Application objects, object data, S3 access keys and their secrets are outside the collection. [Object Storage][object] · [S3 API compatibility][s3compat] · [ListBuckets operation][listbuckets] · [Management API][s3mgmt]

## Kubernetes and container registry

- **Managed Kubernetes.** The Cloud API lists clusters, each cluster's node pools and each pool's nodes. A pool describes placement and desired capacity. A node describes an observed host. Preserve both identities, including any verified VDC placement. Do not invent a control-plane VM or node by extrapolating the pool's desired size. [Managed Kubernetes][k8s] · [Cloud API v6][compute]
- **Private Container Registry.** A registry contains repositories and artifacts. A repository name, mutable image tag, immutable content digest and registry identity have different roles. Retain the registry context for artifacts. Promote an OCI image to `ContainerImage` only when its image type and all mandatory model fields are known. Arbitrary OCI artifacts, image layers and vulnerability findings do not each correspond to an image CI. [Private Container Registry][registry] · [iTop container model][containers]
- **Running applications.** Namespaces, deployments, pods and containers are Kubernetes control-plane objects. The Cloud API resource plan inventories the managed infrastructure. It does not fetch a cluster credential file, call the Kubernetes workload API or infer a running deployment from a pushed image. Native `ContainerApplication` and its image links require independent deployment evidence. [Managed Kubernetes][k8s] · [iTop container model][containers]
- **Other container platforms.** Red Hat OpenShift and SUSE Rancher are documented deployment options for workloads on IONOS. Underlying VMs may be visible in compute inventory. Cluster-specific facts must come from the corresponding platform. [OpenShift deployment guide][openshift] · [Rancher deployment guide][rancher]

## Databases and event streaming

Database endpoints are versioned separately for each engine. v1 and v2 results can overlap. Keep source version, service endpoint, region and provider identity until the operator can establish which records describe the same resource. Several APIs return backup locations, software versions or instance templates alongside customer resources. [Database products][db]

- **PostgreSQL.** v2 collects regional clusters, backups, backup locations and supported versions. The v1 adapter reads clusters, their databases and cluster backups from the v1 API. Individual database contents, credentials and database roles are outside inventory. [PostgreSQL v1][pg1] · [PostgreSQL v2][pg2]
- **MariaDB.** v2 collects regional clusters, backups, backup locations and supported versions. The v1 adapter lists regional clusters and backups. [MariaDB v1][maria1] · [MariaDB v2][maria2]
- **MongoDB.** v2 collects regional clusters, snapshots, instance templates, snapshot locations and versions. The v1 adapter inventories clusters exposed through the legacy API. IONOS completed the Frankfurt migration on November 25, 2026. It directs management requests for that region to `https://mongodb.de-fra.ionos.com/v2`. A v1 HTTP 404 or 410 cannot establish an empty customer inventory. [MongoDB v1][mongo1] · [MongoDB v2][mongo2] · [Frankfurt migration][frankfurt]
- **In-Memory DB.** v2 collects clusters, snapshots, supported versions and snapshot locations. v1 inventories replica sets, snapshots and their restores. A restore is operational evidence attached to its snapshot. [In-Memory DB v1][memory1] · [In-Memory DB v2][memory2]
- **Event Streams for Apache Kafka.** The regional service exposes clusters and topics. A topic belongs to its cluster. Producing or consuming messages is unnecessary for inventory. [Event Streams][kafka]

## Observability, AI and platform services

- **Logging, Monitoring and Tracing.** The collector lists pipeline resources through the respective regional management APIs. A pipeline is a configured resource that can consume metrics, logs or traces. The aggregate telemetry itself is outside inventory. Central service settings, Activity Log entries and the legacy Monitoring as a Service product are documented only. [Observability products][observe] · [Tracing API][tracing]
- **AI Model Hub.** The models endpoint returns models available to the caller. This is reference data for inference capabilities. The collector makes no inference calls. A model appearing in this list does not prove the customer deployed it, used it or incurred charges. The IONOS MCP server is a documented tool for interacting with platform services. [AI Model Hub][ai] · [AI API][aiapi] · [MCP server][mcp]
- **Private Cloud and Nextcloud Workspace.** These product guides describe a private cloud service and a managed collaboration service. No dedicated inventory adapter is configured for their control planes. Their presence in the IONOS catalog does not establish a provisioned customer instance. [IONOS Private Cloud][private] · [Nextcloud Workspace][nextcloud]
- **Management and commercial services.** The Data Center Designer, account contracts, user and group access, resource sharing, reseller relationships, billing, usage, support and data exports have their own workflows. Requests, invoices, usage line items, support cases and exported customer data are outside infrastructure inventory. Preserve the source contract as an explicit reconciliation context if several contracts are later integrated. [Service catalog][services] · [Developer Reference][reference]

For required CMDB modules, relationship constraints and incomplete-result handling, see the [inventory design](design.md).

[products]: https://docs.ionos.com/cloud/product-guides.md
[services]: https://docs.ionos.com/cloud/support/general-information/service-catalog
[reference]: https://docs.ionos.com/cloud/api-reference
[sdk]: https://github.com/ionos-cloud/sdk-go-bundle/tree/bffba230f9ffa761001d44a52e87fe7c7d470132/products
[compute]: https://api.ionos.com/docs/cloud/v6/
[computeproducts]: https://docs.ionos.com/cloud/compute-services/compute-services.md
[virtual]: https://github.com/Combodo/iTop/blob/3.3.0/datamodels/2.x/itop-virtualization-mgmt/datamodel.itop-virtualization-mgmt.xml
[core]: https://github.com/Combodo/iTop/blob/3.3.0/datamodels/2.x/itop-config-mgmt/datamodel.itop-config-mgmt.xml
[containers]: https://github.com/Combodo/iTop/blob/3.3.0/datamodels/2.x/itop-container-mgmt/datamodel.itop-container-mgmt.xml
[storage]: https://github.com/Combodo/iTop/blob/3.3.0/datamodels/2.x/itop-storage-mgmt/datamodel.itop-storage-mgmt.xml
[teemip]: https://github.com/TeemIP/teemip-core-ip-mgmt/tree/3.3.1
[images]: https://docs.ionos.com/cloud/backup-and-storage/images-snapshots.md
[scaling]: https://docs.ionos.com/cloud/network-services/vm-auto-scaling.md
[net]: https://docs.ionos.com/cloud/network-services/vdc-networking.md
[nsg]: https://docs.ionos.com/cloud/security/network-security-groups.md
[cross]: https://docs.ionos.com/cloud/network-services/cross-connect.md
[nat]: https://docs.ionos.com/cloud/network-services/nat-gateway.md
[nlb]: https://docs.ionos.com/cloud/network-services/network-load-balancer.md
[alb]: https://docs.ionos.com/cloud/network-services/application-load-balancer.md
[vpn]: https://docs.ionos.com/cloud/network-services/vpn-gateway.md
[dns]: https://docs.ionos.com/cloud/network-services/cloud-dns.md
[cdn]: https://docs.ionos.com/cloud/network-services/cdn.md
[apigatewaysdk]: https://github.com/ionos-cloud/sdk-go-bundle/tree/bffba230f9ffa761001d44a52e87fe7c7d470132/products/apigateway
[cert]: https://docs.ionos.com/cloud/security/certificate-manager.md
[ddos]: https://docs.ionos.com/cloud/network-services/ddos-protect.md
[ssh]: https://docs.ionos.com/cloud/security/ssh-key-manager.md
[iam]: https://docs.ionos.com/cloud/set-up-ionos-cloud/management/identity-access-management/iam-federation.md
[flow]: https://docs.ionos.com/cloud/network-services/flow-logs.md
[block]: https://docs.ionos.com/cloud/backup-and-storage/block-storage.md
[backup]: https://docs.ionos.com/cloud/backup-and-storage/backup-service.md
[nfs]: https://docs.ionos.com/cloud/backup-and-storage/network-file-storage.md
[object]: https://docs.ionos.com/cloud/backup-and-storage/ionos-object-storage.md
[s3compat]: https://docs.ionos.com/cloud/backup-and-storage/ionos-object-storage/concepts/s3-api-compatibility.md
[listbuckets]: https://docs.aws.amazon.com/AmazonS3/latest/API/API_ListBuckets.html
[s3mgmt]: https://github.com/ionos-cloud/sdk-go-bundle/tree/bffba230f9ffa761001d44a52e87fe7c7d470132/products/objectstoragemanagement
[k8s]: https://docs.ionos.com/cloud/containers/managed-kubernetes.md
[registry]: https://docs.ionos.com/cloud/containers/private-container-registry.md
[openshift]: https://docs.ionos.com/cloud/containers/red-hat-openshift.md
[rancher]: https://docs.ionos.com/cloud/containers/suse-rancher.md
[db]: https://docs.ionos.com/cloud/databases/databases.md
[pg1]: https://docs.ionos.com/cloud/api-reference/databases/postgresql.md
[pg2]: https://docs.ionos.com/cloud/api-reference/databases/postgresql-v2.md
[maria1]: https://docs.ionos.com/cloud/api-reference/databases/mariadb.md
[maria2]: https://docs.ionos.com/cloud/api-reference/databases/v2-1.md
[mongo1]: https://docs.ionos.com/cloud/api-reference/databases/mongodb.md
[mongo2]: https://docs.ionos.com/cloud/api-reference/databases/v2-2.md
[frankfurt]: https://status.ionos.cloud/incidents/9f7yltcb5zbx
[memory1]: https://docs.ionos.com/cloud/api-reference/databases/in-memory-db.md
[memory2]: https://docs.ionos.com/cloud/api-reference/databases/v2.md
[kafka]: https://docs.ionos.com/cloud/data-analytics/kafka.md
[observe]: https://docs.ionos.com/cloud/observability/observability.md
[tracing]: https://docs.ionos.com/cloud/api-reference/observability/tracing-service.md
[ai]: https://docs.ionos.com/cloud/ai/ai-model-hub.md
[aiapi]: https://docs.ionos.com/cloud/api-reference/ai/ai-model-hub.md
[mcp]: https://docs.ionos.com/cloud/ai/mcp-server.md
[private]: https://docs.ionos.com/cloud/private-cloud/ionos-private-cloud.md
[nextcloud]: https://docs.ionos.com/cloud/sovereign-workplace/nextcloud-workspace.md
