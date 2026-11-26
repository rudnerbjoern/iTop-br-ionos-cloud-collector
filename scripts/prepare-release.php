<?php

/** Prepare a collector release and run the native checks. */

declare(strict_types=1);

require_once __DIR__ . '/release-version.php';

/** Validate a strictly increasing release version before changing the local version file. */
function nextReleaseVersion(string $current, string $next): string
{
    validateVersion($current);
    validateVersion($next);
    if (!version_compare($next, $current, '>')) {
        throw new RuntimeException('Release version must be newer than ' . $current);
    }
    return $next;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    if ($argc !== 2) {
        throw new RuntimeException('Usage: php scripts/prepare-release.php X.Y.Z');
    }
    $root = dirname(__DIR__);
    $path = $root . '/VERSION';
    $next = nextReleaseVersion(trim(file_get_contents($path)), $argv[1]);
    if (file_put_contents($path, $next . "\n", LOCK_EX) !== strlen($next) + 1) {
        throw new RuntimeException('Could not update VERSION.');
    }
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/run.php'), $status);
    exit($status);
}
