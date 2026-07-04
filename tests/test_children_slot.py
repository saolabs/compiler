#!/usr/bin/env python3
"""
Regression test (standalone, không cần pytest) cho slots/children:

  Parent:  @import('components.card' as Card)
           <Card title="x"> <p>...</p> </Card>
    → this.include(..., 'components.card', ..., () => ({
          "title": "x",
          __ONE_CHILDREN_CONTENT__: (parentElement) => [ ...elements ]
      }))

  Child:   @children
    → ...this.__children(__ONE_CHILDREN_CONTENT__, parentElement)
      (+ auto-inject `__ONE_CHILDREN_CONTENT__ = ''` vào destructure __data__)

Chạy:  python3 tests/test_children_slot.py
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


# ── 1. Child view với @children ──────────────────────────────────
js = compile_sao(
    "@props({ title: '' })\n"
    "<template>\n"
    "    <div class=\"card\">\n"
    "        <h3>{{ title }}</h3>\n"
    "        <div class=\"card-body\">\n"
    "            @children\n"
    "        </div>\n"
    "    </div>\n"
    "</template>\n"
)
check("@children → spread this.__children(...)",
      '...this.__children(__ONE_CHILDREN_CONTENT__, parentElement)' in js)
check("__ONE_CHILDREN_CONTENT__ auto-inject vào destructure __data__",
      re.search(r"let \{[^}]*__ONE_CHILDREN_CONTENT__ = ''[^}]*\} = __data__", js) is not None)

# ── 2. Parent: custom tag với children ───────────────────────────
js = compile_sao(
    "@import('components.card' as Card)\n"
    "@states({ msg: 'hello' })\n"
    "<template>\n"
    "    <div class=\"page\">\n"
    "        <Card title=\"Greeting\">\n"
    "            <p class=\"inner\">{{ msg }}</p>\n"
    "        </Card>\n"
    "    </div>\n"
    "</template>\n"
)
check("custom tag → this.include('components.card')",
      re.search(r"this\.include\([^,]+, 'components\.card'", js) is not None,
      js[:300])
check("children → __ONE_CHILDREN_CONTENT__ element factory trong data",
      re.search(r'__ONE_CHILDREN_CONTENT__:\s*\(parentElement\) => \[', js) is not None)
check("data attr title truyền vào include",
      '"title": "Greeting"' in js)
check("children elements nằm TRONG factory (p.inner)",
      re.search(r'__ONE_CHILDREN_CONTENT__:\s*\(parentElement\) => \[.*?"inner".*?\]',
                js, re.DOTALL) is not None)

# ── 3. Parent: custom tag KHÔNG children (self-closing) ──────────
js = compile_sao(
    "@import('components.card' as Card)\n"
    "<template>\n"
    "    <Card title=\"OnlyTitle\" />\n"
    "</template>\n"
)
check("self-closing tag → include không có __ONE_CHILDREN_CONTENT__",
      'this.include(' in js and '__ONE_CHILDREN_CONTENT__' not in js)

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
