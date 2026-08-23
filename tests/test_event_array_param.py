#!/usr/bin/env python3
"""
Regression guard: PHP array literal làm THAM SỐ của event handler.

Hai lỗi riêng biệt, cùng lộ ra ở `@click(send(['a' => 1]))`:

1. **Nhận diện array bằng so chuỗi** (regression của F1, đã vá):
   nhánh PHP-array từng được chọn bằng `' => ' in expr` — phụ thuộc KHOẢNG
   TRẮNG. Hệ quả:
     - `['a'=>1]` (không khoảng trắng) → BỎ SÓT, giữ nguyên cú pháp PHP
       trong output JS → file không parse được.
     - `[1,2].filter(x => x > 1)` (JS thật) → NHẬN NHẦM là PHP array, bị
       `convert_php_to_js` đổi `.` thành `+` → `[1,2]+filter(...)`.
   Nay dùng `_is_php_array_literal()` — kiểm CẤU TRÚC (khép kín `[...]` +
   `=>` ở độ sâu 0), không phụ thuộc khoảng trắng.

2. **Arrow trả object literal thiếu ngoặc** (lỗi CÓ TỪ TRƯỚC, đã vá):
   `(event) => {"a": 1}` không phải arrow trả object — JS đọc `{` là BLOCK
   và `"a": 1` trong block là SyntaxError ⇒ CẢ FILE compiled không parse
   được. Phải là `(event) => ({"a": 1})`. Đã đối chiếu bản gốc: lỗi tồn tại
   trước mọi thay đổi của phiên này, chỉ ít chạm tới vì nhiều ca khác hỏng
   sớm hơn ở bước convert.

Test này KIỂM CÚ PHÁP THẬT bằng `node --check` (không chỉ so chuỗi) — đó là
cách duy nhất bắt được lớp lỗi #2.

Chạy:  python3 tests/test_event_array_param.py
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CLI = os.path.join(ROOT, 'src', 'sao2js', 'cli.py')

passed = 0
failed = 0


def compile_sao(source):
    d = tempfile.mkdtemp()
    inp = os.path.join(d, 'in.sao')
    out = os.path.join(d, 'out.mjs')
    with open(inp, 'w') as f:
        f.write(source)
    r = subprocess.run([sys.executable, CLI, inp, out, 'Fn', 'test.fn'],
                        capture_output=True, text=True)
    if not os.path.exists(out):
        raise RuntimeError('compile failed:\n' + r.stdout + r.stderr)
    with open(out) as f:
        js = f.read()
    return js, out, d


def check(name, cond, detail=''):
    global passed, failed
    if cond:
        passed += 1
        print('  ok   ' + name)
    else:
        failed += 1
        print('  FAIL ' + name + ((' — ' + detail) if detail else ''))


def node_syntax_ok(path):
    """True nếu `node --check` parse được file (None nếu máy không có node)."""
    if shutil.which('node') is None:
        return None
    r = subprocess.run(['node', '--check', path], capture_output=True, text=True)
    return (r.returncode == 0, r.stderr.strip())


def click_line(js):
    m = re.search(r'events:\s*\{\s*click:\s*\[(.*?)\]\s*\}', js, re.DOTALL)
    return m.group(1).strip() if m else None


print('PHP array làm tham số event — mọi dạng khoảng trắng quanh =>')
for label, arr in [('có khoảng trắng', "['a' => 1]"),
                   ('không khoảng trắng', "['a'=>1]"),
                   ('lệch trái', "['a' =>1]"),
                   ('lệch phải', "['a'=> 1]"),
                   ('lồng nhau', "['a'=>['b'=>2]]")]:
    js, path, d = compile_sao(
        "@states({n:0})\n"
        "<template><div><button @click(send(" + arr + "))>x</button></div></template>\n"
    )
    ev = click_line(js)
    check(f'{label}: convert sang object JS, không còn cú pháp PHP `=>`',
          ev is not None and '=>' not in ev.replace('=> (', '').replace('=>(', ''),
          f'got={ev!r}')
    syn = node_syntax_ok(path)
    if syn is None:
        print('  skip node --check (không có node trên PATH)')
    else:
        ok, err = syn
        check(f'{label}: file compiled PARSE ĐƯỢC (node --check)', ok, err.split(chr(10))[0] if err else '')
    shutil.rmtree(d, ignore_errors=True)

print('JS thật `[...].method(x => ...)` KHÔNG bị nhận nhầm là PHP array')
js, path, d = compile_sao(
    "@states({n:0})\n"
    "<template><div><button @click(send([1,2].filter(x => x > 1)))>x</button></div></template>\n"
)
ev = click_line(js)
check('giữ nguyên `[1,2].filter(...)`, KHÔNG thành `[1,2]+filter(...)`',
      ev is not None and '[1,2].filter' in ev and '+filter' not in ev,
      f'got={ev!r}')
syn = node_syntax_ok(path)
if syn is not None:
    ok, err = syn
    check('file compiled PARSE ĐƯỢC', ok, err.split(chr(10))[0] if err else '')
shutil.rmtree(d, ignore_errors=True)

print('giá trị trong array giữ property access (không hồi quy F1)')
js, path, d = compile_sao(
    "@states({item:{href:'x'}})\n"
    "<template><div><button @click(send(['url' => item.href]))>x</button></div></template>\n"
)
ev = click_line(js)
check('item.href giữ nguyên, không thành item+href',
      ev is not None and 'item.href' in ev and 'item+href' not in ev,
      f'got={ev!r}')
shutil.rmtree(d, ignore_errors=True)

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
