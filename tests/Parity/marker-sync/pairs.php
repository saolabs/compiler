#!/usr/bin/env php
<?php
/** Bóc cặp (blade, js) đã compile từ output của full-pipeline/subject.php. */
declare(strict_types=1);

foreach (file($argv[1] ?? '', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if ($line === '') {
        continue;
    }
    [$name, $raw] = array_pad(explode("\t", $line, 2), 2, '');
    $r = json_decode($raw, true);
    if (!is_array($r) || !($r['ok'] ?? false)) {
        continue;
    }
    echo json_encode([
        'name' => $name,
        'blade' => base64_decode((string) ($r['blade'] ?? ''), true),
        'js' => base64_decode((string) ($r['js'] ?? ''), true),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}
