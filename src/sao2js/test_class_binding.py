"""Self-check cho ClassBindingHandler: `{}` phải cho ra Y HỆT `[]`.

Handler này chạy khi preprocessor KHÔNG chạy (file bọc <blade>). Trước đây nó chỉ
nhận `[...]`, nên `@class({'a', 'b': cond})` rơi vào nhánh fallback và ra thẳng
`class="{'a', 'b': $status}"` — không exception, không warning, HTML rác.
Phải khớp với template_ast.py::_split_class_entry (nhánh AST parse cùng file .sao).

Chạy: python3 src/sao2js/test_class_binding.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from class_binding_handler import ClassBindingHandler


def demo():
    h = ClassBindingHandler({'status', 'mode'})

    # 4 tổ hợp [] / {} x => / : phải ra cùng một output.
    forms = [
        "@class(['a', 'b' => $status])",
        "@class({'a', 'b' => $status})",
        "@class(['a', 'b': $status])",
        "@class({'a', 'b': $status})",
    ]
    outs = [h.process_class_directive(f) for f in forms]
    assert len(set(outs)) == 1, outs
    assert '__classBinding' in outs[0], outs[0]
    assert '{type: "static", value: "a"}' in outs[0], outs[0]
    assert 'value: "b"' in outs[0] and 'states: ["status"]' in outs[0], outs[0]

    # Toàn static → không dùng __classBinding, ra thẳng attribute.
    assert h.process_class_directive("@class(['a', 'b'])") == 'class="a b"'
    assert h.process_class_directive("@class({'a', 'b'})") == 'class="a b"'

    # Ternary trong value: dấu ':' của ternary KHÔNG được coi là separator.
    tern = h.process_class_directive("@class({'a': $status ? 1 : 2})")
    assert 'value: "a"' in tern, tern
    assert '__classBinding' in tern, tern

    # '::' (hằng lớp PHP) không bị cắt làm separator.
    dbl = h.process_class_directive("@class(['a' => Mode::ON])")
    assert 'value: "a"' in dbl, dbl

    # Back-compat: key là biểu thức PHP trước '=>' vẫn đi nhánh cũ.
    expr_key = h.process_class_directive("@class([$mode => $status])")
    assert 'value: "$mode"' in expr_key, expr_key

    # Dạng 1 tham số và 2 tham số không đổi.
    assert h.process_class_directive("@class('solo')") == 'class="solo"'
    two = h.process_class_directive("@class('b', $status)")
    assert 'value: "b"' in two and 'states: ["status"]' in two, two

    print('test_class_binding: OK')


if __name__ == '__main__':
    demo()
