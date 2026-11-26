<?php

/** Render snapshot coverage without revealing credentials or naming private resources. */

declare(strict_types=1);

namespace BR\IonosCloud\Collector;

/** Generate a human-readable coverage index alongside the full local inventory. */
final class Summary
{
    /**
     * Count row observations per resource route. A VM and its attached volume reference
     * can appear in several scopes; row counts therefore do not represent unique CIs.
     * @param array{startedAt:string,finishedAt:string,complete:bool,scopes:list<array>} $report
     */
    public static function markdown(array $report): string
    {
        $groups = [];
        foreach ($report['scopes'] as $scope) {
            $key = $scope['product'] . '|' . $scope['template'];
            $groups[$key] ??= ['observations'=>0,'statuses'=>[]];
            $groups[$key]['observations'] += count($scope['items']);
            $status = $scope['status'];
            $groups[$key]['statuses'][$status] = ($groups[$key]['statuses'][$status] ?? 0) + 1;
        }
        ksort($groups);
        $coverage = $report['complete'] ? 'Complete for the requested scope' : 'Partial for the requested scope';
        $lines = ['# IONOS inventory coverage','', $coverage . '.',
            '', 'Started ' . $report['startedAt'] . '. Finished ' . $report['finishedAt'] . '.',
            '', 'Counts are observations across regional endpoints, parent relationships and API versions. The JSON report contains the individual scopes, resource IDs, properties and IP observations.',
            '', '| Product | Resource collection | Observations | Scope results |','| --- | --- | ---: | --- |'];
        foreach ($groups as $key=>$group) {
            [$product,$path] = explode('|',$key,2);
            $statuses = [];
            foreach ($group['statuses'] as $name=>$count) {
                $statuses[] = $name . ' (' . $count . ')';
            }
            $lines[] = '| ' . $product . ' | ' . chr(96) . $path . chr(96) . ' | ' . $group['observations'] . ' | ' . implode(', ',$statuses) . ' |';
        }
        return implode("\n",$lines) . "\n";
    }
}
