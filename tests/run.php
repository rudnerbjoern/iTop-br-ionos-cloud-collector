<?php

declare(strict_types=1);

/** Run the repository's PHPUnit suite from the existing CLI and release entry point. */
$root = dirname(__DIR__);
$phpunit = $root . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
    fwrite(STDERR, "Run composer install in the collector checkout before running the tests.\n");
    exit(1);
}

$process = proc_open([PHP_BINARY, $phpunit, '--configuration', $root . '/phpunit.xml.dist', ...array_slice($argv, 1)],
    [STDIN, STDOUT, STDERR], $pipes, $root);
exit(is_resource($process) ? proc_close($process) : 1);
