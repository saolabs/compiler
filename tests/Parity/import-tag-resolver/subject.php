#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Template\ImportTagResolver;

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    $call = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    [$code, $imports, $target] = $call['args'];

    try {
        $value = (new ImportTagResolver($imports, $target))->resolveTags($code);
        $result = ['ok' => true, 'value' => $value];
    } catch (Throwable $e) {
        $short = substr(strrchr($e::class, '\\') ?: $e::class, 1);
        $result = ['ok' => false, 'value' => $short === '' ? $e::class : $short];
    }

    printf(
        "%s\t%s\n",
        $line,
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}
