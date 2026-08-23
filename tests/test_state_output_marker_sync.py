#!/usr/bin/env python3
"""
Regression guard: marker output của `{{ }}` phải ĐỒNG BỘ HOÀN TOÀN giữa
sao2js (CSR) và sao2blade (SSR) — cả SỐ LƯỢNG lẫn THỨ TỰ id trong mỗi scope.

Lịch sử — 2 lớp bug đã vá trong file này:

1. `{{ state }}` NGOÀI vòng lặp phải có marker ở SSR — `.sao` viết kiểu JS
   (`{{ count }}`), không phải PHP (`{{ $count }}`).
   `sao2blade/hydrate_processor.py::_get_state_keys` từng dùng regex BẮT BUỘC
   '$' nên với mọi file `.sao` nó trả rỗng → không emit marker, trong khi
   sao2js LUÔN compile `{{ state }}` thành `this.output(...)` cần đúng cặp
   marker đó ⇒ hydrate không claim được ⇒ nhân đôi nội dung. (Đã vá.)

2. FIX(F4, docs/FIX_PLAN_2026-08-14.md): sao2js từng compile MỌI `{{ }}`
   thành `this.output()` — kể cả biểu thức KHÔNG reactive (hằng, `@let` dẫn
   xuất) — trong khi sao2blade chỉ emit marker khi `skeys or loop_scopes`.
   `next_output()` là bộ đếm TUẦN TỰ THEO SCOPE (common/hydrate_id.py) — bỏ
   qua marker ở MỘT `{{ }}` làm LỆCH toàn bộ id của các `{{ }}` REACTIVE đứng
   SAU nó trong CÙNG scope. Hậu quả không chỉ nhân đôi: output SAU claim
   NHẦM marker của output KHÁC lúc hydrate → HOÁN ĐỔI + nhân bản nội dung.
   Điều kiện: ≥2 `{{ }}` cùng scope, có ít nhất 1 cái không-reactive đứng
   TRƯỚC — bố cục cực kỳ thường gặp (`<p>{{ nhãn }}: {{ giá_trị }}</p>`).
   Test cũ ("bất đối xứng còn lại") CHỈ so SỐ LƯỢNG với biểu thức hằng đơn lẻ
   nên không lộ ra lớp hậu quả THỨ HAI này — thay bằng so DÃY ID bên dưới.

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


# Phần TĨNH (trước `-${...}`/`-{$...}`) của id — phần động (chỉ số loop)
# khác cú pháp JS/PHP nên không so được, nhưng phần tĩnh PHẢI khớp: đây chính
# là thứ next_output() cấp phát tuần tự theo scope.
_BLADE_ID_RE = re.compile(r"@startMarker\('output',\s*(?:'([a-f0-9]+)'|\"([a-f0-9]+)-)")
_JS_ID_RE = re.compile(r"this\.output\(`([a-f0-9]+)(?:-\$\{)?")


def id_sequence(src):
    """Dãy id output THEO ĐÚNG THỨ TỰ compiler sinh ra, cho cả 2 phía."""
    blade = _run(BLADE_CLI, src, '.blade.php')
    js = _run(JS_CLI, src, '.js')
    blade_ids = [a or b for a, b in _BLADE_ID_RE.findall(blade)]
    js_ids = _JS_ID_RE.findall(js)
    return blade_ids, js_ids, blade, js


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

# ── FIX(F4): biểu thức HẰNG không còn tiêu marker ở CẢ HAI phía ────────────
print('biểu thức HẰNG: KHÔNG bên nào emit marker (đã vá — trước đây js emit, blade thì không)')
b, j, _, _ = counts("""@states({ a: 1 })
<template><div>{{ 'chuỗi tĩnh' }}</div></template>
""")
check('không bên nào tiêu marker cho hằng', b == 0 and j == 0, f'blade={b} js={j}')

# ── FIX(F4), ca THẬT của bug: @let dẫn xuất KHÔNG reactive đứng TRƯỚC một
#    output REACTIVE trong CÙNG scope. Đây là ca đã lộ ra HOÁN ĐỔI marker —
#    không chỉ nhân đôi. Bất biến: dãy id output PHẢI KHỚP TUYỆT ĐỐI (không
#    chỉ số lượng), vì lệch 1 vị trí sớm làm mọi id SAU nó trong scope lệch
#    theo — đúng cơ chế gây hoán đổi nội dung lúc hydrate. ─────────────────
print('@let (không reactive) đứng trước output reactive CÙNG scope — dãy id phải khớp TUYỆT ĐỐI')
blade_ids, js_ids, blade_src, js_src = id_sequence("""@states({ count: 0 })
@let(label = 'Total')
<template><p>{{ label }}: {{ count }}</p></template>
""")
check('label KHÔNG tiêu id (không marker cả 2 phía)',
      len(blade_ids) == 1 and len(js_ids) == 1,
      f'blade_ids={blade_ids} js_ids={js_ids}')
check('id DUY NHẤT (của count) khớp TUYỆT ĐỐI giữa blade và js',
      blade_ids == js_ids, f'blade_ids={blade_ids} js_ids={js_ids}')

print('nhiều {{ }} không-reactive xen giữa các {{ }} reactive cùng scope — dãy id khớp tuyệt đối')
blade_ids, js_ids, _, _ = id_sequence("""@states({ a: 0, b: 0 })
<template><p>{{ 'x' }}{{ a }}{{ 'y' }}{{ b }}{{ 'z' }}</p></template>
""")
check('dãy id (a rồi b) khớp tuyệt đối giữa blade và js',
      len(blade_ids) == 2 and blade_ids == js_ids,
      f'blade_ids={blade_ids} js_ids={js_ids}')

print('echo tĩnh emit this.text(...) PHẢI có `?? \'\'` — null/undefined = RỖNG như Blade')
_, js = counts("""@states({ n: 0 })
@let(nothing = null)
<template><div>{{ nothing }}</div></template>
""")[2:]
# Blade `{{ $x }}` với null → `e(null)` → chuỗi rỗng. Đường Output cũ cũng đã
# luôn có `?? ''` (Output.ts). Thiếu guard này thì CSR hiện chữ "null" còn SSR
# hiện rỗng — đúng lớp lệch SSR/CSR mà F4 sinh ra để diệt.
m = re.search(r"this\.text\(String\((.*?)\)\)", js)
check("this.text(String(expr ?? '')) — có guard null",
      m is not None and "?? ''" in m.group(1),
      f'got={m.group(1) if m else None!r}')

print('@foreach: {{ x }} (không phụ thuộc state riêng) vẫn tiêu marker vì ĐANG TRONG LOOP')
blade_ids, js_ids, _, _ = id_sequence("""@states({ xs: [] })
<template>
@foreach(xs as x)
  <i>{{ x }}</i>
@endforeach
</template>
""")
check('foreach vẫn emit marker ở cả 2 phía (loop_scopes, không phải state_vars)',
      len(blade_ids) == 1 and len(js_ids) == 1,
      f'blade_ids={blade_ids} js_ids={js_ids}')

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
