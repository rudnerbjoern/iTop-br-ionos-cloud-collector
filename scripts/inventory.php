<?php

/** Run a local, read-only IONOS inventory and return its coverage status. */

declare(strict_types=1);

use BR\IonosCloud\Collector\InventoryError;
use BR\IonosCloud\Collector\Run;

require_once dirname(__DIR__) . '/collectors/src/Run.php';

try {
    $root = dirname(__DIR__);
    $config = [];
    foreach ([$root . '/collectors/params.distrib.xml', $root . '/conf/params.local.xml'] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $xml = simplexml_load_file($file, SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false || $xml->getName() !== 'parameters') {
            throw new InventoryError('invalid_configuration');
        }
        foreach ($xml->children() as $key=>$value) {
            if (str_starts_with($key,'ionos_')) {
                $config[$key] = (string)$value;
            }
        }
    }
    exit(Run::execute($root,$config) ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR,'Inventory failed: ' . ($error instanceof InventoryError ? $error->reason : 'local_error') . "\n");
    exit(1);
}
