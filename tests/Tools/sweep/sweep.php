#!/usr/bin/env php
<?php
/**
 * Sweep — săn lỗi IM LẶNG, thứ mà cổng golden không thấy.
 *
 * Cổng golden chứng minh "compiler không đổi hành vi ngoài ý muốn". Nó KHÔNG
 * chứng minh hành vi đó đúng: cả 5 bug ở docs/05-roadmap.md §15 đều xanh suốt
 * vì ảnh chụp cũng chụp luôn cái sai.
 *
 *   ./sweep.php cases     sinh cases/*.sao từ cases.json (86 tổ hợp cú pháp)
 *   ./sweep.php check     soát bất biến SSR↔CSR trên cases/
 *   ./sweep.php leak      24 ca × 2 kiểu bọc: mã trong {{-- --}} / @verbatim
 *                         có thành mã thật không
 *   ./sweep.php clean     xoá cases/
 *
 * `leak` không cần `cases` — nó tự sinh ca. Bug "mã trong chú thích" đã xuất
 * hiện ở SÁU khâu quét khác nhau (§16, §20, §21), mỗi lần vá một chỗ lại lộ chỗ
 * kế. Chạy lại nó sau mỗi lần thêm khâu quét mới.
 */
declare(strict_types=1);

const VOID_TAGS = ['br','hr','img','input','meta','link','source','track','area',
                   'base','col','embed','param','wbr','path'];

$DIR = __DIR__;
$COMPILER = dirname($DIR, 3);   // …/compiler — KHÔNG phải php-compiler/ đã gỡ

/** Compile một file .sao, trả [dữ liệu, lỗi]. */
function compileFile(string $path): array
{
    global $COMPILER;
    // stderr PHẢI tách khỏi stdout: compiler ghi cảnh báo ra stderr, trộn vào là
    // JSON hỏng và ca "compiler tự báo" bị đếm nhầm thành lỗi compile.
    $err = tempnam(sys_get_temp_dir(), 'sweep');
    $cmd = sprintf(
        'cd %s && %s compile %s --view-path=t.v --fn=V --factory=VF --json 2>%s',
        escapeshellarg($COMPILER),
        escapeshellarg($COMPILER . '/bin/saoc'),
        escapeshellarg(realpath($path) ?: $path),
        escapeshellarg($err),
    );
    exec($cmd, $lines, $code);
    $out = implode("\n", $lines);
    $stderr = trim((string) file_get_contents($err));
    @unlink($err);

    if ($code !== 0) {
        return [null, substr($stderr !== '' ? $stderr : $out, 0, 160)];
    }
    $data = json_decode($out, true);
    if (!is_array($data)) {
        return [null, 'JSON lỗi: ' . substr($out, 0, 100)];
    }
    // Cảnh báo ra stderr cũng tính là "compiler đã tự báo".
    if ($stderr !== '' && empty($data['warnings'])) {
        $data['warnings'] = [$stderr];
    }

    return [$data, null];
}

function compileSource(string $src): array
{
    $tmp = sys_get_temp_dir() . '/_sweep_' . getmypid() . '.sao';
    file_put_contents($tmp, $src);
    $r = compileFile($tmp);
    @unlink($tmp);

    return $r;
}

/** @return list<string> */
function matches(string $re, string $s, int $g = 1): array
{
    preg_match_all($re, $s, $m);

    return $m[$g] ?? [];
}

// ─────────────────────────── lệnh: cases ───────────────────────────
function cmdCases(string $dir): int
{
    $cases = json_decode((string) file_get_contents("$dir/cases.json"), true) ?: [];
    @mkdir("$dir/cases", 0777, true);
    foreach ($cases as $name => $src) {
        file_put_contents("$dir/cases/{$name}.sao", $src . "\n");
    }
    echo count($cases), " ca\n";

    return 0;
}

