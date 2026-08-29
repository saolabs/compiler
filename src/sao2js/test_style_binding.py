"""Self-check cho @style ở nhánh AST.

Trước khi có _parse_style_binding, @style KHÔNG nằm trong dispatch của
template_ast: `@style({'color': c})` rơi xuống parser attribute thường và ra
`attrs: {style: true, color: true}` — hai attribute boolean rác, không binding gì.
Blade thì vẫn giữ `@style([...])` đúng ⇒ SSR khác CSR mà không báo lỗi.

Shape phải khớp ElementInterface.styles mà Html.initializeStyles đọc.

Chạy: python3 src/sao2js/test_style_binding.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import template_ast as T


def parse(expr, state_vars):
    p = T.TemplateASTParser.__new__(T.TemplateASTParser)
    p.state_variables = set(state_vars)

    class El:
        def __init__(s):
            s.styles = {}

    el = El()
    p._parse_style_binding(expr, el)
    return el.styles


def demo():
    sv = {'color', 'size', 'on'}

    # [] và {} , => và : — bốn tổ hợp phải ra như nhau.
    forms = [
        "['color' => $color]",
        "{'color' => $color}",
        "['color': $color]",
        "{'color': $color}",
    ]
    outs = [parse(f, sv) for f in forms]
    for o in outs:
        assert set(o) == {'color'}, o
        assert o['color']['js'] == 'color', o
        assert o['color']['state_vars'] == {'color'}, o

    # Nhiều property, tên có dấu '-', biểu thức ghép chuỗi.
    multi = parse("{'color': $color, 'font-size': $size . 'px'}", sv)
    assert set(multi) == {'color', 'font-size'}, multi
    assert multi['font-size']['state_vars'] == {'size'}, multi

    # Ternary trong value: dấu ':' của ternary KHÔNG phải separator.
    tern = parse("{'color': $on ? 'red' : 'blue'}", sv)
    assert set(tern) == {'color'}, tern
    assert 'red' in tern['color']['js'] and 'blue' in tern['color']['js'], tern

    # Hằng chuỗi → không có state var (generator phát type 'static').
    const = parse("{'z-index': '3'}", sv)
    assert const['z-index']['state_vars'] == set(), const

    print('test_style_binding: OK')


if __name__ == '__main__':
    demo()
