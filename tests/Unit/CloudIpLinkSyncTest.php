<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use BR\IonosCloud\Collector\CloudIpLinkSync;
use BR\IonosCloud\Collector\Tests\Support\RestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../collectors/src/CloudIpLinkSync.php';
require_once __DIR__ . '/../Support/RestResponse.php';

/** Exercise link reconciliation against in-memory iTop REST responses. */
final class CloudIpLinkSyncTest extends TestCase
{
    private const LINK = 'lnkCloudToIPObject';
    private const SOURCE = 'IONOS VDC IPv6 - org 27 - provider 18 - contract 123';
    private const OTHER_SOURCE = 'IONOS VDC IPv6 - org 27 - provider 18 - contract 456';

    /** Manual links, another contract's link and an owned link on a different VDC. */
    private const EXISTING = [
        1 => [82, 51, ''],
        2 => [82, 52, null],
        3 => [82, 53, self::OTHER_SOURCE],
        4 => [83, 77, self::SOURCE],
    ];

    /** @var array<int,array{int,int,?string}> Link ID to parent, target and source. */
    private array $links = self::EXISTING;

    /** @var list<string> Observed REST writes in execution order. */
    private array $writes = [];

    /** Next link ID in the simulated iTop database. */
    private int $nextId = 5;

    /** VDC whose read response omits the link source. */
    private ?int $unreadableCloud = null;

    /** Simulate a transport failure before inserting the link. */
    private bool $failCreate = false;

    /** Simulate an unsuccessful deletion in the REST object's result code. */
    private bool $failDelete = false;

    /** Simulate the unique link already being created by a user. */
    private bool $concurrentManualCreate = false;

    /** Replaying an allocation retains the original link ID. */
    public function testRepeatedAllocationCreatesOneOwnedLink(): void
    {
        $this->apply(['vdc' => 71]);
        $this->apply(['vdc' => 71]);

        self::assertSame(['create'], $this->writes);
        self::assertSame(self::EXISTING + [5 => [82, 71, self::SOURCE]], $this->links);
    }

    /** All participating VDCs must be readable before retiring the first link. */
    public function testUnreadableSourceOnSecondCloudAbortsBeforeAnyWrite(): void
    {
        $this->apply(['vdc' => 71]);
        $this->unreadableCloud = 83;
        $this->writes = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid or ambiguous Cloud IP association.');

        try {
            $this->apply(['vdc' => null, 'other' => null]);
        } finally {
            self::assertSame([], $this->writes);
            self::assertSame(self::EXISTING + [5 => [82, 71, self::SOURCE]], $this->links);
        }
    }

    /** A failed replacement keeps the previous allocation and conceals transport details. */
    public function testFailedCreateRetainsPreviousLink(): void
    {
        $this->apply(['vdc' => 71]);
        $this->failCreate = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Could not establish the new IPv6 allocation\. Previous links were retained\.$/');

        try {
            $this->apply(['vdc' => 72]);
        } finally {
            self::assertSame(['create', 'create'], $this->writes);
            self::assertSame(self::EXISTING + [5 => [82, 71, self::SOURCE]], $this->links);
        }
    }

    /** The new link survives an unsuccessful cleanup and the next run only retries the delete. */
    public function testFailedDeleteCanBeRetriedWithoutDuplicatingNewLink(): void
    {
        $this->apply(['vdc' => 71]);
        $this->writes = [];
        $this->failDelete = true;
        try {
            $this->apply(['vdc' => 72]);
            self::fail('Expected iTop to reject deletion of the old link.');
        } catch (RuntimeException $failure) {
            self::assertSame('The previous IONOS IPv6 association could not be removed.', $failure->getMessage());
        }
        self::assertSame(['create', 'delete'], $this->writes);
        self::assertSame(self::EXISTING + [5 => [82, 71, self::SOURCE], 6 => [82, 72, self::SOURCE]], $this->links);

        $this->failDelete = false;
        $this->apply(['vdc' => 72]);

        self::assertSame(['create', 'delete', 'delete'], $this->writes);
        self::assertSame(self::EXISTING + [6 => [82, 72, self::SOURCE]], $this->links);
    }

