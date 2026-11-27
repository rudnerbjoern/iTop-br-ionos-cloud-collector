<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

use Closure;
use RuntimeException;

require_once __DIR__ . '/InventoryReconciler.php';

/** Resolve both native LAN keys and reconcile source-owned NLB assignments. */
final class NlbSync
{
    private const BALANCER = 'CloudNetworkLoadBalancer';
    private const LISTENER = 'CloudNetworkLoadBalancerListenerAddress';
    private const BACKEND = 'CloudNetworkLoadBalancerBackendAddress';
    private const MODEL = [
        self::BALANCER => ['cloud_id', 'provider_resource_id', ['name', 'org_id', 'listener_lan_id', 'backend_lan_id'],
            [self::LISTENER, self::BACKEND]],
        self::LISTENER => ['load_balancer_id', 'address', [], []],
        self::BACKEND => ['load_balancer_id', 'address', ['prefix_length'], []],
    ];

    private readonly InventoryReconciler $reconciler;

    /** @param Closure(int,int,string,?int,bool):int|null $resolveIp Resolve the address in the customer's IPAM. */
    public function __construct(
        private readonly InventoryStore $store,
        string $source,
        private readonly ?Closure $resolveIp = null,
    ) {
        $this->reconciler = new InventoryReconciler($store, $source, self::BALANCER, self::MODEL,
            $resolveIp !== null, ['listener_lan_id', 'backend_lan_id']);
    }

    /**
     * @param list<array> $balancers Validated NLB observations.
     * @param list<array> $vdcs All observed VDCs, including ones with an empty NLB list.
     * @param Closure(string):int $resolveCloud Resolve the UUID within the configured provider and organization.
     */
    public function apply(array $balancers, array $vdcs, Closure $resolveCloud): void
    {
        $clouds = $organizations = $trees = $lanIds = [];
        foreach ($vdcs as $vdc) {
            $uuid = $vdc['provider_resource_id'];
            $cloud = $clouds[$uuid] = $resolveCloud($uuid);
            $organizations[$cloud] = $vdc['org_id'];
            $trees[$cloud] = [];
        }
        foreach ($balancers as $balancer) {
            $cloud = $clouds[$balancer['cloud_resource_id']];
            foreach ([$balancer['listener_lan'], $balancer['backend_lan']] as $lan) {
                $lanIds[$cloud][$lan] ??= $this->lan($cloud, $balancer['org_id'], $lan);
            }
        }
        foreach ($balancers as $balancer) {
            $cloud = $clouds[$balancer['cloud_resource_id']];
            $org = $balancer['org_id'];
            $listener = $backend = [];
            foreach ($balancer['listener_ips'] as $host => $prefix) {
                $key = $this->resolveIp === null ? $host : ($this->resolveIp)($org, $cloud, $host, $prefix, !$balancer['listener_public']);
                $listener[$key] = [];
            }
            foreach ($balancer['backend_ips'] as $host => $prefix) {
                $key = $this->resolveIp === null ? $host : ($this->resolveIp)($org, $cloud, $host, $prefix, true);
                $backend[$key] = ['fields' => ['prefix_length' => $prefix]];
            }
            $trees[$cloud][$balancer['provider_resource_id']] = [
                'fields' => ['org_id' => $org, 'name' => $balancer['name'],
                    'listener_lan_id' => $lanIds[$cloud][$balancer['listener_lan']],
                    'backend_lan_id' => $lanIds[$cloud][$balancer['backend_lan']]],
                'children' => [self::LISTENER => $listener, self::BACKEND => $backend],
            ];
        }
        $this->reconciler->apply($trees, $organizations);
    }

    /** Require an exact LAN match in the owning Cloud and customer. */
    private function lan(int $cloud, int $org, string $providerId): int
    {
        $rows = $this->store->rows('CloudLan', ['cloud_id' => $cloud, 'provider_resource_id' => $providerId], ['org_id']);
        if (count($rows) !== 1 || (int) $rows[0]['org_id'] !== $org) {
            throw new RuntimeException('Cannot identify a network load balancer LAN in its owning Cloud and organization.');
        }
        return $rows[0]['id'];
    }
}
