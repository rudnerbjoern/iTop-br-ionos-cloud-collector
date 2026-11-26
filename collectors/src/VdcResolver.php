<?php

/** Resolve existing iTop references for the virtual datacenter synchronization. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
use RestClient;
use RuntimeException;
use Throwable;

/** Read native iTop organizations, provider locations and allocated TeemIP IPv6 blocks. */
final class VdcResolver
{
    /** @var Closure(string,int|string|array,string,int):mixed Base core/get transport. */
    private readonly Closure $get;

    /** @var array<int,int> Organizations confirmed during this collection. */
    private array $organizations = [];

    /**
     * @param callable|null $get RestClient::Get-compatible transport for a target iTop installation.
     */
    public function __construct(?callable $get = null)
    {
        $this->get = Closure::fromCallable($get ?? [new RestClient(), 'Get']);
    }

    /**
     * Verify the configured provider or tenant organization by its native iTop ID.
     *
     * @throws RuntimeException If the ID is invalid or the organization is unavailable.
     */
    public function organization(int $id): int
    {
        if ($id < 1) {
            throw new RuntimeException('The configured iTop organization ID must be positive.');
        }
        if (!isset($this->organizations[$id])) {
            $object = $this->one('Organization', $id, 'id');
            if ($object['key'] !== $id) {
                throw new RuntimeException('The returned organization differs from the requested ID.');
            }
            $this->organizations[$id] = $id;
        }
        return $id;
    }

    /**
     * Find a provider-owned Location whose name exactly matches the IONOS public location code.
     *
     * @throws RuntimeException If the provider or the location cannot be identified uniquely.
     */
    public function location(int $providerId, string $locationCode): int
    {
        if ($locationCode === '' || trim($locationCode) !== $locationCode) {
            throw new RuntimeException('A location lookup requires the exact IONOS location code.');
        }
        $this->organization($providerId);
        $object = $this->one('Location', ['org_id' => $providerId, 'name' => $locationCode], 'name,org_id');
        if (($object['fields']['name'] ?? null) !== $locationCode
            || (string) ($object['fields']['org_id'] ?? '') !== (string) $providerId) {
            throw new RuntimeException('The returned location differs from the requested provider or code.');
        }
        return $object['key'];
    }

    /**
     * Resolve a previously synchronized VDC within the configured provider and customer.
     *
     * @throws RuntimeException If the parent cannot be resolved uniquely with the expected ownership.
     */
    public function cloud(int $provider, int $customer, string $uuid): int
    {
        $key = ['provider_id' => $provider, 'provider_resource_id' => $uuid];
        $object = $this->one('Cloud', $key, 'provider_id,provider_resource_id,org_id');
        $fields = $object['fields'];
        if ((string) ($fields['provider_id'] ?? '') !== (string) $provider
            || ($fields['provider_resource_id'] ?? null) !== $uuid
            || (string) ($fields['org_id'] ?? '') !== (string) $customer) {
            throw new RuntimeException('The resolved LAN parent differs from the expected VDC or its owner.');
        }
        return $object['key'];
    }

    /**
     * Find an existing block in the tenant's address space with the exact VDC /56 boundaries.
     *
     * @throws RuntimeException If the CIDR is malformed, misaligned or the tenant or block cannot be resolved.
     */
    public function ipv6Block(int $tenantId, string $cidr): int
    {
        [$address, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($prefix !== '56' || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            throw new RuntimeException('The VDC allocation must be an IPv6 /56 prefix.');
        }
        $packed = inet_pton($address);
        if ($packed === false || substr($packed, 7) !== str_repeat("\0", 9)) {
            throw new RuntimeException('The IPv6 /56 prefix contains host bits.');
        }
        $this->organization($tenantId);

        // TeemIP exposes its canonical, zero-padded _text columns as iTop 3.3 OQL fields.
        $first = implode(':', str_split(bin2hex($packed), 4));
        $last = implode(':', str_split(bin2hex(substr($packed, 0, 7) . str_repeat("\xff", 9)), 4));
        $query = "SELECT IPv6Block WHERE org_id = $tenantId AND firstip_text = '$first' AND lastip_text = '$last'";
        $object = $this->one('IPv6Block', $query, 'org_id');
        if ((string) ($object['fields']['org_id'] ?? '') !== (string) $tenantId) {
            throw new RuntimeException('The returned IPv6 block belongs to a different tenant.');
        }
        return $object['key'];
    }

    /**
     * Read at most two matches, requiring a successful response containing one valid iTop object.
     *
     * @param int|string|array<string,int|string> $key Numeric ID, OQL or structured iTop search key.
     * @return array{key:int,fields:array} Native iTop key and requested output fields.
     * @throws RuntimeException If the request fails or returns an invalid or ambiguous result.
     */
    private function one(string $class, int|string|array $key, string $fields): array
    {
        try {
            $response = ($this->get)($class, $key, $fields, 2);
        } catch (Throwable) {
            // The Base client's original exception can contain the full HTTP response.
            throw new RuntimeException("iTop core/get failed for $class.");
        }
        if (!is_array($response) || ($response['code'] ?? null) !== 0) {
            throw new RuntimeException("iTop rejected core/get for $class.");
        }
        $objects = $response['objects'] ?? [];
        if (!is_array($objects) || count($objects) !== 1) {
            throw new RuntimeException("Expected exactly one matching iTop $class.");
        }
        $row = reset($objects);
        $id = is_array($row) ? ($row['key'] ?? null) : null;
        if ((!is_int($id) && !is_string($id)) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || (string) (int) $id !== (string) $id || ($row['code'] ?? null) !== 0
            || ($row['class'] ?? null) !== $class || !is_array($row['fields'] ?? null)
            || array_key_first($objects) !== "$class::$id") {
            throw new RuntimeException("Invalid object in iTop core/get response for $class.");
        }
        return ['key' => (int) $id, 'fields' => $row['fields']];
    }
}
