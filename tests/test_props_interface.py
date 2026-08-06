#!/usr/bin/env python3
"""
Regression test (standalone) cho interface props sinh ở TS mode (§2.13).

Trước đây `__data__` là `any` nên một view `.sao` KHÔNG có contract compile-time
nào về thứ cha truyền vào — `{{ count.toUpperCase() }}` với `count = 0` không hề
bị tsc bắt.

Contract:
  - TS (`<script setup lang="ts">`) → emit `export interface {Name}Props`,
    `constructor(__data__: {Name}Props ...)` và factory cùng kiểu.
  - JS → KHÔNG emit gì, chữ ký giữ nguyên `__data__ = {}`, không sót placeholder.
  - Kiểu suy TỪ LITERAL của default; biểu thức → `any` (đoán sai làm tsc báo lỗi
    ở code đúng, tệ hơn không đoán).
  - Index signature BẮT BUỘC: data thật luôn mang thêm key ngoài khai báo
    (route params, systemData, __SSR_VIEW_ID__).

Test này từng bị xoá khỏi đĩa cùng lúc `src/templates/view.js` bị thay bằng
stub (xem §2.22) — giữ nó là cách duy nhất phát hiện template mất placeholder.

Chạy:  python3 tests/test_props_interface.py
"""
import os
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CLI = os.path.join(ROOT, 'src', 'sao2js', 'cli.py')

passed = 0
failed = 0

PROPS = """@props({
    users: [],
    count: 0,
    title: 'hello',
    active: true,
    meta: {},
    other: null,
    fromCall: makeThing(),
})
<blade><div>{{ $title }}</div></blade>
"""

TS_HEADER = '<script setup lang="ts"></script>\n'


def compile_sao(source: str, ext: str = 'ts') -> str:
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, 'in.sao')
        out = os.path.join(d, 'out.' + ext)
        with open(inp, 'w') as f:
            f.write(source)
        subprocess.run([sys.executable, CLI, inp, out, 'Demo', 'test.demo'],
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


print('TS mode')
ts = compile_sao(TS_HEADER + PROPS, 'ts')
check('emit interface DemoProps', 'export interface DemoProps {' in ts)
check('constructor dùng DemoProps', 'constructor(__data__: DemoProps = {}' in ts)
check('factory dùng DemoProps', 'export function Demo(__data__: DemoProps = {}' in ts)

for field, expected in [
    ('users', 'any[]'),
    ('count', 'number'),
    ('title', 'string'),
    ('active', 'boolean'),
    ('meta', 'Record<string, any>'),
    ('other', 'any'),
    ('fromCall', 'any'),   # biểu thức → không đoán
]:
    line = '    {}?: {};'.format(field, expected)
    check('suy kiểu {} → {}'.format(field, expected), line in ts, line)

check('có index signature', '[key: string]: any;' in ts)
check('có __SSR_VIEW_ID__', '__SSR_VIEW_ID__?: string;' in ts)

print('JS mode')
js = compile_sao(PROPS, 'js')
check('KHÔNG emit interface', 'export interface' not in js)
check('KHÔNG sót placeholder', 'COMPONENT_PROPS_INTERFACE' not in js)
check('KHÔNG rò tên kiểu', 'DemoProps' not in js)
check('constructor giữ nguyên', 'constructor(__data__ = {}, systemData = {})' in js)
check('factory giữ nguyên', 'export function Demo(__data__ = {}, systemData = {})' in js)

print('View không có @props/@vars')
bare = compile_sao(TS_HEADER + '<blade><div>x</div></blade>\n', 'ts')
check('vẫn emit interface (constructor tham chiếu tới nó)',
      'export interface DemoProps {' in bare)
check('vẫn dùng được', 'constructor(__data__: DemoProps = {}' in bare)

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
