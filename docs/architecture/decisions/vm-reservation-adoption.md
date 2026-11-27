# Bind an IONOS server to a native VM by its verified iTop ID

## Context

An administrator can reserve a VM before IONOS reports the server. The CI may already have contacts and application relationships. A server can be renamed, and another Cloud can have the same display name and contain a VM of the same name. Provisioning must preserve these distinctions and keep existing links usable.

## Decision

Identify the server by `ionos_source_key`, `ionos_vdc_id` and `ionos_server_id`. The source includes the configured customer, provider and optional contract. Resolve the Cloud's iTop key and customer before looking for a VM. Retaining the provider's VDC ID lets the collector recognize a previously imported VM even if an operator moves it to another Cloud. That contradiction requires investigation before the server can update a CI.

Use the name only for a first claim. The candidate must be exactly a native `VirtualMachine` in the same Cloud and customer organization. Check that it has no provider identity and no destination replica belonging to any synchronization source. Evaluate all claims from the completed VDC list together. Multiple candidates for one server or multiple servers competing for one reservation leave every contested record untouched.

Give a single native VM synchronization source the selected CI's numeric iTop ID as its primary key. On adoption, this lets iTop bind the existing CI and update only the managed fields. On an unambiguous miss, create the VM with its scoped identity through REST and submit the returned iTop ID through the same source. A run interrupted between creation and binding can find the identified CI on retry. The native source treats a missing target as an error and ignores deletion. Incomplete and empty server lists cannot authorize a VM write.

## Alternatives considered

- Reconciling on a reservation's name and host on every run would lose the match after a rename and could transfer ownership of another source's CI.
- Reconciling only on the provider ID would miss a reservation. Writing that ID onto the reservation ahead of synchronization would change a manually managed record before the native source had bound it.
- Using separate sources for reservations and discovered VMs would make native ownership depend on how the server entered the CMDB. Both paths use the same model, field policy and owner.

## Consequences

The provider ID finds the server on later observations. The positive iTop key tells the native source which object it can update. The collector must have permission to inspect synchronization ownership as well as to read and create VMs. Its data-directory lock serializes runs sharing that directory. Coordinate other provisioning writers when they target the same reservations because independent REST reads and native synchronization do not share a transaction.

The original behavior and installed-target acceptance cases are tracked in [VM-1](https://github.com/rudnerbjoern/iTop-br-ionos-cloud-collector/issues/14). The native binding and attribute update rules are defined by the [iTop 3.3 synchronization model](https://github.com/Combodo/iTop/blob/3.3.0/synchro/synchrodatasource.class.inc.php).
