#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Subject: chuyển biểu thức bằng bản PHP. Cùng hợp đồng với oracle.py. */

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Expr\ExpressionCompiler;

$compiler = new ExpressionCompiler();
$compiler->setUserMethods([], 'parity.view');

while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line, "\n");
    if ($line === '') {
        continue;
    }

    [$funcName, $raw] = explode("\t", $line, 2);
    $expr = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    try {
        $result = match ($funcName) {
            'php_to_js' => $compiler->compileStatement($expr),
            'php_to_js_advanced' => $compiler->compile($expr),
        };
    } catch (Throwable $e) {
        $result = '<<ERROR ' . $e::class . '>>';
    }

    printf("%s\t%s\t%s\n", $funcName, $raw, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
