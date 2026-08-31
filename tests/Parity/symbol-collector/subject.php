#!/usr/bin/env php
<?php

declare(strict_types=1);

/** Subject: dựng bảng ký hiệu bằng bản PHP. Cùng hợp đồng với oracle.js. */

require __DIR__ . '/../../../vendor/autoload.php';

use Saola\Compiler\Preprocessor\SymbolCollector;

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

/**
 * SymbolTable không mở ra danh sách khoá (transformer chỉ cần has/get).
 * Cho việc đối chiếu thì cần đọc toàn bộ, nên dùng reflection ở ĐÂY —
 * trong test — thay vì nới API sản phẩm chỉ để phục vụ test.
 */
function dumpTable(object $table): array
{
    $property = new ReflectionProperty($table, 'symbols');

    /** @var array<string, \Saola\Compiler\Preprocessor\Symbol> $symbols */
    $symbols = $property->getValue($table);

    ksort($symbols, SORT_STRING);

    $out = [];
    foreach ($symbols as $name => $symbol) {
        $out[(string) $name] = [
            'type' => $symbol->type->value,
            'source' => $symbol->source,
            'stateOf' => $symbol->stateOf,
            'assetPath' => $symbol->assetPath,
            'pattern' => $symbol->pattern,
        ];
    }

    return $out;
}

while (($line = fgets(STDIN)) !== false) {
    $file = trim($line);
    if ($file === '') {
        continue;
    }

    try {
        $table = (new SymbolCollector())->collect((string) file_get_contents($file));
        $payload = dumpTable($table);
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
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
    );
}
