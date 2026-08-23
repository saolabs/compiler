#!/usr/bin/env python3
"""
Regression guard (F1, docs/FIX_PLAN_2026-08-14.md §F1): biểu thức bên trong
`@click(...)` phải giữ property access (`item.id`) — KHÔNG được biến `.`
thành `+` (nối chuỗi kiểu PHP).

Trước đây `EventDirectiveProcessor.convert_php_array_to_js_object()` chạy
MỌI param qua `common.php_converter.convert_php_to_js()`, đổi VÔ ĐIỀU KIỆN
mọi `.` giữa hai định danh thành `+`:
    @click(remove(item.id))  →  remove(item+id)   [SAI — item+id là NaN/lỗi]
Đường `{{ }}`/`@if` không dính vì dùng converter khác có guard (xem
`common/php_js_converter.py`). Bài test này SO SÁNH kết quả compile của
CÙNG một biểu thức khi đặt trong `{{ }}` (echo, đã đúng từ trước) và trong
`@click(f(...))` (event) — hai bên phải cho RA CÙNG chuỗi con biểu thức.

Kèm test không-hồi-quy cho `setCount(count + 1)` — state setter gọi trần
trong event, PHẢI giữ nguyên (không bị `App.Helper.` prefix, không bị vỡ).

Chạy:  python3 tests/test_event_expression.py
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


def compile_sao(source):
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, 'in.sao')
        out = os.path.join(d, 'out.js')
        with open(inp, 'w') as f:
            f.write(source)
        r = subprocess.run([sys.executable, CLI, inp, out, 'Fn', 'test.fn'],
                            capture_output=True, text=True)
        if not os.path.exists(out):
            raise RuntimeError('compile failed:\n' + r.stdout + r.stderr)
        with open(out) as f:
            return f.read()


def check(name, cond, detail=''):
    global passed, failed
    if cond:
        passed += 1
        print('  ok   ' + name)
    else:
        failed += 1
        print('  FAIL ' + name + ((' — ' + detail) if detail else ''))


def echo_body(js, marker='ECHO_MARK'):
    """Trích factory của this.output(...) đầu tiên có marker id `marker`."""
    m = re.search(r"this\.output\(`" + re.escape(marker) + r"`[^)]*\) => ([^\n]*?)\)\)", js)
    return m.group(1).strip() if m else None


def click_param(js):
    """Trích tham số ĐẦU TIÊN trong params:[...] của handler `probe`."""
    m = re.search(r'"handler":"probe","params":\[([^\]]*)\]', js)
    return m.group(1).strip() if m else None


def click_arrow(js):
    """Trích toàn bộ arrow function trong events:{click:[...]}."" khi KHÔNG có handler object."""
    m = re.search(r'events:\s*\{\s*click:\s*\[([^\]]*)\]\s*\}', js)
    return m.group(1).strip() if m else None


# ── Bảng đối chiếu: property access phải giữ nguyên ở CẢ HAI đường ─────────
CASES = [
    ('items.length',     'items.length'),
    ('user.name',        'user.name'),
    ('it.id',            'it.id'),
    ('user.profile.city', 'user.profile.city'),
]

print('property access: echo vs event params phải khớp')
for expr, expected in CASES:
    js = compile_sao(
        "@states({user:{name:'a',profile:{city:'x'}}, items:[], it:null})\n"
        "<template><div>\n"
        "  <span>{{ " + expr + " }}</span>\n"
        "  <button @click(probe(" + expr + "))>x</button>\n"
        "</div></template>\n"
    )
    param = click_param(js)
    check(f'@click(probe({expr})) giữ nguyên property access',
          param is not None and expected in param,
          f'got param={param!r}')

# ── setCount(...) trần trong event: KHÔNG được prefix App.Helper. ──────────
print('state setter gọi trần trong @click: không bị App.Helper. hoặc bọc lại')
js = compile_sao(
    "@states({count:0})\n"
    "<template><div><button @click(setCount(count + 1))>+</button></div></template>\n"
)
arrow = click_arrow(js)
check('setCount(count + 1) giữ nguyên, không App.Helper.',
      arrow is not None and 'App.Helper' not in arrow and 'setCount(count + 1)' in arrow,
      f'got={arrow!r}')

# ── @click(remove(item.id)) trong @foreach — ca thật (regression gốc) ──────
print('@foreach + @click(f(item.id)) — ca gốc của bug')
js = compile_sao(
    "@states({items:[]})\n"
    "<template><div>\n"
    "@foreach(items as item)\n"
    "  <button @click(remove(item.id))>x</button>\n"
    "@endforeach\n"
    "</div></template>\n"
)
check('remove(item.id) KHÔNG bị biến thành remove(item+id)',
      'item+id' not in js and 'item.id' in js,
      'tìm thấy item+id trong output' if 'item+id' in js else 'không tìm thấy item.id')

# ── PHP array literal @attr/@class vẫn phải hoạt động (không phá nhánh cũ) ──
print('PHP array literal (@class/@attr) vẫn convert đúng — không bị nhánh mới nuốt')
js = compile_sao(
    "@states({active:true})\n"
    "<template><div @class(['btn', 'active': active])>x</div></template>\n"
)
check('@class([...]) vẫn sinh classes config',
      'classes:' in js or 'classes :' in js,
      'không thấy classes: trong output')

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
