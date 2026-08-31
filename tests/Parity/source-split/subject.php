#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Subject: tách file .sao bằng bản PHP. Cùng hợp đồng với oracle.js. */

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Source\SourceSplitter;

function repoRoot(): string
{
    $dir = __DIR__;

    while ($dir !== dirname($dir)) {
        $dir = dirname($dir);
        if (is_file($dir . '/builder/src/index.js')) {
            return $dir;
        }
    }

    throw new RuntimeException('Không tìm thấy repo root');
}

$root = repoRoot();
$splitter = new SourceSplitter();

while (($line = fgets(STDIN)) !== false) {
    $file = trim($line);
    if ($file === '') {
        continue;
    }

    try {
        $parts = $splitter->split((string) file_get_contents($file));
        $payload = [
            'declarations' => $parts->declarations,
            'blade' => $parts->blade,
            'bladeWithSSR' => $parts->bladeWithSSR,
            'script' => $parts->script,
            'style' => $parts->style,
            'cleanedContent' => $parts->cleanedContent,
            'wrapperType' => $parts->wrapperType,
        ];
    } catch (Throwable $e) {
        $payload = ['__error__' => $e::class];
    }

    // realpath() bỏ '..' đi — oracle bên JS dùng path.relative() vốn tự chuẩn
    // hoá, nên không normalize ở đây thì đường dẫn hai bên lệch nhau và diff
    // báo đỏ ở nơi không có lỗi thật.
    $relative = ltrim(str_replace($root, '', realpath($file) ?: $file), '/');

    printf(
        "%s\t%s\n",
        $relative,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}