// ─────────────────────────── lệnh: check ───────────────────────────
function cmdCheck(string $dir): int
{
    $files = glob("$dir/cases/*.sao") ?: [];
    if ($files === []) {
        echo "Chưa có cases/ — chạy: ./sweep.php cases\n";

        return 1;
    }

    $bad = $warned = [];
    foreach ($files as $f) {
        $name = basename($f, '.sao');
        [$d, $err] = compileFile($f);
        if ($err !== null) {
            $bad[] = [$name, 'COMPILE', $err];
            continue;
        }
        $blade = (string) ($d['blade'] ?? '');
        $js = (string) ($d['js'] ?? '');

        // Compiler đã tự báo ⇒ không còn là lỗi IM LẶNG, đó là hành vi mong muốn.
        if (!empty($d['warnings'])) {
            $warned[] = [$name, substr((string) $d['warnings'][0], 0, 100)];
            continue;
        }

        $bt = matches('/<([a-zA-Z][\w-]*)[^>]*?@class\(\[\$__VIEW_ID__/', $blade);
        $jt = matches('/this\.html\(`[^`]+`,\s*"([\w-]+)"/', $js);
        if ($bt !== $jt) {
            $bad[] = [$name, 'TAG', 'blade=[' . implode(',', $bt) . '] js=[' . implode(',', $jt) . ']'];
            continue;
        }

        // Điểm mù đã từng bỏ sót @block: cả hai cùng rỗng thì so danh sách vẫn
        // "khớp". Thẻ nằm trong wrapper mà KHÔNG có hydrate id là dấu hiệu nội
        // dung bị bỏ quên.
        $body = str_contains($blade, '@wrapper')
            ? substr($blade, strpos($blade, '@wrapper') + 8)
            : $blade;
        $body = preg_replace('/\{\{--[\s\S]*?--\}\}|@verbatim\b[\s\S]*?@endverbatim\b/i', ' ', $body) ?? '';
        $body = preg_replace('/<(script|style|svg)\b[\s\S]*?<\/\1>/i', ' ', $body) ?? '';

        preg_match_all('/<([a-zA-Z][\w-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/', $body, $tags, PREG_SET_ORDER);
        $naked = [];
        foreach ($tags as [$_, $tag, $attrs]) {
            if (in_array(strtolower($tag), VOID_TAGS, true) || str_contains($attrs, '__VIEW_ID__')) {
                continue;
            }
            $naked[] = $tag;
        }
        if ($naked !== []) {
            $bad[] = [$name, 'NO-ID', 'thẻ trong blade không có hydrate id: [' . implode(',', $naked) . ']'];
            continue;
        }

        // id có {$loop->index} ở blade tương ứng ${__loopIndex} ở js → chuẩn hoá
        $norm = static fn (string $s): string => (string) preg_replace(
            ['/\$\{[^}]*\}/', '/\{\$[^}]*\}/'], 'X', $s,
        );
        $bo = array_map($norm, matches('/@startMarker\(\'output\',\s*["\']([\w\-{}$>.]+)["\']/', $blade));
        preg_match_all('/this\.output\(`([^`]+)`,\s*[^,]+,\s*(true|false),\s*\[([^\]]*)\]/', $js, $om, PREG_SET_ORDER);
        $jo = [];
        foreach ($om as [$_, $id, $flag, $keys]) {
            if ($flag === 'true' || trim($keys) !== '') {
                $jo[] = $norm($id);
            }
        }
        $bo = array_unique($bo);
        $jo = array_unique($jo);
        $onlyB = array_values(array_diff($bo, $jo));
        $onlyJ = array_values(array_diff($jo, $bo));
        if ($onlyB || $onlyJ) {
            $bad[] = [$name, 'OUTPUT', 'chỉ-blade=[' . implode(',', $onlyB) . '] chỉ-js=[' . implode(',', $onlyJ) . ']'];
        }
    }

    printf("Đã soát %d ca — %d lỗi im lặng, %d ca compiler đã cảnh báo\n\n",
        count($files), count($bad), count($warned));
    foreach ($warned as [$n, $m]) {
        echo "  [ĐÃ BÁO] {$n}\n        {$m}\n";
    }
    foreach ($bad as [$n, $k, $m]) {
        echo "  [{$k}] {$n}\n        {$m}\n";
    }

    return $bad === [] ? 0 : 1;
}

// ─────────────────────────── lệnh: leak ────────────────────────────
function cmdLeak(string $dir): int
{
    $cases = json_decode((string) file_get_contents("$dir/leak-cases.json"), true) ?: [];
    $wrap = "@states({ that: 1 })\n<template><p>{{ that }}</p></template>\n";
    $fails = 0;

    foreach (['comment' => '{{-- %s --}}', 'verbatim' => '@verbatim %s @endverbatim'] as $kind => $fmt) {
        foreach ($cases as $name => $snippet) {
            [$d, $err] = compileSource(sprintf($fmt, $snippet) . "\n" . $wrap);
            if ($err !== null) {
                echo "  ❌ [{$kind}] {$name}: COMPILE {$err}\n";
                $fails++;
                continue;
            }
            $js = (string) ($d['js'] ?? '');
            $blade = (string) ($d['blade'] ?? '');
            $outside = preg_replace('/\{\{--[\s\S]*?--\}\}|@verbatim[\s\S]*?@endverbatim/', ' ', $blade) ?? '';

            $bad = [];
            if (stripos($outside, 'gia') !== false) {
                $bad[] = 'blade';
            }
            // trong @verbatim, nội dung ra this.text(...) là ĐÚNG — chỉ báo khi thành mã
            if (preg_match('/gia/i', $js) && !preg_match("/this\.text\('[^']*gia/i", $js)) {
                $bad[] = 'js';
            }
            if (!empty($d['css'])) {
                $bad[] = 'css';
            }
            if (!empty($d['imports'])) {
                $bad[] = 'imports';
            }
            if (!str_contains($js, 'that')) {
                $bad[] = 'MẤT nội dung thật';
            }
            if ($bad !== []) {
                echo "  ❌ [{$kind}] {$name}: RÒ " . implode(', ', $bad) . "\n";
                $fails++;
            }
        }
    }

    printf("\nĐã soát %d ca — %d rò\n", count($cases) * 2, $fails);

    return $fails === 0 ? 0 : 1;
}

$cmd = $argv[1] ?? 'help';
exit(match ($cmd) {
    'cases' => cmdCases($DIR),
    'check' => cmdCheck($DIR),
    'leak' => cmdLeak($DIR),
    'clean' => (static function () use ($DIR): int {
        array_map('unlink', glob("$DIR/cases/*.sao") ?: []);
        @rmdir("$DIR/cases");
        echo "đã dọn cases/\n";

        return 0;
    })(),
    default => (static function (): int {
        echo "dùng: ./sweep.php cases|check|leak|clean\n";

        return 1;
    })(),
});
