<?php

/** Normalize address observations for TeemIP mapping. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

/** Extract observed IP addresses and network prefixes for subsequent TeemIP reconciliation. */
final class IpFacts
{
    /**
     * Preserve the source field and address family; link-local and private space are meaningful
     * only in the owning organization's address space. A host prefix is not a subnet allocation.
     *
     * @param array<string,mixed> $attributes Projected IONOS resource data.
     * @return list<array{field:string,address:string,family:int,prefix:?int}>
     */
    public static function fromAttributes(array $attributes): array
    {
        $facts = [];
        foreach (['ip','ips','privateIps','publicIp','publicIps','ipv6Ips','listenerIp','cidr',
            'lbPrivateIps','sourceIp','targetIp','gatewayIP','interfaceIPv4CIDR','interfaceIPv6CIDR',
            'sourceSubnet','targetSubnet','ipv6CidrBlock','ipv6CidrBlocks','ipv4CidrBlock'] as $field) {
            foreach ((array) ($attributes[$field] ?? []) as $value) {
                self::add($facts,$field,$value);
            }
        }
        foreach (['targets'=>['ip'],'lans'=>['gatewayIps'],'connections'=>['cidr','ipv4CIDR','ipv6CIDR']] as $section=>$fields) {
            foreach ($attributes[$section] ?? [] as $entry) {
                foreach ($fields as $field) {
                    foreach ((array) ($entry[$field] ?? []) as $value) {
                        self::add($facts,$section . '.' . $field,$value);
                    }
                }
            }
        }
        return $facts;
    }

    /**
     * Append a valid IPv4 or IPv6 literal and its optional prefix length.
     * @param list<array{field:string,address:string,family:int,prefix:?int}> $facts
     */
    private static function add(array &$facts, string $field, mixed $raw): void
    {
        $host = self::parse($raw);
        if ($host !== null) {
            $facts[] = ['field' => $field] + $host;
        }
    }

    /**
     * Parse a literal host and optional prefix without discarding host bits.
     * @return array{address:string,family:int,prefix:?int}|null
     */
    public static function parse(mixed $raw): ?array
    {
        if (!is_string($raw)) {
            return null;
        }
        [$host,$prefix] = array_pad(explode('/',$raw,2),2,null);
        if (!filter_var($host,FILTER_VALIDATE_IP)) {
            return null;
        }
        $family = str_contains($host,':') ? 6 : 4;
        if ($prefix !== null && (!ctype_digit($prefix) || (int)$prefix > ($family === 6 ? 128 : 32))) {
            return null;
        }
        return ['address'=>inet_ntop(inet_pton($host)),'family'=>$family,
            'prefix'=>$prefix === null ? null : (int)$prefix];
    }
}
