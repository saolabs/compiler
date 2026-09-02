#!/usr/bin/env php
<?php
/**
 * Sinh dãy thao tác cho HydrateIdGenerator.
 *
 * Mỗi dòng là một JSON `{"op": ..., "args": [...]}`. Subject chạy đúng dãy này
 * rồi in giá trị trả về của từng thao tác; diff với ảnh chụp golden phải rỗng.
 *
 * Dãy sinh máy tốt hơn assert viết tay ở chỗ nó chạm tới các tổ hợp push/pop
 * lồng nhau mà người viết test khó nghĩ ra — mà đó lại chính là nơi bộ đếm theo
 * scope dễ lệch.
 *
 * TẤT ĐỊNH theo seed bằng một LCG viết tay, KHÔNG dùng mt_rand: thuật toán của
 * mt_rand đổi theo phiên bản PHP (MT_RAND_PHP đã deprecated ở 8.4), mà golden
 * thì phải tái lập được sau nhiều năm. 12 dòng số học rẻ hơn một ảnh chụp chết.
 */
declare(strict_types=1);

const TAGS = ['div', 'span', 'p', 'ul', 'li', 'a', 'h1', 'code', 'button', 'img'];
const REACTIVE = ['if', 'switch', 'foreach', 'for', 'while'];
const BLOCKS = ['content', 'main', 'side_bar', 'workspace'];

$count = (int) ($argv[1] ?? 5000);
$seed = (int) ($argv[2] ?? 20260830);

// LCG numerical-recipes: state = state * 1664525 + 1013904223 (mod 2^32)
$state = $seed & 0xFFFFFFFF;
$rand = static function () use (&$state): float {
    $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;

    return $state / 4294967296.0;
};
$pick = static function (array $xs) use ($rand) { return $xs[(int) ($rand() * count($xs))]; };

$depth = 1;   // scope gốc
$out = [];

for ($i = 0; $i < $count; $i++) {
    $roll = $rand();

    if ($roll < 0.16) {
        $out[] = ['args' => [$pick(TAGS)], 'op' => 'nextElement'];
    } elseif ($roll < 0.30) {
        $out[] = ['args' => [$pick(TAGS)], 'op' => 'pushElement'];
        $depth++;
    } elseif ($roll < 0.40) {
        $out[] = ['args' => [$pick(REACTIVE)], 'op' => 'pushReactive'];
        $depth++;
    } elseif ($roll < 0.47) {
        $out[] = ['args' => [1 + (int) ($rand() * 12)], 'op' => 'pushCase'];
        $depth++;
    } elseif ($roll < 0.53) {
        $blade = $pick(['$loop->index', '$i', null]);
        $out[] = ['args' => ['__loopIndex', $blade], 'op' => 'pushLoopIteration'];
        $depth++;
    } elseif ($roll < 0.60) {
        $out[] = ['args' => [], 'op' => 'nextOutput'];
    } elseif ($roll < 0.65) {
        $out[] = ['args' => [], 'op' => 'nextComponent'];
    } elseif ($roll < 0.69) {
        $out[] = ['args' => [], 'op' => 'pushComponent'];
        $depth++;
    } elseif ($roll < 0.72) {
        $out[] = ['args' => [], 'op' => 'nextBlockOutlet'];
    } elseif ($roll < 0.76) {
        $out[] = ['args' => [], 'op' => 'nextYield'];
    } elseif ($roll < 0.80) {
        $out[] = ['args' => [$pick(BLOCKS)], 'op' => 'pushBlock'];
        $depth++;
    } elseif ($roll < 0.84) {
        $out[] = ['args' => [], 'op' => 'depth'];
    } elseif ($roll < 0.91) {
        $out[] = ['args' => ['div-1-output-1'], 'op' => 'formatJsId'];
    } elseif ($roll < 0.94 && $depth > 8) {
        // Thỉnh thoảng đặt lại — kiểm tra reset() dọn sạch mọi bộ đếm.
        $out[] = ['args' => [], 'op' => 'reset'];
        $depth = 1;
    } else {
        // Cố tình pop cả khi đã ở gốc: phải là no-op trả null, không được ném.
        $out[] = ['args' => [], 'op' => 'popScope'];
        $depth = max(1, $depth - 1);
    }
}

foreach ($out as $op) {
    echo json_encode($op, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}
