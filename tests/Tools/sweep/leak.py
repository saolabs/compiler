#!/usr/bin/env python3
"""Soát rò: mã nêu làm VÍ DỤ trong {{-- --}} / @verbatim không được thành mã thật.

    python3 leak.py

Trang tài liệu là nơi dính nặng nhất — nó tồn tại để in ra cú pháp Saola.
Xem docs/05-roadmap.md §16 và §21: bug này đã xuất hiện ở SÁU chỗ quét khác
nhau, mỗi lần vá một chỗ lại lộ chỗ kế. Chạy lại bộ này sau mỗi lần thêm khâu
quét mới.
"""
import json, os, re, subprocess, sys

PC = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
WRAP = "@states({ that: 1 })\n<template><p>{{ that }}</p></template>\n"

# Mọi cấu trúc mà compiler có quét ở đâu đó. 'gia' = mồi: không được xuất hiện
# như MÃ ở bất kỳ đầu ra nào.
CASES = {
    '<template>':      "<template><p>gia</p></template>",
    '<blade>':         "<blade><p>gia</p></blade>",
    '<sao:blade>':     "<sao:blade><p>gia</p></sao:blade>",
    '<script> mở':     "nhắc <script> thôi",
    '<script>...':     "<script>var gia=1</script>",
    '<style scoped>':  "<style scoped>.gia{color:red}</style>",
    '<link css>':      "<link rel='stylesheet' href='/gia.css'>",
    '@props':          "@props({gia: 1})",
    '@vars':           "@vars(gia = 1)",
    '@const':          "@const(GIA = 1)",
    '@states':         "@states({gia: 1})",
    '@useState':       "@useState($gia, 1)",
    '@computed':       "@computed(gia = 1)",
    '@import':         "@import(__template__ + 'u.gia')",
    '@asset':          "@asset(gia = 'a.png')",
    '@fetch':          "@fetch(gia = '/api')",
    '@await':          "@await",
    '@extends':        "@extends('gia.layout')",
    '@viewtype':       "@viewtype('gia')",
    '@section':        "@section('gia')x@endsection",
    '@block':          "@block('gia')x@endblock",
    '@wrapper':        "@wrapper<p>gia</p>@endwrapper",
    '@php':            "@php $gia=1; @endphp",
    '{{ output }}':    "{{ gia }}",
}


def compile_sao(src):
    with open('/tmp/_leak.sao', 'w', encoding='utf-8') as f:
        f.write(src)
    r = subprocess.run(
        [f'{PC}/bin/saoc', 'compile', '/tmp/_leak.sao',
         '--view-path=t.l', '--fn=V', '--factory=VF', '--json'],
        capture_output=True, text=True, cwd=PC)
    if r.returncode != 0:
        return None, (r.stderr or '').strip()[:120]
    return json.loads(r.stdout), None


def check(d):
    """Trả danh sách chỗ rò. 'gia' chỉ được phép nằm TRONG comment/verbatim."""
    js, blade = d.get('js') or '', d.get('blade') or ''
    outside = re.sub(r'\{\{--[\s\S]*?--\}\}|@verbatim[\s\S]*?@endverbatim', ' ', blade)
    bad = []
    if 'gia' in outside.lower():
        bad.append('blade')
    # trong @verbatim, nội dung ra this.text(...) là ĐÚNG — chỉ báo khi thành mã
    if re.search('gia', js, re.I) and not re.search(r"this\.text\('[^']*gia", js, re.I):
        bad.append('js')
    if d.get('css'):
        bad.append('css=%s' % d['css'])
    # @import thật nằm ngoài mồi thì không tính; ở đây không có import thật nào
    if d.get('imports'):
        bad.append('imports')
    if 'that' not in js:
        bad.append('MẤT nội dung thật')
    return bad


def main():
    fails = 0
    for wrapper_name, wrap in (('comment', '{{-- %s --}}'), ('verbatim', '@verbatim %s @endverbatim')):
        for name, snippet in CASES.items():
            d, err = compile_sao((wrap % snippet) + '\n' + WRAP)
            if err:
                print(f'  ❌ [{wrapper_name}] {name}: COMPILE {err}')
                fails += 1
                continue
            bad = check(d)
            if bad:
                print(f'  ❌ [{wrapper_name}] {name}: RÒ {", ".join(bad)}')
                fails += 1

    total = len(CASES) * 2
    print(f'\nĐã soát {total} ca — {fails} rò')
    return 1 if fails else 0


if __name__ == '__main__':
    sys.exit(main())
