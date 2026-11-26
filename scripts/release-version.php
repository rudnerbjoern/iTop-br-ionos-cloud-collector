<?php

/** Validate collector release metadata for the release workflow. */

declare(strict_types=1);

/** Return a validated three-part release number or throw on invalid metadata. */
function validateVersion(string $version): string
{
    if (!preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $version)) {
        throw new RuntimeException('Expected a version in X.Y.Z format without leading zeros.');
    }
    return $version;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $root = dirname(__DIR__);
    $version = validateVersion(trim(file_get_contents($root . '/VERSION')));
    if (($argv[1] ?? '') !== '' && $argv[1] !== 'release/' . $version) {
        throw new RuntimeException('Release branch and project version must match.');
    }
    if (!is_file($root . '/collectors/main.php')) {
        throw new RuntimeException('Publication requires the collector entry point in collectors/main.php.');
    }
    echo $version, PHP_EOL;
}
