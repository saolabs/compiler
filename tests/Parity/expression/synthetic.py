#!/usr/bin/env python3
"""Biểu thức tổng hợp nhắm vào các NHÁNH BIẾN ĐỔI.

Corpus thật (587 biểu thức từ 56 view) có 87% đi qua converter mà không đổi —
tốt cho việc chứng minh không hồi quy, nhưng yếu ở chỗ chứng minh các nhánh
thật sự làm việc là đúng. File này ép vào đúng những nhánh đó.

Viết tay có chủ ý: mỗi dòng nhắm một nhánh cụ thể trong bản Python.
"""
import json
import sys

# ── mảng PHP → object/mảng JS ─────────────────────────────────────────
ARRAYS = [
    "['a' => 1]",
    "['a' => 1, 'b' => 2]",
    "['a' => 1, 2, 'c' => 3]",                       # mixed
    "[1, 2, 3]",
    "[]",
    "['x' => ['y' => 1]]",                           # object lồng object
    "['x' => [1, 2]]",                               # object chứa mảng
    "[['a' => 1], ['a' => 2]]",                      # mảng các object
    "['a' => 'chuỗi tiếng Việt']",
    "['a' => true, 'b' => FALSE, 'c' => Null]",      # hoa/thường
    "['a' => 1.5, 'b' => 100]",
    "['a' => $x->y, 'b' => count($z)]",
    "['a' => x ?? null]",
    "$items['key']",                                 # truy cập, không phải literal
    "$items[0]",
    "$items['a']['b']",
    "['k' => \"nháy kép\"]",
    "['a' => 1, 'b' => ['c' => 2, 'd' => [3, 4]]]",
]

# ── nối chuỗi PHP ─────────────────────────────────────────────────────
CONCAT = [
    "$a . $b",
    "$a . ' ' . $b",
    "'x' . $y",
    "$count * 10 . '%'",
    "$count + 1 . 'px'",
    '"xin chào $name"',
    "'giữ nguyên $name'",
    "$a . 1.5 . $b",
    "$obj->prop . '!'",
]

# ── truy cập property / static ────────────────────────────────────────
ACCESS = [
    "$post->title",
    "$a->b->c->d",
    "$user->profile->email",
    "User::find(1)",
    "$x->method()->chain()",
]

# ── hàm & helper ──────────────────────────────────────────────────────
FUNCS = [
    "count($items)",
    "count(items)",
    "route('home')",
    "asset('a.png')",
    "App.Helper.count(x)",          # đã có tiền tố — không được gắn thêm
    "obj.count(x)",                 # property — không được gắn
    "Math.max(a, b)",               # builtin
    "parseInt(x)",
    "json_encode(event('view.rendered'))",
    "unknownFn(x)",
    "count(min(max(a, b), c))",
    "yield('name')",
    "foreach($items, fn)",
]

# ── toán tử ───────────────────────────────────────────────────────────
OPS = [
    "$i++",
    "$i--",
    "++$i",
    "$a++ + $b--",
    "$x ?? 'mặc định'",
    "$a === $b ? 'x' : 'y'",
    "!$flag",
    "(array) $x",
    "$a <= $b",
    "$a !== null && $b > 0",
]

# ── định danh loop ────────────────────────────────────────────────────
LOOPS = [
    "loop.index",
    "$loop->index",
    "__loop.index",
    "x.loop",
    "loopFoo",
    "myloop",
    "'loop'",
    '"loop"',
    "`loop`",
    "loop.index + 1",
    "remove(loop.index)",
]

# ── câu lệnh (chỉ đi qua php_to_js) ───────────────────────────────────
STATEMENTS = [
    "foreach ($items as $item) {",
    "foreach ($items as $key => $value) {",
    "foreach (items as item) {",
    "function () use ($a, $b) { return $a; }",
    "foreach ($x->items as $i) {",
]

# ── ca biên / bug đã biết ─────────────────────────────────────────────
EDGE = [
    "",
    "   ",
    "'ready'",
    '"@if(status === \'ready\')"',   # bug __STR_LIT_ đã biết
    '"@bind(name)"',                 # prefix helper áp vào chuỗi hiển thị
    "1.5",
    "0",
    "'0'",
    "false",
    "$a.b",
    "a.b.c.d",
    "'chuỗi có . dấu chấm'",
    '"đường/dẫn/tệp.php"',
]


def main() -> int:
    both = ARRAYS + CONCAT + ACCESS + FUNCS + OPS + LOOPS + EDGE

    for expr in both:
        for func in ('php_to_js', 'php_to_js_advanced'):
            print(f'{func}\t{json.dumps(expr, ensure_ascii=False)}')

    # Câu lệnh chỉ có nghĩa với php_to_js (php_to_js_advanced không xử lý foreach)
    for expr in STATEMENTS:
        print(f'php_to_js\t{json.dumps(expr, ensure_ascii=False)}')

    print(f'  tổng hợp: {len(both) * 2 + len(STATEMENTS)} dòng', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main())
