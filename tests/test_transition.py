#!/usr/bin/env python3
"""
Regression test (standalone) cho @transition('fade').

Contract với runtime (client Html.maybeRunEnter / destroy):
  - bucket RIÊNG `transition: { name: 'fade' }`, cạnh attrs/props/events/bind;
    vắng mặt = hành vi cũ y nguyên (gỡ DOM đồng bộ).
  - tên là HẰNG chuỗi hợp lệ cho class CSS — nó sinh ra `fade-enter-from`...,
    đổi theo state thì class chẳng khớp gì cả. Tên động → cảnh báo + bỏ qua.

Chạy:  python3 tests/test_transition.py
"""
import os
import subprocess
import sys
import tempfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CLI = os.path.join(ROOT, 'src', 'sao2js', 'cli.py')

passed = 0
failed = 0


def compile_sao(source: str):
    with tempfile.TemporaryDirectory() as d:
        inp = os.path.join(d, 'in.sao')
        out = os.path.join(d, 'out.js')
        with open(inp, 'w') as f:
            f.write(source)
        proc = subprocess.run([sys.executable, CLI, inp, out, 'Fn', 'test.fn'],
                              capture_output=True, text=True)
        with open(out) as f:
            return f.read(), proc.stdout


def check(name: str, cond: bool, detail: str = ''):
    global passed, failed
    if cond:
        passed += 1
        print('  ok   ' + name)
    else:
        failed += 1
        print('  FAIL ' + name + ((' — ' + detail) if detail else ''))


out, _ = compile_sao("""@states({ items: [], show: false })
<blade>
  @if($show)
    <div class="box" @transition('fade')>hi</div>
  @endif
  @foreach($items as $it) @key($it['id'])
    <li @transition('slide-up') @click(pick())>{{ $it['n'] }}</li>
  @endforeach
  <p>không transition</p>
</blade>
""")

print('emit')
check('bucket riêng, tên đúng', "transition: { name: 'fade' }" in out)
check('sống chung với class tĩnh',
      "classes: [{ type: 'static', value: \"box\" }], transition: { name: 'fade' }" in out)
check('sống chung với event',
      "transition: { name: 'slide-up' }" in out and '"handler":"pick"' in out)
check('tên có dấu gạch ngang hợp lệ', "name: 'slide-up'" in out)

print('tương thích ngược')
check('element không khai báo → KHÔNG có key transition', out.count('transition:') == 2,
      str(out.count('transition:')))

print('tên không hợp lệ')
bad, log = compile_sao("<blade><p @transition($dynamic)>x</p></blade>\n")
check('cảnh báo ra stdout', 'transition' in log and 'hằng chuỗi' in log)
check('KHÔNG emit bucket', 'transition:' not in bad)
check('element vẫn render', '"p"' in bad)

bad2, log2 = compile_sao("<blade><p @transition('có khoảng trắng')>x</p></blade>\n")
check('từ chối tên có khoảng trắng', 'transition:' not in bad2 and 'hằng chuỗi' in log2)

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
