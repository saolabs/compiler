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
sys.path.insert(0, os.path.join(ROOT, 'src'))

from sao2blade.blade_compiler import BladeTemplateCompiler

passed = 0
failed = 0


def compile_sao(source: str) -> str:
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, 'in.sao')
        out = os.path.join(d, 'out.js')
        with open(inp, 'w') as f:
            f.write(source)
        result = subprocess.run([sys.executable, CLI, inp, out, 'Fn', 'test.fn'],
                                capture_output=True, text=True)
        if result.returncode != 0:
            raise AssertionError(result.stderr or result.stdout)
        with open(out) as f:
            return f.read()


def compile_sao_failure(source: str) -> subprocess.CompletedProcess:
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, 'in.sao')
        out = os.path.join(d, 'out.js')
        with open(inp, 'w') as f:
            f.write(source)
        return subprocess.run([sys.executable, CLI, inp, out, 'Fn', 'test.fn'],
                              capture_output=True, text=True)


def compile_blade(source: str) -> str:
    return BladeTemplateCompiler().compile(source)


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

# ── 1b. {{ $children }} là raw alias của cùng ChildrenNode ───────
js_alias = compile_sao(
    "<template>\n"
    "    <div class=\"card-body\">{{ $children }}</div>\n"
    "</template>\n"
)
check("{{ $children }} → cùng lazy this.__children(...) contract",
      '...this.__children(__ONE_CHILDREN_CONTENT__, parentElement)' in js_alias)
check("{{ $children }} không compile thành output/escaped text",
      '$children' not in js_alias and 'this.output(' not in js_alias)

blade_alias = compile_blade(
    "<template>\n"
    "    <div class=\"card-body\">{{ $children }}</div>\n"
    "</template>\n"
)
check("Blade {{ $children }} → canonical raw slot",
      '{!! $__ONE_CHILDREN_CONTENT__ !!}' in blade_alias)
check("Blade không giữ escaped {{ $children }}",
      '{{ $children }}' not in blade_alias)

blade_raw_alias = compile_blade(
    "<template><div>{!! $children !!}</div></template>"
)
check("Blade {!! $children !!} → cùng canonical raw slot",
      '{!! $__ONE_CHILDREN_CONTENT__ !!}' in blade_raw_alias)
js_raw_alias = compile_sao(
    "<template><div>{!! $children !!}</div></template>"
)
check("JS {!! $children !!} → cùng lazy ChildrenNode",
      '...this.__children(__ONE_CHILDREN_CONTENT__, parentElement)' in js_raw_alias)

# ── 2. Parent: custom tag với children ───────────────────────────
parent_source = (
    "@import('components.card' as Card)\n"
    "@useState($msg, 'hello')\n"
    "<template>\n"
    "    <div class=\"page\">\n"
    "        <Card title=\"Greeting\">\n"
    "            <p class=\"inner\">{{ $msg }}</p>\n"
    "        </Card>\n"
    "    </div>\n"
    "</template>\n"
)
js = compile_sao(parent_source)
blade_parent = compile_blade(parent_source)
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
check("parent không materialize children trước khi child gặp placeholder",
      'this.__children(' not in js)
check("Blade parent capture children trước khi truyền include",
      'startSection' in blade_parent and 'yieldContent' in blade_parent and
      "'__ONE_CHILDREN_CONTENT__'" in blade_parent)

blade_element_ids = re.findall(r"__VIEW_ID__\s*\.\s*'-([0-9a-f]{8})'", blade_parent)
js_element_ids = re.findall(r'this\.html\(\s*`([0-9a-f]{8})`', js)
blade_component_ids = re.findall(
    r"@startMarker\(\s*'component'\s*,\s*'([0-9a-f]{8})'", blade_parent
)
js_component_ids = re.findall(r'this\.include\(\s*`([0-9a-f]{8})`', js)
blade_output_ids = re.findall(
    r"@startMarker\(\s*'output'\s*,\s*'([0-9a-f]{8})'", blade_parent
)
js_output_ids = re.findall(r'this\.output\(\s*`([0-9a-f]{8})`', js)
check("slot subtree hydrate element IDs đồng bộ Blade ↔ JS",
      blade_element_ids == js_element_ids,
      f'blade={blade_element_ids!r}, js={js_element_ids!r}')
