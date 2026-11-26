<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/IpFacts.php';

/** Reuse or create a TeemIP host in the address space of the observed NAT endpoint. */
final class NatIpResolver
{
    private readonly Closure $get;
    private readonly Closure $create;

    /** @param callable $get Base core/get transport. @param callable $create Base core/create transport. */
    public function __construct(callable $get, callable $create, private readonly bool $ipv6)
    {
        $this->get = Closure::fromCallable($get);
        $this->create = Closure::fromCallable($create);
    }

    /**
     * Resolve a public host within the organization or a private host within this VDC's linked networks.
     * The private gateway prefix describes the attachment and is independent of the TeemIP subnet mask.
     * @param int|null $prefixLength Reported private prefix length, or null for a public IP.
     * @return int Native TeemIP host ID.
     */
    public function resolve(int $orgId, int $cloudId, string $address, ?int $prefixLength): int
    {
        $host = IpFacts::parse($address);
        if ($orgId < 1 || $cloudId < 1 || $host === null || $host['prefix'] !== null
            || ($prefixLength !== null && ($prefixLength < 0 || $prefixLength > ($host['family'] === 4 ? 32 : 128)))) {
            throw new RuntimeException('Invalid scope, host or prefix for a NAT address lookup.');
        }
        $v6 = $host['family'] === 6;
        if ($v6 && !$this->ipv6) {
            throw new RuntimeException("IPv6 management is required for NAT address $address.");
        }
        $class = $v6 ? 'IPv6Address' : 'IPv4Address';
        $subnet = $v6 ? 'IPv6Subnet' : 'IPv4Subnet';
        $field = $v6 ? 'ip_text' : 'ip';
        $ip = $host['address'];
        $literal = $v6 ? implode(':', str_split(bin2hex(inet_pton($ip)), 4)) : $ip;
        $scope = "s.org_id = $orgId";
        $query = "SELECT $class AS a";
        $where = "a.org_id = $orgId AND a.$field = '$literal'";
        if ($prefixLength !== null) {
            $scope .= " AND (s.id IN (SELECT IPSubnet AS sn JOIN lnkCloudToIPObject AS ls"
                . " ON ls.ipobject_id = sn.id WHERE ls.cloud_id = $cloudId)"
                . " OR s.block_id IN (SELECT IPBlock AS b JOIN lnkCloudToIPObject AS lb"
                . " ON lb.ipobject_id = b.id WHERE lb.cloud_id = $cloudId))";
            $query .= " JOIN $subnet AS s ON a.subnet_id = s.id";
            $where .= " AND $scope";
        }
        $query .= " WHERE $where";
        $existing = $this->host($class, $query, $orgId, $ip);
        if ($existing !== null) {
            return $existing['id'];
        }

        $last = $v6 ? 'lastip' : 'broadcastip';
        $bounds = $v6 ? "s.ip_text <= '$literal' AND '$literal' <= s.lastip_text"
            : "INET_ATON(s.ip) <= INET_ATON('$literal') AND INET_ATON('$literal') <= INET_ATON(s.broadcastip)";
        $parent = $this->one($subnet, "SELECT $subnet AS s WHERE $scope AND $bounds", "org_id,ip,$last", $orgId);
        if ($parent === null) {
            throw new RuntimeException("No matching $subnet for NAT address $ip in organization $orgId (Cloud $cloudId).");
        }
        $firstIp = IpFacts::parse($parent['ip'] ?? null);
        $lastIp = IpFacts::parse($parent[$last] ?? null);
        if (($firstIp['family'] ?? null) !== $host['family'] || ($lastIp['family'] ?? null) !== $host['family']
            || $firstIp['prefix'] !== null || $lastIp['prefix'] !== null
            || strcmp(inet_pton($ip), inet_pton($firstIp['address'])) < 0
            || strcmp(inet_pton($ip), inet_pton($lastIp['address'])) > 0) {
            throw new RuntimeException("The returned $subnet does not contain NAT address $ip.");
        }

        // Confirm the native object's identity even if another writer won the race or the response was lost.
        try {
            ($this->create)($class, ['org_id' => $orgId, 'subnet_id' => $parent['id'], 'ip' => $ip,
                'status' => 'allocated'], 'Import a NAT gateway address from IONOS.');
        } catch (Throwable) {
            // An uncertain write may already have committed. Verify by the same scoped lookup before retrying.
        }
        $created = $this->host($class, $query, $orgId, $ip);
        if ($created === null || (int) $created['subnet_id'] !== $parent['id']) {
            throw new RuntimeException("Could not confirm $class $ip in $subnet " . $parent['id'] . '.');
        }
        return $created['id'];
    }

    /** @return array|null Validated host identity and its native subnet. */
    private function host(string $class, string $query, int $orgId, string $ip): ?array
    {
        $row = $this->one($class, $query, 'org_id,ip,subnet_id', $orgId);
        if ($row !== null) {
            $actual = IpFacts::parse($row['ip'] ?? null);
            if (($actual['address'] ?? null) !== $ip || $actual['prefix'] !== null || !array_key_exists('subnet_id', $row)) {
                throw new RuntimeException("Unexpected iTop host returned for $class $ip.");
            }
        }
        return $row;
    }

    /** @return array|null A single verified native record, or null after a confirmed empty result. */
    private function one(string $class, string $query, string $fields, int $orgId): ?array
    {
        try {
            $reply = ($this->get)($class, $query, $fields, 2);
        } catch (Throwable) {
            throw new RuntimeException("iTop core/get failed for $class.");
        }
        if (!is_array($reply) || ($reply['code'] ?? null) !== 0 || !array_key_exists('objects', $reply)
            || !is_string($reply['message'] ?? null) || !preg_match('/^Found: (0|[1-9][0-9]*)$/D', $reply['message'], $matches)) {
            throw new RuntimeException("Invalid iTop core/get response for $class.");
        }
        $count = (int) $matches[1];
        $objects = $reply['objects'] ?? [];
        if (!is_array($objects) || count($objects) !== min(2, $count)) {
            throw new RuntimeException("Incomplete iTop inventory for $class.");
        }
        if ($count > 1) {
            throw new RuntimeException("Multiple matching $class records in organization $orgId.");
        }
        if ($count === 0) {
            return null;
        }
        $object = reset($objects);
        $id = $object['key'] ?? null;
        $data = $object['fields'] ?? null;
        if ((!is_int($id) && !is_string($id)) || (int) $id < 1 || (string) (int) $id !== (string) $id
            || ($object['code'] ?? null) !== 0 || ($object['class'] ?? null) !== $class
            || array_key_first($objects) !== "$class::$id" || !is_array($data) || (string) ($data['org_id'] ?? '') !== (string) $orgId) {
            throw new RuntimeException("Unexpected iTop identity for $class.");
        }
        return ['id' => (int) $id] + $data;
    }
}
