<?php

declare(strict_types=1);

namespace BR\IonosCloud\Collector\Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

require_once __DIR__ . '/../../scripts/prepare-release.php';

/** Verify the files and metadata shipped with the collector. */
final class CollectorPackageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** The published version must use the release script's canonical format. */
    public function testProjectVersionIsValid(): void
    {
        $version = trim(file_get_contents(self::ROOT . '/VERSION'));
        self::assertSame($version, validateVersion($version));
    }

    /** A valid next release is returned unchanged. */
    public function testAcceptsPatchRelease(): void
    {
        self::assertSame('1.2.4', nextReleaseVersion('1.2.3', '1.2.4'));
    }

    /** @return array<string, array{string}> Invalid candidates for a 1.2.3 release. */
    public static function invalidReleaseVersions(): array
    {
        return [
            'missing patch' => ['1.2'],
            'leading zero' => ['1.02.4'],
            'same version' => ['1.2.3'],
            'older version' => ['1.2.2'],
        ];
    }

    /** Only a newer, well-formed version is accepted for publication. */
    #[DataProvider('invalidReleaseVersions')]
    public function testRejectsInvalidRelease(string $candidate): void
    {
        $this->expectException(RuntimeException::class);
        nextReleaseVersion('1.2.3', $candidate);
    }

    /** Both deployment templates must expose the same settings and ship empty credentials. */
    public function testParameterTemplatesMatchAndContainEmptyCredentials(): void
    {
        $settings = [];
        foreach (['collectors/params.distrib.xml', 'conf/params.example.xml'] as $path) {
            $template = simplexml_load_file(self::ROOT . '/' . $path, options: LIBXML_NONET);
            self::assertNotFalse($template, $path);
            self::assertSame('parameters', $template->getName(), $path);
            $ranks = [];
            foreach ($template->collectors_launch_sequence->collector as $stage) {
                self::assertSame('yes', (string) $stage->enable);
                $ranks[(string) $stage->name] = (int) $stage->rank;
            }
            self::assertSame([
                'BRIonosVdcCollector' => 1, 'BRIonosLanCollector' => 2, 'BRIonosNatIpv4Collector' => 7,
                'BRIonosNatIpv6Collector' => 8, 'BRIonosNatGatewayCollector' => 22,
            ], $ranks);
            $settings[$path] = array_keys((array) $template);
            foreach (['itop_login', 'itop_password', 'itop_token'] as $key) {
                self::assertTrue(isset($template->$key), "$path is missing $key");
                self::assertSame('', (string) $template->$key, "$path contains a value for $key");
            }
        }
        self::assertSame($settings['collectors/params.distrib.xml'], $settings['conf/params.example.xml']);
    }

    /** @return iterable<string, array{string}> Runtime PHP files and synchronization definitions. */
    public static function runtimeFiles(): iterable
    {
        $root = realpath(self::ROOT . '/collectors');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'json'], true)) {
                $path = $file->getPathname();
                yield substr($path, strlen($root) + 1) => [$path];
            }
        }
    }

    /** Check each shipped PHP or JSON file without loading the collector runtime. */
    #[DataProvider('runtimeFiles')]
    public function testRuntimeFileSyntax(string $path): void
    {
        if (str_ends_with($path, '.json')) {
            self::assertJson(file_get_contents($path));
            return;
        }
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        self::assertSame(0, proc_close($process), $output);
    }
}
