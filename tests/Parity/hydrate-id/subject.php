#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Subject: mã hoá hydrate id bằng bản PHP.
 *
 * Cùng hợp đồng với oracle.py — stdin base_id, stdout `mode<TAB>id<TAB>hash`.
 */

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Hydration\HydrateId;
use Saola\Compiler\Hydration\IdMode;

$baseIds = [];
while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line, "\n");
    if (trim($line) !== '') {
        $baseIds[] = $line;
    }
}

foreach (['terse', 'compact', 'md5', 'raw'] as $mode) {
    $idMode = IdMode::from($mode);

    foreach ($baseIds as $baseId) {
        printf("%s\t%s\t%s\n", $mode, $baseId, HydrateId::hash($baseId, $idMode));
    }
}
