#!/usr/bin/env php
<?php
/** In chỗ lệch ĐẦU TIÊN giữa golden và output hiện tại, ở mức byte. */
declare(strict_types=1);

$left = file($argv[1] ?? '', FILE_IGNORE_NEW_LINES) ?: [];
$right = file($argv[2] ?? '', FILE_IGNORE_NEW_LINES) ?: [];

foreach ($left as $i => $a) {
    $b = $right[$i] ?? '';
    if ($a === $b) {
        continue;
    }
    [$name, $aj] = array_pad(explode("\t", $a, 2), 2, '');
    [, $bj] = array_pad(explode("\t", $b, 2), 2, '');
    $av = json_decode($aj, true) ?? [];
    $bv = json_decode($bj, true) ?? [];

    echo "Lệch đầu tiên: {$name}\n";
    if (!($av['ok'] ?? false) || !($bv['ok'] ?? false)) {
        echo 'golden : ', json_encode($av, JSON_UNESCAPED_UNICODE), "\n";
        echo 'hiện tại: ', json_encode($bv, JSON_UNESCAPED_UNICODE), "\n";
        break;
    }
    $x = (string) base64_decode((string) $av['base64'], true);
    $y = (string) base64_decode((string) $bv['base64'], true);
    $len = min(strlen($x), strlen($y));
    $k = 0;
    while ($k < $len && $x[$k] === $y[$k]) {
        $k++;
    }
    printf("byte: %d  golden-len: %d  hiện tại-len: %d\n", $k, strlen($x), strlen($y));
    $from = max(0, $k - 180);
    echo 'golden  : ', var_export(substr($x, $from, 500), true), "\n";
    echo 'hiện tại: ', var_export(substr($y, $from, 500), true), "\n";
    break;
}
