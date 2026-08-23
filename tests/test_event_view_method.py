#!/usr/bin/env python3
"""
Regression guard (N7, client/docs/GAPS_AND_ROADMAP.md §1c — phát hiện khi
làm F1/F3, docs/FIX_PLAN_2026-08-14.md): method của component gọi TRONG
arrow function viết tay trong event — `@click(() => componentMethod(x))` —
phải resolve qua `this.view.componentMethod(x)`, giống hệt cách `{{ }}` đã
được vá ở F3.

Khác với `@click(componentMethod(x))` (không bọc arrow) — case đó ĐÃ ĐÚNG từ
trước, đi qua đường object-handler dispatch (`{"handler":"componentMethod",
"params":[...]}`), runtime tra `view[name]` theo TÊN, không quan tâm cú
pháp bên trong. Case BỌC ARROW đi qua đường compile biểu thức thường
(EventDirectiveProcessor.convert_php_array_to_js_object) — trước fix N7,
`componentMethod` ở lại làm định danh TRẦN trong closure → ReferenceError
lúc click, KHÔNG throw lúc compile nên rất khó phát hiện (bug này còn nằm
ẩn sau khi F1+F2 đã xong, chỉ lộ ra khi mount thật — xem
tests/compiled/compiled-views.test.ts phía client).

Chạy:  python3 tests/test_event_view_method.py
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


def compile_sao(source, name='in'):
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, name + '.sao')
        out = os.path.join(d, name + '.js')
        with open(inp, 'w') as f:
            f.write(source)
        r = subprocess.run([sys.executable, CLI, inp, out, 'Fn', 'test.fn'],
                            capture_output=True, text=True)
        if not os.path.exists(out):
            raise RuntimeError('compile failed:\n' + r.stdout + r.stderr)
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


def click_events(js):
    m = re.search(r'events:\s*\{\s*click:\s*\[(.*?)\]\s*\}', js, re.DOTALL)
    return m.group(1).strip() if m else None


SETUP = (
    "<script setup>\n"
    "export default {\n"
    "  pickItem(name) { setPicked(name); }\n"
    "}\n"
    "</script>\n"
)

print('method component trong arrow function → this.view.method(...)')
js = compile_sao(
    "@states({picked:''})\n"
    "<template><div><button @click(() => pickItem('x'))>p</button></div></template>\n"
    + SETUP
)
ev = click_events(js)
check('this.view.pickItem(...) xuất hiện, KHÔNG còn định danh trần',
      ev is not None and 'this.view.pickItem(' in ev,
      f'got={ev!r}')
check('đúng 1 lớp arrow (không bị F2 double-wrap trở lại)',
      ev is not None and ev.count('=>') == 1,
      f'got={ev!r}')

print('property access bên trong vẫn giữ nguyên (không hồi quy F1)')
js = compile_sao(
    "@states({items:[{id:1,name:\"a\"}]})\n"
    "<template><div>\n"
    "@foreach(items as item)\n"
    "  <button @click(() => pickItem(item.name))>p</button>\n"
    "@endforeach\n"
    "</div></template>\n"
    + SETUP
)
ev = click_events(js)
check('item.name giữ nguyên, KHÔNG bị đổi thành item+name',
      ev is not None and 'item.name' in ev and 'item+name' not in ev,
      f'got={ev!r}')
check('gọi qua this.view.pickItem', ev is not None and 'this.view.pickItem(' in ev)

print('@click(pickItem(x)) KHÔNG bọc arrow — vẫn handler-object dispatch như cũ')
js = compile_sao(
    "@states({picked:''})\n"
    "<template><div><button @click(pickItem('x'))>p</button></div></template>\n"
    + SETUP
)
check('vẫn {"handler":"pickItem",...}, không đổi hành vi cũ',
      '"handler":"pickItem"' in js)

print('state setter KHÔNG bị nhầm thành method component')
js = compile_sao(
    "@states({count:0})\n"
    "<template><div><button @click(() => setCount(count + 1))>+</button></div></template>\n"
    + SETUP
)
ev = click_events(js)
check('setCount(count + 1) giữ nguyên, không this.view.setCount',
      ev is not None and 'setCount(count + 1)' in ev and 'this.view.setCount' not in ev,
      f'got={ev!r}')

print('không rò tên method giữa 2 lần compile trong CÙNG 1 tiến trình')
sys.path.insert(0, os.path.join(ROOT, 'src'))
sys.path.insert(0, os.path.join(ROOT, 'src', 'sao2js'))
from main_compiler import BladeCompiler  # noqa: E402

compiler = BladeCompiler()
js_a = compiler.compile_blade_to_js(
    "@states({picked:''})\n"
    "<template><div><button @click(() => onlyInA())>p</button></div></template>\n"
    "<script setup>\nexport default { onlyInA() { setPicked('a'); } }\n</script>\n",
    'test.a', 'A', 'A',
)
js_b = compiler.compile_blade_to_js(
    "@states({picked:''})\n"
    "<template><div><button @click(() => onlyInA())>p</button></div></template>\n",
    'test.b', 'B', 'B',
)
ev_b = click_events(js_b)
check('view B (không có script setup) KHÔNG kế thừa onlyInA của view A',
      ev_b is not None and 'this.view.onlyInA' not in ev_b,
      f'view B vẫn resolve onlyInA như method — state đã bị rò từ view A: {ev_b!r}')

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
