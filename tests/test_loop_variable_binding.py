#!/usr/bin/env python3
"""
Regression guard: tên LoopContext phải KHỚP giữa hai nhánh compiler.

`.sao` dùng `__loop` (đúng tên tham số callback mà sao2js sinh ra:
`(item, __loopKey, __loopIndex, __loop) => ...`). Nhánh Blade KHÔNG có
`$__loop` — Laravel chỉ cấp `$loop` trong `@foreach`. Không map thì
`{{ __loop.index }}` ra `$__loop->index`, biến KHÔNG TỒN TẠI phía SSR trong
khi CSR chạy đúng ⇒ lệch SSR/CSR, đúng lớp F1/F3/F4.

Preprocessor map `__loop` (và alias `loop`) → `$loop` — xem `LOOP_ALIASES`
trong src/preprocessor/expression-transformer.js. Bài test này khoá cả hai
chiều: JS bind `__loop`, Blade ra `$loop`.

Xem thêm: `client/src/core/view/LoopContext.ts::snapshot()` — phía runtime phải
truyền BẢN CHỤP theo từng vòng, vì `childrenFactory` chạy muộn và sẽ bắt
LoopContext dùng chung theo tham chiếu (mọi hàng đọc ra index CUỐI).

Chạy:  python3 tests/test_loop_variable_binding.py
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
        return f.read(), out, d


def check(name, cond, detail=''):
    global passed, failed
    if cond:
        passed += 1
        print('  ok   ' + name)
    else:
        failed += 1
        print('  FAIL ' + name + ((' — ' + detail) if detail else ''))


def node_check(path):
    if shutil.which('node') is None:
        return None
    r = subprocess.run(['node', '--check', path], capture_output=True, text=True)
    return (r.returncode == 0, r.stderr.strip())


print('@foreach: callback bind `__loop`')
js, path, d = compile_sao("""@states({ xs: [1,2,3] })
<template>
@foreach(xs as x)
  <b @click(f(__loop.index))>B</b>
  <i>{{ __loop.iteration }}</i>
@endforeach
</template>
""")
m = re.search(r'__foreach\(xs,\s*\(([^)]*)\)', js)
params = m.group(1) if m else ''
names = [seg.split('=')[0].strip() for seg in params.split(',')]
check('tham số callback có `__loop`', '__loop' in names, f'params={params!r}')
check('biểu thức trong event giữ `__loop.index`', '__loop.index' in js)
check('echo `{{ __loop.iteration }}` dùng được', '__loop.iteration' in js)
syn = node_check(path)
if syn is None:
    print('  skip node --check (không có node)')
else:
    ok, err = syn
    check('file compiled PARSE ĐƯỢC', ok, err.split(chr(10))[0] if err else '')
shutil.rmtree(d, ignore_errors=True)

print('@for: bind `__loop`')
js, path, d = compile_sao("""@states({ n: 3 })
<template>
@for(i = 0; i < 3; i++)
  <a>{{ __loop.index }}</a>
@endfor
</template>
""")
check('có bind `__loop` ở @for', '(__loop)' in js or '(__loop: any)' in js,
      'không thấy tham số __loop trong this.__for(...)')
syn = node_check(path)
if syn is not None:
    ok, err = syn
    check('@for: file compiled PARSE ĐƯỢC', ok, err.split(chr(10))[0] if err else '')
shutil.rmtree(d, ignore_errors=True)

# ── Chiều Blade: preprocessor PHẢI map `__loop` → `$loop` ─────────────────
# Đây là nửa dễ lệch: CLI sao2blade gọi thẳng KHÔNG qua preprocessor nên
# không lộ ra; app thật luôn chạy preprocessor. Test dùng đúng API Node đó.
print('Blade: preprocessor map `__loop`/`loop` → `$loop` của Laravel')
import json
node = shutil.which('node')
if node is None:
    print('  skip (không có node)')
else:
    d2 = tempfile.mkdtemp()
    sao = os.path.join(d2, 'i.sao')
    open(sao, 'w').write("""@states({ xs: [1,2,3] })
@foreach(xs as x)
  <p>{{ __loop.index }} | {{ loop.first }}</p>
@endforeach
""")
    script = os.path.join(d2, 'run.js')
    open(script, 'w').write(
        "const C=require(%s),P=require(%s),fs=require('fs');" % (
            json.dumps(os.path.join(ROOT, 'src', 'index.js')),
            json.dumps(os.path.join(ROOT, 'src', 'preprocessor'))) +
        "const parts=new C().parseSaoFile(fs.readFileSync(process.argv[2],'utf-8'),process.argv[2]);"
        "const bp=new P().preprocess(parts);"
        "process.stdout.write(bp.bladeWithSSR||bp.blade);"
    )
    r = subprocess.run([node, script, sao], capture_output=True, text=True, cwd=ROOT)
    blade = r.stdout
    check('`__loop.index` → `$loop->index`', '$loop->index' in blade,
          f'blade={blade.strip()!r}')
    check('`loop.first` → `$loop->first`', '$loop->first' in blade,
          f'blade={blade.strip()!r}')
    check('KHÔNG còn `$__loop` (biến không tồn tại ở Laravel)',
          '$__loop' not in blade, f'blade={blade.strip()!r}')
    shutil.rmtree(d2, ignore_errors=True)

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
