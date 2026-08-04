#!/usr/bin/env python3
"""
Regression test (standalone) cho event modifier: @click.prevent.stop(...)

Contract với runtime (client ViewController.addEventListener):
  - modifier đi trong bucket RIÊNG `eventModifiers: { click: [...] }`,
    KHÔNG nhét vào `events` — shape `events: {click:[...]}` là contract sẵn có,
    view compile trước tính năng này phải chạy y nguyên.
  - tên modifier hợp lệ giới hạn trong EVENT_MODIFIERS; tên lạ → cảnh báo +
    bỏ qua, handler vẫn đăng ký (gõ sai không được làm mất event).

Chạy:  python3 tests/test_event_modifiers.py
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
    """Trả (output, stdout) — stdout để kiểm cảnh báo modifier sai."""
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


out, _ = compile_sao("""<blade>
  <form @submit.prevent(save())>
    <button @click.stop.once(go())>a</button>
    <div @click.self(outer())></div>
    <span @click(plain())></span>
    <i @onClick.prevent(aliased())></i>
  </form>
</blade>
""")

print('emit modifier')
check('prevent', 'eventModifiers: { submit: ["prevent"] }' in out)
check('nhiều modifier giữ thứ tự', 'eventModifiers: { click: ["stop", "once"] }' in out)
check('self', 'eventModifiers: { click: ["self"] }' in out)
check('alias @onClick.prevent', 'eventModifiers: { click: ["prevent"] }' in out)

print('tương thích ngược')
check('handler vẫn ở events', '{"handler":"save","params":[]}' in out)
check('không modifier → KHÔNG có key eventModifiers',
      'events: { click: [{"handler":"plain","params":[]}] } }' in out
      or 'events: { click: [{"handler":"plain","params":[]}] }' in out)
check('số bucket eventModifiers đúng bằng số element có modifier',
      out.count('eventModifiers:') == 4, str(out.count('eventModifiers:')))

print('modifier sai tên')
bad_out, bad_log = compile_sao('<blade><a @click.prevet(typo())>x</a></blade>\n')
check('cảnh báo ra stdout', 'prevet' in bad_log and 'không hợp lệ' in bad_log)
check('KHÔNG emit modifier sai', 'prevet' not in bad_out)
check('handler vẫn được đăng ký', '{"handler":"typo","params":[]}' in bad_out)

print('\n{} passed, {} failed'.format(passed, failed))
sys.exit(1 if failed else 0)
