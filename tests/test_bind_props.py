#!/usr/bin/env python3
"""
Regression test (standalone, không cần pytest) cho form-binding directives:

  1. `@bind(key)` / `@val(key)` → own top-level config bucket
     `bind: { key: "<key>" }` — sibling of attrs/props/events (KHÔNG smuggle
     qua attrs bằng 2 boolean marker như trước, vốn có thể lẫn với 1 static
     boolean attr thật tình cờ đứng trước state-key marker trong object).
  2. `@checked(expr)` / `@disabled(expr)` / `@readonly(expr)`... → DOM property
     binding: props: { "<prop>": { type:'binding', factory: () => expr,
     stateKeys:[...] } } — KHÔNG còn emit garbage static attrs từ tên biến.

Chạy:  python3 tests/test_bind_props.py
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


# ── 1. @bind(key) → own top-level bind: { key: '...' } bucket ────
js = compile_sao(
    "@states({ userName: '' })\n"
    "<input type=\"text\" @bind(userName)>\n"
)
check("@bind → bind: { key: 'userName' } (own config bucket, not inside attrs)",
      re.search(r"bind:\s*\{\s*key:\s*'userName'\s*\}", js) is not None)
check("@bind → không rò rỉ 'bind'/'userName' vào attrs",
      re.search(r'"bind":\s*\{', js) is None and re.search(r'"userName":\s*\{', js) is None)

# ── 2. @checked(state) → props binding ───────────────────────────
js = compile_sao(
    "@states({ done: true })\n"
    "<input type=\"checkbox\" @checked(done)>\n"
)
check("@checked → props checked binding",
      re.search(r'props:\s*\{\s*"checked":\s*\{\s*type:\s*\'binding\'', js) is not None,
      js[js.find('this.html'):js.find('this.html') + 300] if 'this.html' in js else js[:200])
check("@checked factory là expression",
      'factory: () => done' in js)
check("@checked stateKeys bắt 'done'",
      re.search(r'"checked":\s*\{[^}]*stateKeys:\s*\["done"\]', js) is not None)
check("KHÔNG còn garbage attr \"done\": static true",
      re.search(r'"done":\s*\{\s*type:\s*\'static\',\s*value:\s*true\s*\}', js) is None)
check("KHÔNG emit attr 'checked' static true",
      re.search(r'"checked":\s*\{\s*type:\s*\'static\',\s*value:\s*true\s*\}', js) is None)

# ── 3. @disabled(expr) với biểu thức ─────────────────────────────
js = compile_sao(
    "@states({ count: 0 })\n"
    "<button @disabled(count > 3)>Add</button>\n"
)
check("@disabled(expr) → props disabled binding factory expr",
      'factory: () => count > 3' in js)
check("@disabled stateKeys=['count']",
      re.search(r'"disabled":\s*\{[^}]*stateKeys:\s*\["count"\]', js) is not None)

# ── 4. @readonly → prop readOnly (camelCase DOM property) ────────
js = compile_sao(
    "@states({ locked: false })\n"
    "<input @readonly(locked)>\n"
)
check("@readonly → prop \"readOnly\" (camelCase)",
      re.search(r'"readOnly":\s*\{\s*type:\s*\'binding\'', js) is not None)

# ── 5. boolean attr HTML thường vẫn static (không nhầm directive) ─
js = compile_sao(
    "<input type=\"checkbox\" checked disabled>\n"
)
check("boolean attr thường (không @) vẫn static",
      re.search(r'"checked":\s*\{\s*type:\s*\'static\',\s*value:\s*true\s*\}', js) is not None
      and 'props:' not in js)

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
