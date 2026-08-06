#!/usr/bin/env python3
"""
Regression guard: closure var của `@states` phải được seed bằng GIÁ TRỊ KHỞI TẠO,
không phải `null`.

Luồng CSR là render() → mount() → commitView(), tức contentFactory của Output
(`() => items.length`) CHẠY TRƯỚC `commitConstructorData` — nơi `update$items(...)`
mới gán giá trị. Để `null` thì mọi `{{ state.thuộcTính }}` ném TypeError ngay lần
mount đầu và error boundary nuốt trọn trang.

Đường hydrate KHÔNG lộ lỗi này vì nó commit TRƯỚC render — nên nó chỉ xuất hiện
khi điều hướng client-side, và đó là lý do nó sống sót lâu.

Chạy:  python3 tests/test_state_seed.py
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


def compile_sao(source, ext='js'):
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, 'in.sao')
        out = os.path.join(d, 'out.' + ext)
        with open(inp, 'w') as f:
            f.write(source)
        subprocess.run([sys.executable, CLI, inp, out, 'Fn', 'test.fn'],
                       capture_output=True, text=True)
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


js = compile_sao("""@states({ items: [1, 2, 3], name: 'a', flag: true, n: 0 })
<template><div>{{ items.length }}</div></template>
""")

print('seed closure var')
for var, seed in [('items', '[1, 2, 3]'), ('name', "'a'"), ('flag', 'true'), ('n', '0')]:
    m = re.search(r'let %s(?::\s*any)?\s*=\s*(.+?);' % var, js)
    got = m.group(1) if m else None
    check('let %s = %s' % (var, seed), got == seed, 'got %r' % got)

check('KHÔNG còn `= null` cho state', not re.search(r'let (?:items|name|flag|n)(?::\s*any)?\s*=\s*null;', js))
check('commitConstructorData vẫn gán vào StateManager', 'update$items(' in js)

print('TS mode giữ annotation')
ts = compile_sao("""<script setup lang="ts"></script>
@states({ items: [1, 2] })
<template><div>{{ items.length }}</div></template>
""", 'ts')
check('let items: any = [1, 2]', 'let items: any = [1, 2];' in ts)

print('@computed seed được vì dep đã có giá trị')
js2 = compile_sao("""@states({ a: [1, 2] })
@computed(total = a.length)
<template><div>{{ total }}</div></template>
""")
check('computed vẫn seed ngay', 'total = get$total();' in js2)
check('computed có listener cập nhật theo dep', "subscribe(['total']" in js2)

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
