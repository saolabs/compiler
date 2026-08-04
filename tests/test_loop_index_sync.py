#!/usr/bin/env python3
"""
Regression guard: hậu tố CHỈ SỐ của id marker trong loop phải khớp giữa
sao2blade (SSR) và sao2js (CSR) khi loop KHÔNG có @key.

`test_loop_output_marker_sync.py` đã guard trường hợp CÓ @key — chính khoảng
trống đó che lỗi này: sao2blade dùng `$loop->index` (Laravel, 0-based) còn
sao2js dùng `__loopIndex + 1` (1-based). SSR emit marker -0,-1,-2; CSR đi tìm
-1,-2,-3 → item đầu claim nhầm marker của item sau, item cuối không thấy nên
tạo mới ⇒ NHÂN ĐÔI DOM. Mọi @foreach không @key đều dính.

Bất biến: với cùng một loop, biểu thức hậu tố hai bên phải trỏ tới CÙNG một
gốc đếm (cả hai 0-based, hoặc cả hai 1-based) — không được lệch nhau.

Chạy:  python3 tests/test_loop_index_sync.py
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
        subprocess.run([sys.executable, cli, inp, out, 'Fn', 'test.fn'],
                       capture_output=True, text=True)
        with open(out) as f:
            return f.read()


def check(name: str, cond: bool, detail: str = ''):
    global passed, failed
    if cond:
        passed += 1
        print('  ok   ' + name)
    else:
        failed += 1
        print('  FAIL ' + name + ((' — ' + detail) if detail else ''))


NO_KEY = """@props({ todos: [] })
<blade>
  <ul>
    @foreach($todos as $todo)
      <li>{{ $todo['text'] }}</li>
    @endforeach
  </ul>
</blade>
"""

blade = _run(BLADE_CLI, NO_KEY, '.blade.php')
js = _run(JS_CLI, NO_KEY, '.js')

# Hậu tố index của id output trong loop, hai phía.
blade_suffix = re.findall(r"@startMarker\('output',\s*\"[0-9a-f]{8}-\{([^}]+)\}\"", blade)
js_suffix = re.findall(r"this\.output\(`[0-9a-f]{8}-\$\{([^}]+)\}`", js)

print('@foreach KHÔNG có @key')
check('blade emit hậu tố index', len(blade_suffix) == 1, str(blade_suffix))
check('js emit hậu tố index', len(js_suffix) == 1, str(js_suffix))

if blade_suffix and js_suffix:
    b, j = blade_suffix[0].strip(), js_suffix[0].strip()
    print(f'       blade={b!r}  js={j!r}')
    # Laravel: $loop->index 0-based, $loop->iteration 1-based.
    blade_base = 0 if 'index' in b else (1 if 'iteration' in b else None)
    # JS: __loopIndex 0-based; '+ 1' đẩy thành 1-based.
    js_base = 1 if re.search(r'\+\s*1', j) else 0
    check('CÙNG gốc đếm (không lệch 1)', blade_base == js_base,
          f'blade {blade_base}-based vs js {js_base}-based')
    check('js KHÔNG cộng thêm offset', '+' not in j, j)

# @key vẫn phải dùng chính biểu thức key ở cả hai phía (không hồi quy).
WITH_KEY = """@props({ todos: [] })
<blade>
  @foreach($todos as $todo)
    @key($todo['id'])
    <li>{{ $todo['text'] }}</li>
  @endforeach
</blade>
"""
blade_k = _run(BLADE_CLI, WITH_KEY, '.blade.php')
js_k = _run(JS_CLI, WITH_KEY, '.js')
print('@foreach CÓ @key — không hồi quy')
check('blade dùng biểu thức key', "todo['id']" in blade_k, '')
check('js dùng biểu thức key', "todo['id']" in js_k, '')
check('js KHÔNG dùng chỉ số khi có key', '__loopIndex' not in
      (re.findall(r"this\.output\(`[^`]+`", js_k) or [''])[0], '')

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
