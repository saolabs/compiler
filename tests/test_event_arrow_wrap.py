#!/usr/bin/env python3
"""
Regression guard (F2, docs/FIX_PLAN_2026-08-14.md §F2): một arrow function
người dùng VIẾT TAY trong `@click(() => f(x))` KHÔNG được compiler bọc thêm
một lớp arrow nữa.

Trước fix: `_process_expression_to_arrow` LUÔN wrap `(event) => ...`/
`() => ...` vô điều kiện ở nhánh cuối, kể cả khi biểu thức ĐÃ là arrow
function → `() => () => f(x)`. Click chỉ TẠO RA một function (không gọi),
im lặng không làm gì — không throw, không log.

Phân biệt với ca KHÔNG được coi là top-level arrow: `remove(x => x.id)` —
`=>` ở đây nằm TRONG params của `remove(...)`, bản thân biểu thức là một
function call, không phải arrow. Case này PHẢI vẫn đi qua nhánh object-handler
dispatch bình thường (không liên quan tới double-wrap).

Chạy:  python3 tests/test_event_arrow_wrap.py
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


def click_events(js):
    m = re.search(r'events:\s*\{\s*click:\s*\[(.*?)\]\s*\}', js, re.DOTALL)
    return m.group(1).strip() if m else None


print('arrow function viết tay trong @click KHÔNG bị bọc thêm')

js = compile_sao(
    "@states({picked:''})\n"
    "<template><div><button @click(() => setPicked('x'))>p</button></div></template>\n"
)
ev = click_events(js)
check('() => setPicked(...) — đúng 1 lớp arrow',
      ev is not None and ev.count('=>') == 1 and 'setPicked' in ev,
      f'got={ev!r}')

js = compile_sao(
    "@states({picked:0})\n"
    "<template><div><button @click((e) => setPicked(e.target))>p</button></div></template>\n"
)
ev = click_events(js)
check('(e) => setPicked(e.target) — đúng 1 lớp arrow, giữ tham số e',
      ev is not None and ev.count('=>') == 1 and 'setPicked' in ev,
      f'got={ev!r}')

# ── Đối chứng: `=>` LỒNG trong params của một function call KHÔNG được coi
#    là top-level arrow — vẫn phải là handler-object dispatch bình thường.
js = compile_sao(
    "@states({items:[]})\n"
    "<template><div>\n"
    "@foreach(items as item)\n"
    "  <button @click(remove(item.id))>x</button>\n"
    "@endforeach\n"
    "</div></template>\n"
)
check('remove(item.id) (không có arrow) vẫn ra handler object bình thường',
      '"handler":"remove"' in js,
      'không thấy handler object cho remove')

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