check("slot component marker IDs đồng bộ Blade ↔ JS",
      blade_component_ids == js_component_ids,
      f'blade={blade_component_ids!r}, js={js_component_ids!r}')
check("reactive output trong slot đồng bộ hydrate IDs Blade ↔ JS",
      blade_output_ids == js_output_ids and len(blade_output_ids) == 1,
      f'blade={blade_output_ids!r}, js={js_output_ids!r}')

# ── 3. Parent: custom tag KHÔNG children (self-closing) ──────────
js = compile_sao(
    "@import('components.card' as Card)\n"
    "<template>\n"
    "    <Card title=\"OnlyTitle\" />\n"
    "</template>\n"
)
check("self-closing tag → include không có __ONE_CHILDREN_CONTENT__",
      'this.include(' in js and '__ONE_CHILDREN_CONTENT__' not in js)

# ── 4. Một component chỉ có một insertion point ──────────────────
failed_compile = compile_sao_failure(
    "<template>\n"
    "    <div>@children</div>\n"
    "    <aside>{{ $children }}</aside>\n"
    "</template>\n"
)
check("duplicate children placeholder → compile error",
      failed_compile.returncode != 0)
check("duplicate error không fallback sang legacy renderer",
      'only one children placeholder' in (failed_compile.stderr + failed_compile.stdout))

try:
    compile_blade(
        "<template><div>@children</div><aside>{{ $children }}</aside></template>"
    )
    blade_duplicate_failed = False
except ValueError as error:
    blade_duplicate_failed = 'only one children placeholder' in str(error)
check("Blade và JS cùng reject duplicate placeholder", blade_duplicate_failed)

# ── 5. Include props phải subscribe dependency của parent ─────────
js = compile_sao(
    "@useState($title, 'A')\n"
    "<template>\n"
    "    @include('components.card', ['title' => $title])\n"
    "</template>\n"
)
check("@include data state → component stateKeys",
      re.search(r"this\.include\([^,]+, 'components\.card', parentElement, \[\"title\"\]", js) is not None)

js = compile_sao(
    "@import('components.card' as Card)\n"
    "@useState($title, 'A')\n"
    "<template>\n"
    "    <Card :title=\"$title\" />\n"
    "</template>\n"
)
check("custom component binding state → component stateKeys",
      re.search(r"this\.include\([^,]+, 'components\.card', parentElement, \[\"title\"\]", js) is not None)

# ── 6. Paired component là include có lazy children payload ───────
nested_source = (
    "@import('components.shell' as Shell)\n"
    "@import('components.one' as One)\n"
    "@import('components.two' as Two)\n"
    "<template>\n"
    "    <Shell>\n"
    "        <One />\n"
    "        <Two />\n"
    "    </Shell>\n"
    "</template>\n"
)
js_nested = compile_sao(nested_source)
blade_nested = compile_blade(nested_source)
check("paired outer component → one include with lazy children factory",
      re.search(r"this\.include\([^,]+, 'components\.shell'.*?__ONE_CHILDREN_CONTENT__:\s*\(parentElement\) => \[",
                js_nested, re.DOTALL) is not None)
check("nested children vẫn là component includes",
      "'components.one'" in js_nested and "'components.two'" in js_nested)
check("Blade paired component capture rồi truyền canonical children variable",
      'startSection' in blade_nested and 'yieldContent' in blade_nested and
      "'__ONE_CHILDREN_CONTENT__'" in blade_nested)

# ── 7. Malformed component tags phải fail đồng nhất Blade ↔ JS ───
malformed_source = (
    "@import('components.shell' as Shell)\n"
    "<template>\n"
    "    <Shell />\n"
    "    </Shell>\n"
    "</template>\n"
)
failed_compile = compile_sao_failure(malformed_source)
malformed_message = failed_compile.stderr + failed_compile.stdout
check("JS reject closing tag sau self-closing component",
      failed_compile.returncode != 0 and 'Unexpected closing component tag </Shell>' in malformed_message)

try:
    compile_blade(malformed_source)
    blade_malformed_failed = False
except ValueError as error:
    blade_malformed_failed = 'Unexpected closing component tag </Shell>' in str(error)
check("Blade reject cùng malformed component tree", blade_malformed_failed)

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
