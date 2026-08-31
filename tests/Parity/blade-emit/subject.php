#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Emit\BladeEmitter;

$rows = [];

while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line, "\n");
    if ($line === '') {
        continue;
    }

    [$relativePath, $raw] = explode("\t", $line, 2);
    $content = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    try {
        $output = (new BladeEmitter())->compile($content);
    } catch (Throwable $e) {
        $short = substr(strrchr($e::class, '\\') ?: $e::class, 1);
        $name = $short === '' ? $e::class : $short;
        $output = "__ERROR__ {$name}: {$e->getMessage()}";
    }

    $rows[] = [$relativePath, json_encode(
        $output,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )];
}

foreach ($rows as [$relativePath, $output]) {
    echo $relativePath, "\t", $output, "\n";
}
