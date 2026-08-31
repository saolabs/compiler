#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Subject: chạy preprocessor bằng bản PHP. Cùng hợp đồng với oracle.js. */

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Preprocessor\Preprocessor;
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

// Cố định để kết quả tái lập được; giá trị thật do index.js dựng từ sao.config
const ASSET_PREFIX = 'static/saola/web/assets/';

$root = repoRoot();
$splitter = new SourceSplitter();

while (($line = fgets(STDIN)) !== false) {
    $file = trim($line);
    if ($file === '') {
        continue;
    }

    try {
        $parts = $splitter->split((string) file_get_contents($file));
        $out = (new Preprocessor(ASSET_PREFIX))->preprocess($parts);
        $payload = [
            'declarations' => $out->declarations,
            'blade' => $out->blade,
            'bladeWithSSR' => $out->bladeWithSSR,
        ];
    } catch (Throwable $e) {
        $payload = ['__error__' => $e::class, '__message__' => $e->getMessage()];
    }

    printf(
        "%s\t%s\n",
        ltrim(str_replace($root, '', realpath($file) ?: $file), '/'),
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}
