#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Subject: chạy DeclarationTracker bản PHP. Cùng hợp đồng với oracle.py. */

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Declaration\Declaration;
use Saola\Compiler\Declaration\DeclarationTracker;

function repoRoot(): string
{
    $dir = __DIR__;
    while ($dir !== dirname($dir)) {
        $dir = dirname($dir);
        if (is_file($dir . '/compiler/src/index.js')) {
            return $dir;
        }
    }
    throw new RuntimeException('Không tìm thấy repo root');
}

$root = repoRoot();

while (($line = fgets(STDIN)) !== false) {
    $file = trim($line);
    if ($file === '') {
        continue;
    }

    try {
        // Instance MỚI mỗi file — tracker giữ trạng thái giữa các lần gọi
        $declarations = (new DeclarationTracker())->parseAll((string) file_get_contents($file));
        // Bỏ 'position' — xem giải thích trong run.sh
        $payload = array_map(
            static function (Declaration $d): array {
                $row = $d->toArray();
                unset($row['position']);

                return $row;
            },
            $declarations,
        );
    } catch (Throwable $e) {
        $payload = ['__error__' => substr(strrchr($e::class, '\\') ?: $e::class, 1)];
    }

    printf(
        "%s\t%s\n",
        ltrim(str_replace($root, '', realpath($file) ?: $file), '/'),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}
