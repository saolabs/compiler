#!/usr/bin/env python3
"""
Regression guard (F7, docs/FIX_PLAN_2026-08-14.md §F7): `@let(x = expr)`
KHÔNG reactive (khác `@computed`) — nếu `expr` đọc state, `x` đứng im vĩnh
viễn sau lần render đầu, không lỗi, không log, nhìn y hệt `@computed` cho
tới khi test tương tác thật mới lộ ra. Compiler phải CẢNH BÁO lúc compile.

Đo được (trước fix, chưa có cảnh báo nào):
    @states({ count: 0 })
    @let(double = count * 2)
    <template><div>{{ count }}</div><div>{{ double }}</div></template>
  → click tăng count: {{ count }} = 1 (đúng), {{ double }} = 0 (SAI, đứng im).

Bài test KHÔNG sửa hành vi @let (giữ nguyên — hợp lệ khi dùng cho hằng/giá
trị tính một lần), chỉ đảm bảo có cảnh báo compile-time khi RHS phụ thuộc
state, và KHÔNG cảnh báo nhầm khi RHS là hằng hoặc khi đã dùng @computed.

Chạy:  python3 tests/test_let_warning.py
"""
import os
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
            js = f.read()
        return js, r.stdout + r.stderr


def check(name, cond, detail=''):
    global passed, failed
    if cond:
        passed += 1
        print('  ok   ' + name)
    else:
        failed += 1
        print('  FAIL ' + name + ((' — ' + detail) if detail else ''))


print('@let phụ thuộc state → CÓ cảnh báo')
_, out = compile_sao(
    "@states({count:0})\n@let(double = count * 2)\n"
    "<template><div>{{ double }}</div></template>\n"
)
check('cảnh báo nhắc đúng tên biến + đề xuất @computed',
      'double' in out and '@computed' in out and 'count' in out,
      f'không thấy nội dung cảnh báo mong đợi: {out[:400]!r}')

print('@let hằng (không phụ thuộc state) → KHÔNG cảnh báo')
_, out = compile_sao(
    "@states({count:0})\n@let(label = 'Total')\n"
    "<template><div>{{ label }}</div></template>\n"
)
check('không có cảnh báo cho @let hằng', 'Cảnh báo' not in out, f'out={out[:400]!r}')

print('@computed phụ thuộc state → KHÔNG bị cảnh báo nhầm (khác @let)')
_, out = compile_sao(
    "@states({count:0})\n@computed(triple = count * 3)\n"
    "<template><div>{{ triple }}</div></template>\n"
)
check('không có cảnh báo cho @computed', 'Cảnh báo' not in out, f'out={out[:400]!r}')

print('@let([a, setA] = useState(...)) — destructuring, KHÔNG bị cảnh báo nhầm')
_, out = compile_sao(
    "@states({count:0})\n@let([a, setA] = useState(count))\n"
    "<template><div>{{ a }}</div></template>\n"
)
check('không có cảnh báo cho @let dùng useState (là state thật)',
      'Cảnh báo' not in out, f'out={out[:400]!r}')

print('nhiều @let: chỉ cái phụ thuộc state mới bị cảnh báo, cái hằng thì không')
_, out = compile_sao(
    "@states({count:0})\n"
    "@let(double = count * 2)\n"
    "@let(testVar = 'hằng')\n"
    "<template><div>{{ double }} {{ testVar }}</div></template>\n"
)
check('có cảnh báo cho double', 'double' in out and 'Cảnh báo' in out)
check('KHÔNG cảnh báo cho testVar (dòng cảnh báo không nhắc testVar)',
      'testVar' not in out.split('Cảnh báo')[1] if 'Cảnh báo' in out else False,
      f'out={out!r}')

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
