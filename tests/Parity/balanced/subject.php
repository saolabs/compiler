#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Subject: chạy cả hai biến thể của Balanced. Cùng hợp đồng với oracle.js. */

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Support\Balanced;

function run(callable $fn): array
{
    try {
        return ['ok' => true, 'value' => $fn()];
    } catch (Throwable $e) {
        return ['ok' => false, 'value' => $e::class];
    }
}

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === '') {
        continue;
    }

    $input = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

    // Xem giải thích trong oracle.js: so CẶP CHUỖI, không so chỉ số thô —
    // JS đếm theo code unit UTF-16, PHP theo byte.
    $halves = static function (callable $find) use ($input): array {
        return run(static function () use ($find, $input) {
            $at = $find();

            return $at === -1
                ? null
                : [substr($input, 0, $at), substr($input, $at + 1)];
        });
    };

    $result = [
        'splitStrict' => run(static fn () => Balanced::splitTopLevel($input, ',')),
        'findStrict' => $halves(static fn () => Balanced::findAssignment($input)),
        'splitLoose' => run(static fn () => Balanced::splitTopLevelLoose($input, ',')),
        'findLoose' => $halves(static fn () => Balanced::findAssignmentLoose($input)),
        'extract' => run(static fn () => Balanced::extractParens($input, 0)),
    ];

    printf(
        "%s\t%s\n",
        json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}
