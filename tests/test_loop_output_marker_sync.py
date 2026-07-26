#!/usr/bin/env python3
"""
Regression guard (standalone, không cần pytest) cho ĐỒNG BỘ id output {{ }} bên
trong @foreach giữa Blade (SSR) và JS (CSR).

Bối cảnh lỗi: sao2js LUÔN compile một {{ }} / {!! !!} nằm trong @foreach thành
một this.output("HASH-{key}", ...) (element marker-based, cần cặp comment
<!--o:{viewId}-HASH-{key}-s/e-->). Trước đây hydrate_processor chỉ emit
@startMarker('output', ...) khi biểu thức tham chiếu STATE KEY, nên output chỉ
dùng biến vòng lặp ($todo['text']) KHÔNG có marker ở SSR. Khi hydrate,
claimSSRMarkers() không thấy marker → tạo marker mới + append text lần nữa cạnh
text server → NHÂN ĐÔI nội dung ("texttext", id 1 → 11).

Bất biến: mỗi {{ }} trong loop sinh một hydrate output id tất định (md5[:8]) với
hậu tố loop động. Blade emit @startMarker('output', "HASH-{$var}") và JS emit
this.output(`HASH-${var}`). Hai danh sách HASH — theo đúng thứ tự — PHẢI trùng.

Chạy:  python3 tests/test_loop_output_marker_sync.py
"""
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
JS_CLI = os.path.join(ROOT, 'src', 'sao2js', 'cli.py')
BLADE_CLI = os.path.join(ROOT, 'src', 'sao2blade', 'cli.py')

passed = 0
failed = 0


def _run(cli: str, source: str, suffix: str) -> str:
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, 'in.sao')
        out = os.path.join(d, 'out' + suffix)
        with open(inp, 'w') as f:
            f.write(source)
        r = subprocess.run([sys.executable, cli, inp, out, 'Fn', 'test.fn'],
                           capture_output=True, text=True)
        if not os.path.exists(out):
            raise RuntimeError(f'compile failed ({cli}):\n{r.stdout}\n{r.stderr}')
        with open(out) as f:
            return f.read()


def compile_js(source: str) -> str:
    return _run(JS_CLI, source, '.js')


def compile_blade(source: str) -> str:
    return _run(BLADE_CLI, source, '.blade.php')


def check(name: str, cond: bool, detail: str = ''):
    global passed, failed
    if cond:
        print(f'  ✅ {name}')
        passed += 1
    else:
        print(f'  ❌ {name}  {detail}')
        failed += 1


# ── Fixture: @foreach với output dùng biến loop (KHÔNG phải state key) ────────
# `todos` là state; `todo['text']`/`todo['id']` là biến loop. Đây chính là cấu
# hình từng lộ bug: sao2js emit this.output() cho chúng, còn hydrate_processor
# (trước fix) bỏ marker vì biểu thức không tham chiếu state key. @key(todo['id'])
# để hậu tố loop hai phía dùng cùng khoá → id đầy đủ trùng khít.
SOURCE = (
    "@state(todos = [])\n"
    "<template>\n"
    "    @foreach(todos as todo)\n"
    "        @key(todo['id'])\n"
    "        <li><strong>{{ todo['text'] }}</strong><span>{{ todo['id'] }}</span></li>\n"
    "    @endforeach\n"
    "</template>\n"
)


def _canon(i: str) -> str:
    # Blade PHP interpolation "{$todo['id']}" ↔ JS template "${todo['id']}".
    # Drop the `$` sigils so the two id forms compare equal on hash + key path.
    return i.replace('$', '')


def blade_loop_output_ids(blade: str):
    """Full ids of loop outputs: @startMarker('output', "HASH-{...}")."""
    ids = re.findall(r"@startMarker\(\s*'output'\s*,\s*\"([0-9a-f]{8}-[^\"]+)\"", blade)
    return [_canon(i) for i in ids]


def js_loop_output_ids(js: str):
    """Full ids of loop outputs: this.output(`HASH-${...}`)."""
    ids = re.findall(r"this\.output\(\s*`([0-9a-f]{8}-[^`]+)`", js)
    return [_canon(i) for i in ids]


print('Test: {{ }}-in-@foreach output marker id sync (blade ↔ js)')

js = compile_js(SOURCE)
blade = compile_blade(SOURCE)

blade_ids = blade_loop_output_ids(blade)
js_ids = js_loop_output_ids(js)

# The two loop outputs must produce markers on BOTH sides. Regression: before the
# fix, blade emitted ZERO markers for loop-variable interpolations.
check('blade emits output markers inside loop', len(blade_ids) == 2,
      f'got {blade_ids!r} — loop {{ }} lost its @startMarker(output)')
check('js emits output ids inside loop', len(js_ids) == 2,
      f'got {js_ids!r}')
check('same NUMBER of loop output ids', len(blade_ids) == len(js_ids),
      f'blade={len(blade_ids)} {blade_ids!r} vs js={len(js_ids)} {js_ids!r}')
check('loop output ids MATCH in order (SSR/CSR sync)', blade_ids == js_ids,
      f'\n      blade={blade_ids!r}\n      js   ={js_ids!r}')

print(f'\n{passed} passed, {failed} failed')
sys.exit(1 if failed else 0)
