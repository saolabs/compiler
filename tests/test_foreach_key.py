#!/usr/bin/env python3
"""
Regression test (standalone, không cần pytest) cho @key trong @foreach:

  @foreach(items as it) @key(it.id) ... @endforeach
    → this.__foreach(items, (it, ...) => [...], (it) => it.id)

Arg thứ 3 (keyFn) là contract với runtime ForeachSlotCache — field-keyed
reconciliation. Không có @key → không emit keyFn (identity keying).

Chạy:  python3 tests/test_foreach_key.py
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


# ── 1. @key(it.id) → keyFn arg thứ 3 ─────────────────────────────
js = compile_sao(
    "@states({ items: [] })\n"
    "<ul>\n"
    "@foreach(items as it)\n"
    "    @key(it.id)\n"
    "    <li>{{ it.name }}</li>\n"
    "@endforeach\n"
    "</ul>\n"
)
check("@key → __foreach(..., (it) => it.id)",
      re.search(r'__foreach\(items,.*\(it\) => it\.id\)', js, re.DOTALL) is not None,
      js[js.find('__foreach'):js.find('__foreach') + 200] if '__foreach' in js else js[:200])
check("element ID trong loop dùng key (`-${it.id}`)",
      '-${it.id}`' in js)

# ── 2. Không @key → không keyFn ──────────────────────────────────
js = compile_sao(
    "@states({ items: [] })\n"
    "<ul>\n"
    "@foreach(items as it)\n"
    "    <li>{{ it.name }}</li>\n"
    "@endforeach\n"
    "</ul>\n"
)
check("không @key → __foreach chỉ 2 args",
      re.search(r'=> it\.', js) is None or '=> it.id)' not in js)
check("element ID fallback __loopIndex",
      '${__loopIndex + 1}`' in js)

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
