#!/usr/bin/env python3
"""
Regression guard (F3, docs/FIX_PLAN_2026-08-14.md §F3): `{{ methodCủaComponent() }}`
phải resolve qua `this.view.methodCủaComponent()`, KHÔNG rơi vào
`App.Helper.methodCủaComponent()` (không tồn tại → TypeError lúc mount, giết
cả view).

Trước fix: `_add_function_prefixes` (common/php_js_converter.py) prefix MỌI
lời gọi hàm trần không nằm trong danh sách PHP-helper cố định bằng
`App.Helper.` — kể cả method định nghĩa trong `<script setup>`, vì compiler
không hề biết tên các method đó khi compile phần `{{ }}`. Event handler
(`@click(label())`) thì ĐÚNG từ trước (resolve theo tên qua
`{"handler":"label",...}` — runtime tra `view[name]`), tạo bất đối xứng khó
phát hiện: cùng method, đúng ở event, sai ở echo.

Bài test này CŨNG guard state-leak: converter dùng chung là SINGLETON
module-level, sống qua nhiều lần compile trong CÙNG 1 tiến trình Python —
method của view A KHÔNG được rò sang view B compile ngay sau đó.

Chạy:  python3 tests/test_method_resolution.py
"""
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CLI = os.path.join(ROOT, 'src', 'sao2js', 'cli.py')

passed = 0
failed = 0


def compile_sao(source, name='in'):
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, name + '.sao')
        out = os.path.join(d, name + '.js')
        with open(inp, 'w') as f:
            f.write(source)
        r = subprocess.run([sys.executable, CLI, inp, out, 'Fn', 'test.fn'],
                            capture_output=True, text=True)
        if not os.path.exists(out):
            raise RuntimeError('compile failed:\n' + r.stdout + r.stderr)
        with open(out) as f:
            return f.read(), r.stdout + r.stderr


def check(name, cond, detail=''):
    global passed, failed
    if cond:
        passed += 1
        print('  ok   ' + name)
    else:
        failed += 1
        print('  FAIL ' + name + ((' — ' + detail) if detail else ''))


# ── Method shorthand: `label() { ... }` ─────────────────────────────────────
print('method shorthand trong <script setup>')
js, _ = compile_sao(
    "@states({n:0})\n"
    "<template><div>{{ label() }}</div></template>\n"
    "<script setup>\n"
    "export default {\n"
    "  label() { return 'n=' + n; }\n"
    "}\n"
    "</script>\n"
)
check('label() → this.view.label(), KHÔNG App.Helper.label',
      'this.view.label()' in js and 'App.Helper.label' not in js,
      'không thấy this.view.label() hoặc còn App.Helper.label trong output')

# ── Property dạng arrow function ────────────────────────────────────────────
print('method dạng property arrow function')
js, _ = compile_sao(
    "@states({n:0})\n"
    "<template><div>{{ label() }}</div></template>\n"
    "<script setup>\n"
    "export default {\n"
    "  label: () => 'n=' + n\n"
    "}\n"
    "</script>\n"
)
check('label: () => ... → this.view.label()',
      'this.view.label()' in js and 'App.Helper.label' not in js)

# ── Helper PHP thật vẫn phải prefix App.Helper — không bị nhánh mới nuốt ────
print('PHP helper thật (count) vẫn App.Helper — không lẫn với method component')
js, _ = compile_sao(
    "@states({items:[1,2,3]})\n"
    "<template><div>{{ count(items) }}</div></template>\n"
    "<script setup>\n"
    "export default { label() { return 'x'; } }\n"
    "</script>\n"
)
check('count(items) vẫn là App.Helper.count(items)',
      'App.Helper.count(items)' in js)

# ── Event path (@click) không bị ảnh hưởng — vẫn resolve theo tên như cũ ────
print('@click(label()) vẫn dùng handler-object dispatch như cũ (không đổi)')
js, _ = compile_sao(
    "@states({n:0})\n"
    "<template><div><button @click(label())>x</button></div></template>\n"
    "<script setup>\n"
    "export default { label() { return 'x'; } }\n"
    "</script>\n"
)
check('@click(label()) → handler object, không phải this.view.label()',
      '"handler":"label"' in js)

# ── Tên lạ (không phải method, không phải helper đã biết) → vẫn App.Helper +
#    cảnh báo compile-time (KHÔNG im lặng) ─────────────────────────────────
print('tên lạ: vẫn App.Helper (fallback cũ) NHƯNG có cảnh báo compile-time')
js, stderr_out = compile_sao(
    "@states({n:0})\n"
    "<template><div>{{ totallyUnknownFn() }}</div></template>\n"
)
check('totallyUnknownFn() vẫn App.Helper.totallyUnknownFn (không đổi hành vi cũ)',
      'App.Helper.totallyUnknownFn' in js)
check('có cảnh báo compile-time cho tên lạ',
      'totallyUnknownFn' in stderr_out and 'sao2js' in stderr_out,
      f'stdout/stderr không thấy cảnh báo: {stderr_out[:300]!r}')

# ── State-leak: method của view A KHÔNG được rò sang view B compile SAU ─────
# (2 lần gọi CLI riêng biệt = 2 tiến trình Python riêng biệt — không lộ được
# leak qua reused-process. Test bằng cách gọi compiler API trực tiếp, TRONG
# CÙNG 1 tiến trình, mô phỏng đúng cách singleton có thể bị tái sử dụng.)
print('không rò tên method giữa 2 lần compile trong CÙNG 1 tiến trình')
sys.path.insert(0, os.path.join(ROOT, 'src'))
sys.path.insert(0, os.path.join(ROOT, 'src', 'sao2js'))
from main_compiler import BladeCompiler  # noqa: E402

compiler = BladeCompiler()
js_a = compiler.compile_blade_to_js(
    "@states({n:0})\n<template><div>{{ onlyInA() }}</div></template>\n"
    "<script setup>\nexport default { onlyInA() { return 'a'; } }\n</script>\n",
    'test.a', 'A', 'A',
)
js_b = compiler.compile_blade_to_js(
    "@states({n:0})\n<template><div>{{ onlyInA() }}</div></template>\n",
    'test.b', 'B', 'B',
)
check('view B (không có script setup) KHÔNG kế thừa onlyInA của view A',
      'App.Helper.onlyInA' in js_b and 'this.view.onlyInA' not in js_b,
      'view B vẫn resolve onlyInA như method — state đã bị rò từ view A')

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