    /** Releasing a prefix twice removes only the observed VDC's owned link. */
    public function testExplicitReleasePreservesManualForeignAndUnobservedLinks(): void
    {
        $this->apply(['vdc' => 71]);
        $this->apply(['vdc' => null]);
        $this->apply(['vdc' => null]);

        self::assertSame(['create', 'delete'], $this->writes);
        self::assertSame(self::EXISTING, $this->links);
    }

    /** A preexisting manual or foreign link never acquires collector ownership. */
    #[DataProvider('existingTargets')]
    public function testMatchingExistingTargetSurvivesRelease(int $target): void
    {
        $this->apply(['vdc' => $target]);
        $this->apply(['vdc' => null]);

        self::assertSame([], $this->writes);
        self::assertSame(self::EXISTING, $this->links);
    }

    /** @return array<string,array{int}> Targets with manual and third-party ownership. */
    public static function existingTargets(): array
    {
        return [
            'manual with empty source' => [51],
            'manual with null source' => [52],
            'other IONOS contract' => [53],
        ];
    }

    /** A concurrently added manual link can satisfy the new allocation and survives release. */
    public function testConcurrentManualInsertSurvivesPrefixChangeAndRelease(): void
    {
        $this->apply(['vdc' => 71]);
        $this->concurrentManualCreate = true;
        $this->apply(['vdc' => 72]);
        $this->apply(['vdc' => null]);

        self::assertSame(['create', 'create', 'delete'], $this->writes);
        self::assertSame(self::EXISTING + [6 => [82, 72, '']], $this->links);
    }

    /** @param array<string,?int> $allocations VDC key and reported IPv6 block ID. */
    private function apply(array $allocations): void
    {
        $sync = new CloudIpLinkSync($this->readLinks(...), $this->createLink(...), $this->deleteLink(...));
        $resolve = static fn (string $uuid): int => ['vdc' => 82, 'other' => 83][$uuid];
        $sync->apply($allocations, $resolve, self::SOURCE);
    }

    /**
     * Read the link provenance for exactly one Cloud.
     * @param array<string,int> $key REST selection.
     * @return array<string,mixed> Native core/get reply.
     */
    private function readLinks(string $class, array $key, string $fields): array
    {
        self::assertSame(self::LINK, $class);
        self::assertSame(['cloud_id'], array_keys($key));
        self::assertSame('cloud_id,ipobject_id,source_key', $fields);
        $objects = [];
        foreach ($this->links as $id => [$cloud, $target, $source]) {
            if ($cloud !== $key['cloud_id']) {
                continue;
            }
            $values = ['cloud_id' => $cloud, 'ipobject_id' => $target, 'source_key' => $source];
            if ($cloud === $this->unreadableCloud) {
                unset($values['source_key']);
            }
            $objects += RestResponse::one($class, $id, $values)['objects'];
        }
        return ['code' => 0, 'message' => 'Found: ' . count($objects), 'objects' => $objects ?: null];
    }

    /**
     * Record a source-attributed insert and optionally simulate a failed or concurrent create.
     * @param array{cloud_id:int,ipobject_id:int,source_key:string} $fields New link fields.
     * @return array{code:int} Native core/create status.
     */
    private function createLink(string $class, array $fields): array
    {
        self::assertSame(self::LINK, $class);
        self::assertSame(self::SOURCE, $fields['source_key']);
        $this->writes[] = 'create';
        if ($this->failCreate) {
            throw new RuntimeException('private fixture');
        }
        $source = $this->concurrentManualCreate ? '' : $fields['source_key'];
        $this->links[$this->nextId++] = [$fields['cloud_id'], $fields['ipobject_id'], $source];
        if ($this->concurrentManualCreate) {
            throw new RuntimeException('A manual association was created concurrently.');
        }
        return ['code' => 0];
    }

    /**
     * Require the observed ID, parent, target and source for every deletion.
     * @param array{id:int,cloud_id:int,ipobject_id:int,source_key:string} $key Guarded lookup.
     * @return array<string,mixed> Native core/delete reply.
     */
    private function deleteLink(array $key): array
    {
        $this->writes[] = 'delete';
        $id = $key['id'];
        self::assertSame(self::SOURCE, $key['source_key']);
        self::assertSame([$key['cloud_id'], $key['ipobject_id'], $key['source_key']], $this->links[$id]);
        if (!$this->failDelete) {
            unset($this->links[$id]);
        }
        return ['code' => 0, 'objects' => [self::LINK . '::' . $id => ['code' => $this->failDelete ? 1 : 0]]];
    }
}
