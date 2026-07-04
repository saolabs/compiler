#!/usr/bin/env python3
"""
Regression test (standalone, không cần pytest) cho 2 tính năng compiler:

  1. Shorthand `:attr="expr"` trên thẻ HTML → binding attr reactive
     (tương đương `attr="{{ expr }}"`).
  2. `<style scoped>` → emit cờ `"scoped":true` trong mảng styles
     (`<style>` thường = global, KHÔNG có cờ).

Chạy:  python3 tests/test_attr_scoped.py
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


def compile_sao(source: str) -> str:
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, 'in.sao')
        out = os.path.join(d, 'out.js')
        with open(inp, 'w') as f:
            f.write(source)
        subprocess.run([sys.executable, CLI, inp, out, 'Fn', 'test.fn'],
                       capture_output=True, text=True)
        with open(out) as f:
            return f.read()


def check(name: str, cond: bool, detail: str = ''):
    global passed, failed
    if cond:
        print(f'  ✅ {name}')
        passed += 1
    else:
        print(f'  ❌ {name}  {detail}')
        failed += 1


# ── 1. :attr shorthand ──────────────────────────────────────────
js = compile_sao(
    "@states({ active: true })\n"
    "<div :data-name=\"user.firstName + ' ' + user.lastName\" "
    ":data-active=\"active\" data-static=\"x\">Hi</div>\n"
)
# :data-name → binding với factory là biểu thức thật (không phải string literal)
check(":data-name → binding attr",
      re.search(r'"data-name":\s*\{\s*type:\s*\'binding\'', js) is not None, js[:200])
check(":data-name factory là expression",
      "factory: () => user.firstName + ' ' + user.lastName" in js)
# :data-active → reactive, stateKeys bắt 'active'
check(":data-active reactive stateKeys=['active']",
      re.search(r'"data-active":\s*\{[^}]*stateKeys:\s*\["active"\]', js) is not None, js[:300])
# data-static giữ nguyên static
check("data-static giữ static",
      re.search(r'"data-static":\s*\{\s*type:\s*\'static\'', js) is not None)
# tên attr KHÔNG còn dấu ':'
check("không emit attr tên ':data-name'", '":data-name"' not in js)

# ── 2. <style scoped> ───────────────────────────────────────────
js_scoped = compile_sao(
    "<div class=\"c\">x</div>\n"
    "<style scoped>.c{color:red}</style>\n"
)
check("<style scoped> → \"scoped\":true", '"scoped":true' in js_scoped)

js_global = compile_sao(
    "<div class=\"c\">x</div>\n"
    "<style>.c{color:red}</style>\n"
)
check("<style> global → KHÔNG có scoped", '"scoped":true' not in js_global and 'styles:' in js_global)

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
