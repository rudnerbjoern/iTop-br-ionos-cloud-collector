<?php

/** Collect projected resources and explicit per-scope completeness evidence. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
require_once __DIR__ . '/IpFacts.php';

/** Traverse configured inventory lists, retaining status and parent context for every scope. */
final class Inventory
{
    /**
     * @param Closure(string,string,array):array $get Fetch one page, or throw InventoryError.
     * @throws InventoryError For invalid request bounds.
     */
    public function __construct(
        private readonly Closure $get,
        private readonly int $pageSize = 100,
        private readonly int $maxPages = 500,
    ) {
        if ($pageSize < 1 || $pageSize > 100 || $maxPages < 1 || $maxPages > 10000) {
            throw new InventoryError('invalid_page_settings');
        }
    }

    /**
     * @param array<string,array{bases:list<string>,resources:array<string,array>}> $products
     * @return array{format:int,finishedAt:string,complete:bool,scopes:list<array>}
     * The complete flag covers every requested parent and child at every selected origin.
     */
    public function collect(array $products): array
    {
        if (!$products) {
            throw new InventoryError('empty_resource_plan');
        }
        $scopes = [];
        foreach ($products as $product => $plan) {
            if (empty($plan['bases']) || empty($plan['resources'])) {
                throw new InventoryError('empty_resource_plan');
            }
            foreach ($plan['bases'] as $base) {
                $sets = [];
                foreach ($plan['resources'] as $template => $options) {
                    // Each child instance uses its parent's actual path and provider ID.
                    $scopesToRead = [$template];
                    if (preg_match('~^(.*)/\{[^{}]+\}(/[^/]+)$~D', $template, $match)) {
                        if (!isset($sets[$match[1]])) {
                            throw new InventoryError('parent_missing_from_plan');
                        }
                        $scopesToRead = [];
                        foreach ($sets[$match[1]] as $parentIndex) {
                            $parent = $scopes[$parentIndex];
                            foreach ($parent['items'] as $row) {
                                $scopesToRead[] = $parent['path'] . '/' . rawurlencode($row['id']) . $match[2];
                            }
                            if (!$parent['complete'] || !$parent['items']) {
                                $scopes[] = [
                                    'product'=>$product, 'base'=>$base, 'template'=>$template,
                                    'path'=>$parent['path'], 'items'=>[], 'pages'=>0,
                                    'status'=>$parent['complete'] ? 'no_parents' : 'parent_incomplete',
                                    'complete'=>$parent['complete'],
                                ];
                                $sets[$template][] = array_key_last($scopes);
                            }
                        }
                    }
                    foreach ($scopesToRead as $path) {
                        $scopes[] = ['product'=>$product, 'base'=>$base, 'template'=>$template, 'path'=>$path]
                            + $this->readCollection($base, $path, $options);
                        $sets[$template][] = array_key_last($scopes);
                    }
                    $sets[$template] ??= [];
                }
            }
        }
        return [
            'format'=>1, 'finishedAt'=>gmdate(DATE_ATOM),
            'complete'=>!in_array(false, array_column($scopes,'complete'), true),
            'scopes'=>$scopes,
        ];
    }

