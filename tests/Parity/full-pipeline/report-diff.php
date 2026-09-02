#!/usr/bin/env php
<?php
/**
 * In chỗ lệch giữa ảnh chụp golden và output hiện tại, ở mức byte.
 *
 * Thay khối python3 nội tuyến cũ — dự án chỉ còn PHP và JS/TS.
 */
declare(strict_types=1);

/** @return array<string,array<string,mixed>> */
$load = static function (string $path): array {
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        [$key, $json] = array_pad(explode("\t", $line, 2), 2, '');
        $rows[$key] = json_decode($json, true) ?? [];
    }

    return $rows;
};

$golden = $load($argv[1] ?? '');
$current = $load($argv[2] ?? '');

$text = static fn (array $row, string $field): string => ($row['ok'] ?? false)
    ? (string) base64_decode((string) ($row[$field] ?? ''), true)
    : (string) ($row['error'] ?? '');

$shown = 0;
foreach ($golden as $key => $g) {
    $c = $current[$key] ?? [];
    if ($g === $c || $shown >= 3) {
        continue;
    }
    $shown++;
    echo "\n### {$key}\n";

    foreach (['blade', 'js'] as $field) {
        $a = $text($g, $field);
        $b = $text($c, $field);
        if ($a === $b) {
            continue;
        }
        $len = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $len && $a[$i] === $b[$i]) {
            $i++;
        }
        if ($i === $len) {
            printf("  [%s] khác độ dài: %d vs %d\n", $field, strlen($a), strlen($b));
            continue;
        }
        $from = max(0, $i - 70);
        printf("  [%s] lệch tại byte %d\n", $field, $i);
        printf("    golden : %s\n", var_export(substr($a, $from, 140), true));
        printf("    hiện tại: %s\n", var_export(substr($b, $from, 140), true));
    }
}
