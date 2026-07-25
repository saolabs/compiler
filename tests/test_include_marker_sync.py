#!/usr/bin/env python3
"""
Regression guard (standalone, không cần pytest) cho ĐỒNG BỘ id component giữa
Blade (SSR) và JS (CSR).

Bất biến: mỗi @include/custom-tag sinh một hydrate id tất định (md5[:8]). Blade
emit  @startMarker('component', 'HASH')  và JS emit  this.include(`HASH`, ...).
Hai danh sách hash — theo đúng THỨ TỰ — PHẢI trùng khít, nếu không marker server
(<!--s:c:{viewId}-{HASH}-s-->) sẽ không khớp component client → hydration vỡ âm
thầm (claimSSRMarkers miss → CSR render lại / nhân đôi DOM).

Blade và JS đến từ HAI traversal độc lập (hydrate_processor regex theo dòng vs
render_generator đi AST) nên chúng CÓ THỂ lệch — test này chốt lại.

Chạy:  python3 tests/test_include_marker_sync.py
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
        r = subprocess.run([sys.executable, cli, inp, out, 'Fn', 'test.fn'],
                           capture_output=True, text=True)
        if not os.path.exists(out):
            raise RuntimeError(f'compile failed ({cli}):\n{r.stdout}\n{r.stderr}')
        with open(out) as f:
            return f.read()


def compile_js(source: str) -> str:
    return _run(JS_CLI, source, '.js')


def compile_blade(source: str) -> str:
    return _run(BLADE_CLI, source, '.blade.php')


def blade_component_hashes(blade: str):
    """Ordered list of hashes from @startMarker('component', 'HASH')."""
    return re.findall(r"@startMarker\(\s*'component'\s*,\s*'([0-9a-f]{8})'", blade)


def js_include_hashes(js: str):
    """Ordered list of hashes from this.include(`HASH`, ...)."""
    return re.findall(r"this\.include\(\s*`([0-9a-f]{8})`", js)


def check(name: str, cond: bool, detail: str = ''):
    global passed, failed
    if cond:
        print(f'  ✅ {name}')
        passed += 1
    else:
        print(f'  ❌ {name}  {detail}')
        failed += 1


# ── Fixture: extends + block với custom-tag includes + @include + nested ──────
SOURCE = (
    "@import(__template__ + 'header' as Header)\n"
    "@import(__template__ + 'footer' as Footer)\n"
    "@import(__template__ + 'post-list')\n"
    "@state(status = false)\n"
    "@const([posts, setPosts] = useState([]))\n"
    "@extends(__layout__ + 'base')\n"
    "@block('content')\n"
    "    <Header></Header>\n"
    "    <div class=\"demo\">\n"
    "        <h1>Hello</h1>\n"
    "        @include(__template__ + 'sidebar')\n"
    "    </div>\n"
    "    <post-list :posts=\"posts\" name=\"test\" />\n"
    "    <Footer>\n"
    "        <post-list :posts=\"posts\" name=\"test\" />\n"
    "    </Footer>\n"
    "@endblock\n"
)

print('Test: include/component marker id sync (blade ↔ js)')

js = compile_js(SOURCE)
blade = compile_blade(SOURCE)

blade_ids = blade_component_hashes(blade)
js_ids = js_include_hashes(js)

check('blade emits component markers', len(blade_ids) > 0,
      f'got {blade_ids!r}')
check('js emits include ids', len(js_ids) > 0,
      f'got {js_ids!r}')
check('same NUMBER of component ids', len(blade_ids) == len(js_ids),
      f'blade={len(blade_ids)} {blade_ids!r} vs js={len(js_ids)} {js_ids!r}')
check('component ids MATCH in order (SSR/CSR sync)', blade_ids == js_ids,
      f'\n      blade={blade_ids!r}\n      js   ={js_ids!r}')

print(f'\n{passed} passed, {failed} failed')
sys.exit(1 if failed else 0)
