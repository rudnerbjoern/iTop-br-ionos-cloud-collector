<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use RestClient;
use RuntimeException;

/** Use the Base credentials and HTTPS settings for native iTop link deletion. */
final class LinkRestClient extends RestClient
{
    /** Update an observed NAT row through the same scoped OQL guard used for deletion. */
    public function updateNatRecord(string $class, array $key, array $fields, string $comment): array
    {
        return parent::Update($class, self::natQuery($class, $key), $fields, $comment);
    }

    /**
     * Format observed native IDs, source attribution and local resource identities as a guarded iTop query.
     * iTop REST structured keys pass every field through the datamodel attribute converter, including id.
     */
    private static function natQuery(string $class, array $key): string
    {
        $allowed = ['id', 'org_id', 'cloud_id', 'provider_resource_id', 'nat_gateway_id', 'cloud_lan_id',
            'attachment_id', 'address', 'ionos_source_key', 'ipaddress_id'];
        if (!in_array($class, ['CloudNatGateway', 'CloudNatGatewayLan', 'CloudNatGatewayLanAddress',
            'CloudNatGatewayPublicAddress'], true) || !isset($key['id'], $key['ionos_source_key'])
            || !preg_match('/^IONOS NAT - org [0-9]+ - provider [0-9]+(?: - contract [0-9]+)?$/D', $key['ionos_source_key'])) {
            throw new RuntimeException('Invalid IONOS NAT inventory key.');
        }
        $conditions = [];
        foreach ($key as $field => $value) {
            if (!in_array($field, $allowed, true) || !preg_match('/^[a-zA-Z0-9:. -]+$/D', (string) $value)) {
                throw new RuntimeException('Invalid NAT inventory identity.');
            }
            $conditions[] = "$field = '$value'";
        }
        return "SELECT $class WHERE " . implode(' AND ', $conditions);
    }

    /**
     * Simulate the scoped deletion before executing it, verifying that iTop would touch only this record.
     * @param array<string,int|string> $key Observed identity, parent, source and optional IPAM target.
     */
    public function deleteNatRecord(string $class, array $key): array
    {
        $operation = ['operation' => 'core/delete', 'class' => $class, 'key' => self::natQuery($class, $key),
            'comment' => 'Release a withdrawn IONOS NAT inventory record.'];
        $preview = parent::ExecOperation($operation + ['simulate' => true], $this->GetVersion());
        $reference = $class . '::' . $key['id'];
        if (($preview['code'] ?? null) !== 0 || array_keys($preview['objects'] ?? []) !== [$reference]
            || ($preview['objects'][$reference]['code'] ?? null) !== 0) {
            throw new RuntimeException('iTop could not safely delete the single observed NAT record.');
        }
        return parent::ExecOperation($operation + ['simulate' => false], $this->GetVersion());
    }

    /**
     * Delete exactly the observed link while it still has the expected Cloud, target and source.
     * @param array{id:int,cloud_id:int,ipobject_id:int,source_key:string} $key
     */
    public function deleteLink(array $key): array
    {
        $source = $key['source_key'];
        if (!preg_match('/^IONOS VDC IPv6 - org [0-9]+ - provider [0-9]+(?: - contract [0-9]+)?$/D', $source)) {
            throw new RuntimeException('Invalid IONOS allocation source.');
        }
        $query = sprintf(
            "SELECT lnkCloudToIPObject WHERE id = %d AND cloud_id = %d AND ipobject_id = %d AND source_key = '%s'",
            $key['id'], $key['cloud_id'], $key['ipobject_id'], $source
        );
        return parent::ExecOperation([
            'operation' => 'core/delete',
            'class' => 'lnkCloudToIPObject',
            'key' => $query,
            'comment' => 'Release the IONOS VDC IPv6 allocation.',
            'simulate' => false,
        ], $this->GetVersion());
    }
}
