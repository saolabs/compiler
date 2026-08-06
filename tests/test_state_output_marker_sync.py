#!/usr/bin/env python3
"""
Regression guard: `{{ state }}` NGOÀI vòng lặp phải có marker output ở SSR.

Bối cảnh: `.sao` viết kiểu JS (`{{ count }}`), không phải PHP (`{{ $count }}`).
`sao2blade/hydrate_processor.py::_get_state_keys` từng dùng regex BẮT BUỘC '$'
nên với mọi file `.sao` nó trả rỗng → `skeys` rỗng → không emit
`@startMarker('output', ...)`. Trong khi sao2js LUÔN compile `{{ state }}` thành
`this.output(...)` cần đúng cặp marker đó ⇒ hydrate không claim được ⇒ client
chèn giá trị lần nữa cạnh text server ⇒ NHÂN ĐÔI nội dung.

Vì sao guard cũ không bắt: `test_loop_output_marker_sync.py` chỉ dựng fixture
có `{{ }}` BÊN TRONG `@foreach`, mà nhánh đó vẫn chạy nhờ mệnh đề
`or loop_scopes` — chính nó che mất nửa còn lại của lỗi.

Kèm: tên khai báo bởi `@computed` cũng phải được coi là state key (slot của nó
nằm chung `states` ở runtime và sao2js đưa vào stateKeys).

Chạy:  python3 tests/test_state_output_marker_sync.py
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


def _run(cli, source, suffix):
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, 'in.sao')
        out = os.path.join(d, 'out' + suffix)
        with open(inp, 'w') as f:
            f.write(source)
        subprocess.run([sys.executable, cli, inp, out, 'Fn', 'test.fn'],
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


def counts(src):
    blade = _run(BLADE_CLI, src, '.blade.php')
    js = _run(JS_CLI, src, '.js')
    return (len(re.findall(r"@startMarker\('output'", blade)),
            len(re.findall(r"this\.output\(", js)),
            blade, js)


print('state NGOÀI loop')
b, j, _, _ = counts("""@states({ count: 0, name: 'a' })
<template><div><span>{{ count }}</span><em>{{ name }}</em></div></template>
""")
check('blade emit marker cho mọi {{ state }} ngoài loop', b == 2, f'blade={b}')
check('số marker khớp sao2js', b == j, f'blade={b} js={j}')

print('trộn state ngoài loop + biến loop trong loop')
b, j, _, _ = counts("""@states({ count: 0, items: [] })
<template>
  <p>{{ count }}</p>
  @foreach(items as it)
    <li>{{ it['text'] }}</li>
  @endforeach
</template>
""")
check('khớp khi trộn hai loại', b == j, f'blade={b} js={j}')

print('@computed cũng là state key')
b, j, blade, _ = counts("""@states({ a: 1, b: 2 })
@computed(total = a + b)
<template><div>{{ total }}</div></template>
""")
check('blade emit marker cho {{ computed }}', b >= 1, f'blade={b}')
check('số marker khớp sao2js', b == j, f'blade={b} js={j}')

print('@props/@vars cũng là state key (data var = reactive key ở runtime)')
b, j, _, _ = counts("""@props({ title: 'x', n: 0 })
<template><div><h1>{{ title }}</h1><span>{{ n }}</span></div></template>
""")
check('blade emit marker cho {{ prop }}', b == 2, f'blade={b}')
check('số marker khớp sao2js', b == j, f'blade={b} js={j}')

# ── Bất đối xứng CÒN LẠI, ghi lại có chủ ý ────────────────────────────────
# sao2js compile MỌI `{{ }}` thành this.output() kể cả biểu thức HẰNG
# (`{{ 'chuỗi' }}`), còn blade chỉ emit marker khi có state key hoặc trong loop.
# Chưa vá vì phải chọn sửa bên nào (bỏ Output cho hằng ở js, hay emit thêm
# marker ở blade), và hằng trong `{{ }}` hiếm gặp. Xem §2.21.
print('bất đối xứng còn lại: biểu thức HẰNG (đã biết, chưa vá)')
b, j, _, _ = counts("""@states({ a: 1 })
<template><div>{{ 'chuỗi tĩnh' }}</div></template>
""")
check('ghi nhận: js emit output cho hằng, blade thì không', b == 0 and j == 1,
      f'blade={b} js={j} — nếu con số đổi, hãy cập nhật §2.21')

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
