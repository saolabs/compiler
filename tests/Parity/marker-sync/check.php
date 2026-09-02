#!/usr/bin/env php
<?php
/**
 * Kiểm bất biến QUAN TRỌNG NHẤT: id trong .blade.php và .js phải KHỚP.
 *
 * Mọi cổng khác so output hiện tại với ảnh chụp golden — "compiler có đổi hành
 * vi không". Cổng này so BLADE với JS trong CÙNG một lần biên dịch: "SSR và CSR
 * có nói cùng một ngôn ngữ không". Hai câu hỏi hoàn toàn khác nhau, và câu thứ
 * hai golden không trả lời được: sửa sai đều hai phía thì golden vẫn xanh.
 *
 * Lệch id ⇒ hydrate không tìm thấy element ⇒ DOM nhân đôi. Đó là lớp lỗi mà cả
 * dự án này tồn tại để chống.
 *
 * stdin  : mỗi dòng một JSON {name, blade, js}
 * stdout : báo cáo; exit 1 nếu lệch
 */
declare(strict_types=1);

const BLADE_ELEMENT = '/\$__VIEW_ID__ \. \'-([A-Za-z0-9_]+)\'/';
const BLADE_MARKER = '/@(?:start|end)Marker\(\s*\'([a-z]+)\'\s*,\s*\'([A-Za-z0-9_]+)\'\s*\)/';
const JS_ELEMENT = '/this\.html\(`([A-Za-z0-9_]+)`/';
const JS_REACTIVE = '/this\.reactive\(`([A-Za-z0-9_]+)`/';

/**
 * Chỉ so output REACTIVE. sao2js phát this.output cho MỌI {{ }}, còn blade chỉ
 * đặt marker cho cái reactive — output tĩnh không bao giờ đổi nên không cần neo
 * SSR. Đó là thiết kế, không phải lệch.
 *
 * "Reactive" = cờ true HOẶC stateKeys không rỗng. Chỉ đọc cờ là sai: props sinh
 * ra `this.output(id, parent, false, ["source"], ...)` — cờ false nhưng vẫn phụ
 * thuộc state, và blade VẪN đặt marker cho nó.
 */
const JS_OUTPUT_ALL = '/this\.output\(`([A-Za-z0-9_]+)`\s*,\s*[^,]+,\s*(true|false)\s*,\s*\[([^\]]*)\]/';

/**
 * Lệch ĐÃ BIẾT, chưa sửa. Gate vẫn xanh nhưng in ra để không ai quên; ca MỚI
 * thì gate đỏ ngay.
 *
 * Đây KHÔNG phải allowlist để giấu lỗi — mỗi dòng là một lỗi thật, hẹp, có ghi
 * lý do. Danh sách này chỉ được co lại, không được nở ra.
 */
const KNOWN = [
    '05-nested-wrapper.sao' => '<template> lồng trong <template>: blade đi vào cấp trong (e21), sao2js thì không',
    '13-unclosed.sao' => 'thẻ bọc không đóng — input hỏng có chủ ý, hành vi không định nghĩa',
];

/** @return list<string> */
function matchAll(string $pattern, string $subject, int $group = 1): array
{
    preg_match_all($pattern, $subject, $m);

    return $m[$group] ?? [];
}

/** @return list<string> */
function jsReactiveOutputs(string $js): array
{
    preg_match_all(JS_OUTPUT_ALL, $js, $m, PREG_SET_ORDER);
    $ids = [];
    foreach ($m as [$_, $id, $flag, $keys]) {
        if ($flag === 'true' || trim($keys) !== '') {
            $ids[] = $id;
        }
    }

    return $ids;
}

/** @param list<string> $a @param list<string> $b @return list<string> */
function report(string $label, array $a, array $b): array
{
    $onlyA = array_values(array_unique(array_diff($a, $b)));
    $onlyB = array_values(array_unique(array_diff($b, $a)));
    sort($onlyA);
    sort($onlyB);

    $out = [];
    if ($onlyA) {
        $out[] = "{$label} chỉ có ở BLADE: [" . implode(', ', $onlyA) . ']';
    }
    if ($onlyB) {
        $out[] = "{$label} chỉ có ở JS: [" . implode(', ', $onlyB) . ']';
    }

    return $out;
}

/** @return list<string> */
function check(string $blade, string $js): array
{
    $bladeMarkers = static function (string $blade, string $kind): array {
        preg_match_all(BLADE_MARKER, $blade, $m, PREG_SET_ORDER);
        $ids = [];
        foreach ($m as [$_, $k, $id]) {
            if ($k === $kind) {
                $ids[] = $id;
            }
        }

        return $ids;
    };

    return array_merge(
        report('element', matchAll(BLADE_ELEMENT, $blade), matchAll(JS_ELEMENT, $js)),
        report('output marker', $bladeMarkers($blade, 'output'), jsReactiveOutputs($js)),
        report('reactive marker', $bladeMarkers($blade, 'reactive'), matchAll(JS_REACTIVE, $js)),
    );
}

$total = $bad = $knownHit = 0;

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $case = json_decode($line, true);
    if (!is_array($case)) {
        continue;
    }
    $total++;

    $problems = check((string) ($case['blade'] ?? ''), (string) ($case['js'] ?? ''));
    if ($problems === []) {
        continue;
    }

    $name = (string) ($case['name'] ?? '');
    $reason = null;
    foreach (KNOWN as $suffix => $why) {
        if (str_ends_with($name, $suffix)) {
            $reason = $why;
            break;
        }
    }
    if ($reason !== null) {
        $knownHit++;
        echo '  ⚠️  ĐÃ BIẾT ', basename($name), " — {$reason}\n";
        continue;
    }

    $bad++;
    if ($bad <= 30) {
        echo "\n  ❌ {$name}\n";
        foreach ($problems as $p) {
            echo "       {$p}\n";
        }
    }
}

echo "\nCorpus: {$total} view ({$knownHit} lệch đã biết)\n";
if ($bad > 0) {
    echo "❌ MARKER SYNC HỎNG: {$bad}/{$total} view lệch MỚI (ngoài danh sách đã biết)\n";
    exit(1);
}
printf("✅ MARKER SYNC: %d/%d view khớp, %d lệch đã biết\n", $total - $knownHit, $total, $knownHit);
