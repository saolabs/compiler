"""Scoped CSS ghép ở tầng biên dịch — selector phải đúng, at-rule phải chừa ra.

Chạy: python3 -m pytest src/common/test_scoped_style.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from common.scoped_style import extract_scoped_css, scope_class_for, scope_css

S = 'sX'


def test_ghep_vao_compound_cuoi():
    assert scope_css('.demo-page { a: 1 }', S) == '.demo-page.sX { a: 1 }'
    assert scope_css('.a .b { a: 1 }', S) == '.a .b.sX { a: 1 }'
    assert scope_css('.a > .b + .c { a: 1 }', S) == '.a > .b + .c.sX { a: 1 }'


def test_danh_sach_selector_ghep_tung_cai():
    assert scope_css('.a, .b { x: 1 }', S) == '.a.sX, .b.sX { x: 1 }'
    assert scope_css('button, select { x: 1 }', S) == 'button.sX, select.sX { x: 1 }'


def test_pseudo_van_o_cuoi():
    assert scope_css('.c:hover { x: 1 }', S) == '.c.sX:hover { x: 1 }'
    assert scope_css('.c::before { x: 1 }', S) == '.c.sX::before { x: 1 }'
    assert scope_css('article:nth-child(2n) { x: 1 }', S) == 'article.sX:nth-child(2n) { x: 1 }'


def test_media_thi_di_vao_trong():
    out = scope_css('@media (max-width: 680px) { .a { x: 1 } }', S)
    assert '.a.sX' in out and '@media (max-width: 680px)' in out


def test_keyframes_khong_bi_dung_toi():
    # 'from'/'to'/'50%' không phải selector — ghép class vào là hỏng animation.
    css = '@keyframes spin { from { x: 0 } to { x: 1 } }'
    assert scope_css(css, S) == css


def test_khong_co_scope_thi_giu_nguyen():
    assert scope_css('.a { x: 1 }', '') == '.a { x: 1 }'


def test_chi_lay_style_co_scoped():
    got = extract_scoped_css('<style scoped>.a{}</style><style>.b{}</style>')
    assert got == ['.a{}'], got


def test_scope_class_on_dinh_va_theo_noi_dung():
    assert scope_class_for(['.a{}']) == scope_class_for(['.a{}'])
    assert scope_class_for(['.a{}']) != scope_class_for(['.b{}'])
    assert scope_class_for([]) == ''


if __name__ == '__main__':
    for name, fn in sorted(globals().items()):
        if name.startswith('test_'):
            fn()
    print('test_scoped_style: OK')