    /**
     * A failed later page retains observed rows with complete=false.
     * @param array<string,mixed> $options Collection and pagination contract from the resource plan.
     * @return array{status:string,complete:bool,pages:int,items:list<array>,http?:int}
     */
    private function readCollection(string $base, string $path, array $options): array
    {
        $rows = [];
        $seen = [];
        $pages = 0;
        $total = null;
        $cursor = null;
        $cursors = [];
        try {
            while ($pages < $this->maxPages) {
                $offset = count($rows);
                $query = isset($options['depth']) ? ['depth'=>$options['depth']] : [];
                if ($options['offset']) {
                    $query += ['limit'=>$this->pageSize, 'offset'=>$offset];
                } elseif (isset($options['cursor'])) {
                    $query['limit'] = $this->pageSize;
                    if ($cursor !== null) {
                        $query[$options['cursor']] = $cursor;
                    }
                }
                $data = ($this->get)($base, $path, $query);
                ++$pages;
                if ($options['offset'] && isset($data['offset']) && $data['offset'] !== $offset) {
                    throw new InventoryError('offset_mismatch');
                }
                $items = $data[$options['items'] ?? 'items'] ?? null;
                if (!is_array($items) || !array_is_list($items)) {
                    throw new InventoryError('invalid_items');
                }
                $reported = $data['totalCount'] ?? $data['total'] ?? null;
                if ($reported !== null) {
                    if (!is_int($reported) || $reported < 0 || ($total !== null && $reported !== $total)) {
                        throw new InventoryError('unstable_total');
                    }
                    $total = $reported;
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        throw new InventoryError('invalid_item');
                    }
                    $id = $item['id'] ?? null;
                    if ((!is_string($id) && !is_int($id)) || (string)$id === '' || isset($seen[$id])) {
                        throw new InventoryError('missing_or_repeated_id');
                    }
                    $seen[$id] = true;
                    $rows[] = self::project((string)$id, $item, $path);
                }
                $next = $data['_links']['next'] ?? $data['links']['next'] ?? null;
                $hasNext = $next !== null && $next !== '' && $next !== false;
                if (isset($options['cursor']) && $hasNext) {
                    $cursor = self::nextCursor($next, $base, $path, $options['cursor']);
                    if (!$items || isset($cursors[$cursor])) {
                        throw new InventoryError('stalled_cursor');
                    }
                    $cursors[$cursor] = true;
                    continue;
                }
                if (isset($options['verifyEmbedded'])) {
                    // This Cloud API collection currently ignores both limit and offset.
                    $dc = ($this->get)($base, dirname($path), ['depth'=>2]);
                    $embedded = $dc['entities'][$options['verifyEmbedded']] ?? [];
                    $references = $embedded['items'] ?? null;
                    if (!is_array($references) || !array_is_list($references)
                        || count($references) !== count($rows) || !empty($embedded['_links']['next'])) {
                        throw new InventoryError('parent_list_mismatch');
                    }
                    $ids = array_column($references, 'id');
                    $listed = array_column($rows, 'id');
                    sort($ids);
                    sort($listed);
                    if ($ids !== $listed || $hasNext || ($total !== null && $total !== count($rows))) {
                        throw new InventoryError('parent_list_mismatch');
                    }
                    return ['status'=>$rows ? 'present' : 'empty','complete'=>true,'pages'=>$pages,'items'=>$rows];
                }
                if (!$items && $hasNext && ($options['syntheticNext'] ?? false)
                    && ($data['offset'] ?? null) === $offset && ($data['limit'] ?? null) === $this->pageSize
                    && self::isSyntheticNext($next, $base . $path, $offset, $this->pageSize)) {
                    // Some regional APIs generate a forward link even on every empty page.
                    $hasNext = false;
                }
                if ($total !== null && count($rows) > $total) {
                    throw new InventoryError('count_mismatch');
                }
                // Never use a server-provided URL as the next authenticated destination.
                if (!$items || !$options['offset'] || ($total !== null && count($rows) === $total)) {
                    if ($hasNext || ($total !== null && count($rows) !== $total)) {
                        throw new InventoryError('unresolved_continuation');
                    }
                    return ['status'=>$rows ? 'present' : 'empty','complete'=>true,'pages'=>$pages,'items'=>$rows];
                }
            }
            throw new InventoryError('page_limit');
        } catch (InventoryError $failure) {
            return ['status'=>$failure->reason,'http'=>$failure->status,'complete'=>false,'pages'=>$pages,'items'=>$rows];
        }
    }

    /** Recognize the exact linear next-page link generated even for empty regional lists. */
    private static function isSyntheticNext(mixed $link, string $expected, int $offset, int $limit): bool
    {
        $link = is_array($link) ? ($link['href'] ?? null) : $link;
        if (!is_string($link)) {
            return false;
        }
        [$address, $query] = array_pad(explode('?', $link, 2), 2, '');
        if (!in_array($address, ['', $expected, parse_url($expected, PHP_URL_PATH)], true)) {
            return false;
        }
        $args = [];
        foreach (explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (!in_array($key, ['limit', 'offset'], true) || isset($args[$key])) {
                return false;
            }
            $args[$key] = $value;
        }
        return ($args['offset'] ?? '') === (string) ($offset + $limit)
            && ($args['limit'] ?? (string) $limit) === (string) $limit;
    }

    /** Read only the named query token from a same-resource navigation link. */
    private static function nextCursor(mixed $link, string $base, string $path, string $key): string
    {
        $link = is_array($link) ? ($link['href'] ?? null) : $link;
        if (!is_string($link)) {
            throw new InventoryError('invalid_cursor_link');
        }
        $expected = $base . $path;
        [$address, $query] = array_pad(explode('?', $link, 2), 2, '');
        if (!in_array($address, ['', $expected, parse_url($expected, PHP_URL_PATH)], true)) {
            throw new InventoryError('invalid_cursor_origin');
        }
        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if (urldecode($name) === $key && $value !== '') {
                return urldecode($value);
            }
        }
        throw new InventoryError('missing_cursor_token');
    }

    /**
     * Project inventory attributes by allowlist; DNS payloads, keys and configuration secrets are excluded.
     * @param array<string,mixed> $item One resource returned by its list operation.
     * @return array{id:string,attributes:array<string,mixed>,ipFacts:list<array>}
     */
    private static function project(string $id, array $item, string $path): array
    {
        $properties = $item['properties'] ?? [];
        if (!is_array($properties)) {
            throw new InventoryError('invalid_properties');
        }
        if ($path === '/ipsecgateways' || $path === '/wireguardgateways') {
            $keys = ['name','description','gatewayIP','tier','connections'];
            array_push($keys, ...($path === '/ipsecgateways' ? ['version']
                : ['listenPort','interfaceIPv4CIDR','interfaceIPv6CIDR']));
            $attrs = array_intersect_key($properties, array_flip($keys));
            if (isset($attrs['connections']) && is_array($attrs['connections'])) {
                $allowed = array_flip(['datacenterId','lanId','ipv4CIDR','ipv6CIDR']);
                $attrs['connections'] = array_map(static function ($connection) use ($allowed): ?array {
                    if (!is_array($connection)) {
                        return null;
                    }
                    return array_map(static fn ($value) => is_scalar($value) || $value === null ? $value : false,
                        array_intersect_key($connection, $allowed));
                }, $attrs['connections']);
            } elseif (array_key_exists('connections', $attrs)) {
                $attrs['connections'] = null;
            }
            foreach ($keys as $key) {
                if ($key !== 'connections' && isset($attrs[$key]) && !is_scalar($attrs[$key])) {
                    $attrs[$key] = false;
                }
            }
            $status = $item['metadata']['status'] ?? null;
            $attrs['providerStatus'] = $status === null || is_string($status) ? $status : false;
            return ['id'=>$id,'attributes'=>$attrs,'ipFacts'=>IpFacts::fromAttributes($attrs)];
        }
        $keys = ['name','location','type','size','cores','ram','cpuFamily','availabilityZone',
            'vmState','state','version','k8sVersion','storageSize','storageType','instances','public',
            'lan','listenerLan','targetLan','mac','ips','ipv6Ips','publicIps','listenerIp','listenerPort',
            'protocol','sourceSubnet','targetSubnet','publicIp','datacenterId','serverId','volumeId',
            'sourceIp','targetIp','sourceMac','ipVersion','icmpCode','icmpType','portRangeStart','portRangeEnd',
            'lbPrivateIps','algorithm','tier','protocolVersion','serverCertificates',
            'targetGroup','targetGroupId','healthCheckPort','replicas','zoneId','cidr','ip','port','weight',
            'digest','repositoryName','registryId','tags','mediaType','artifactType','publisher','image',
            'k8sClusterId','nodePoolId','nodepoolId','privateIps','ipv6CidrBlock','ipv6CidrBlocks',
            'ipv4CidrBlock','dhcp','cpuArchitecture','minNodeCount','maxNodeCount','nodeCount','region'];
        $attrs = [];
        $isNatGateway = str_ends_with($path, '/natgateways');
        $propertiesOnly = $isNatGateway || str_ends_with($path, '/networkloadbalancers');
        foreach ($keys as $key) {
            // Network reconciliation uses the provider's properties as its authoritative fields.
            $value = $properties[$key] ?? ($propertiesOnly ? null : ($item[$key] ?? null));
            if (is_scalar($value) || (is_array($value) && array_is_list($value)
                && count(array_filter($value,'is_scalar')) === count($value))) {
                $attrs[$key] = $value;
            }
        }
        if ($path === '/datacenters') {
            // An explicit null allocation and an omitted provider field have different sync semantics.
            foreach (['secAuthProtection', 'ipv6CidrBlock'] as $field) {
                if (array_key_exists($field, $properties)) {
                    $value = $properties[$field];
                    if ($value !== null && !($field === 'secAuthProtection' ? is_bool($value) : is_string($value))) {
                        throw new InventoryError('invalid_vdc_property');
                    }
                    $attrs[$field] = $value;
                }
            }
        }
        $metadata = $item['metadata'] ?? [];
        foreach (['state','location'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key])) {
                $attrs['provider' . ucfirst($key)] = $metadata[$key];
            }
        }
        $portRange = $properties['targetPortRange'] ?? null;
        if (is_array($portRange)) {
            $attrs['targetPortRange'] = array_filter(
                array_intersect_key($portRange, ['start'=>true, 'end'=>true]), 'is_int'
            );
        }
        foreach (['targets'=>['ip','port','weight','proxyProtocol','healthCheckEnabled','maintenanceEnabled'],
            'httpRules'=>['name','type','targetGroup','statusCode'],
            'lans'=>['id','gatewayIps'], 'connections'=>['datacenterId','lanId','cidr']] as $key=>$fields) {
            if ($key === 'lans' && $isNatGateway && array_key_exists($key, $properties)) {
                $attrs[$key] = self::projectNatLans($properties[$key]);
                continue;
            }
            if (isset($properties[$key]) && is_array($properties[$key])) {
                foreach ($properties[$key] as $element) {
                    if (is_array($element)) {
                        $attrs[$key][] = array_intersect_key($element,array_flip($fields));
                    }
                }
            }
        }
        return ['id'=>$id,'attributes'=>$attrs,'ipFacts'=>IpFacts::fromAttributes($attrs)];
    }

    /**
     * Preserve explicit empty lists and prevent malformed attachment entries from disappearing.
     * @return list<array{id:int|string,gatewayIps?:list<string>}>
     */
    private static function projectNatLans(mixed $lans): array
    {
        if (!is_array($lans) || !array_is_list($lans)) {
            throw new InventoryError('invalid_nat_lans');
        }
        $result = [];
        foreach ($lans as $entry) {
            $id = $entry['id'] ?? null;
            if (!is_int($id) && !is_string($id)) {
                throw new InventoryError('invalid_nat_lan_id');
            }
            $lan = ['id' => $id];
            if (array_key_exists('gatewayIps', $entry)) {
                $ips = $entry['gatewayIps'];
                if (!is_array($ips) || !array_is_list($ips) || count(array_filter($ips, 'is_string')) !== count($ips)) {
                    throw new InventoryError('invalid_nat_gateway_ips');
                }
                $lan['gatewayIps'] = $ips;
            }
            $result[] = $lan;
        }
        return $result;
    }
}
