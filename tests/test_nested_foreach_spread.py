#!/usr/bin/env python3
"""
Regression guard: `@foreach` KHÔNG có state key, nằm trong children list của một
element, phải được emit KÈM spread (`...this.__foreach(...)`).

`__foreach()` trả về MẢNG. Thiếu `...` thì nó thành MỘT phần tử mảng-lồng trong
children list; `mountElementList` không nhận ra kiểu đó nên bỏ qua ⇒ toàn bộ
item của loop biến mất IM LẶNG (element cha vẫn render, chỉ rỗng ruột).

Chỉ nhánh không-state-key mới cần: có state key thì loop đã được bọc trong
`this.reactive(...)`, bản thân nó là element hợp lệ.

Tái hiện thực tế: `@foreach > @if > @foreach` (roles trong danh sách user) —
div `.roles` render nhưng không có chip nào.

Chạy:  python3 tests/test_nested_foreach_spread.py
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


def compile_sao(source):
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, 'in.sao')
        out = os.path.join(d, 'out.js')
        with open(inp, 'w') as f:
            f.write(source)
        subprocess.run([sys.executable, CLI, inp, out, 'Fn', 'test.fn'],
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


js = compile_sao("""@states({ users: [], tags: [] })
<template>
  <div>
    @foreach(users as user)
      @key(user['id'])
      <article>
        @if(user['roles'].length > 0)
          <div class="roles">
            @foreach(user['roles'] as role)
              <span>{{ role['name'] }}</span>
            @endforeach
          </div>
        @endif
      </article>
    @endforeach
    @foreach(tags as tag)
      <em>{{ tag }}</em>
    @endforeach
  </div>
</template>
""")

calls = re.findall(r"(\.\.\.)?this\.__foreach\((\w+(?:\['\w+'\])?)", js)
by_arr = {arr: bool(dots) for dots, arr in calls}
print('emit spread đúng chỗ')
check('loop lồng không state key CÓ spread', by_arr.get("user['roles']") is True,
      str(by_arr))
check('loop trên state KHÔNG spread (đã bọc Reactive)', by_arr.get('users') is False,
      str(by_arr))
check('loop trên state khác cũng KHÔNG spread', by_arr.get('tags') is False,
      str(by_arr))

print('nhánh imperative (@exec trong loop) cũng phải spread')
js2 = compile_sao("""@states({ items: [] })
<template>
  <div>
    @foreach(items as it)
      <p>
        @foreach(it['sub'] as s)
          @php($x = 1)
          <b>{{ s }}</b>
        @endforeach
      </p>
    @endforeach
  </div>
</template>
""")
inner = re.search(r"(\.\.\.)?this\.__foreach\(it\['sub'\]", js2)
check('loop lồng có exec node vẫn CÓ spread', bool(inner and inner.group(1)),
      inner.group(0) if inner else 'không tìm thấy')

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
