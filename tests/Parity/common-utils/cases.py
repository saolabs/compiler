#!/usr/bin/env python3
"""Đầu vào cho cổng ScopedStyle + ChildrenSlot.

Mỗi dòng: {"fn": "<tên>", "args": [...]}

Chú ý ca CSS có tiếng Việt: hash djb2 lặp theo CODEPOINT bên Python. Nếu bản
PHP lặp theo byte thì scope class lệch, và scope class lệch nghĩa là TOÀN BỘ
CSS scoped của view đó chết.
"""
import json
import sys

CSS = [
    ".a { color: red }",
    ".a .b { color: red }",
    ".a, .b { color: red }",
    ".a:hover { color: red }",
    ".a::before { content: '' }",
    ".a > .b + .c ~ .d { color: red }",
    "@media (min-width: 100px) { .a { color: red } }",
    "@keyframes k { from { opacity: 0 } to { opacity: 1 } }",
    "@font-face { font-family: x }",
    "@supports (display: grid) { .a { color: red } }",
    "@import url('x.css');",
    ".a[data-x='y'] { color: red }",
    ".a:not(.b, .c) { color: red }",
    "",
    "  ",
    ".a { }",
    "@media screen { @media print { .a { color: red } } }",
    ".a\\:b { color: red }",
    # ── tiếng Việt: ca then chốt cho hash theo codepoint ──
    ".a::after { content: 'Xin chào' }",
    ".tên-lớp { color: red }",
    "/* chú thích tiếng Việt có dấu */ .a { color: red }",
    ".a { font-family: 'Đường Kẻ' }",
    # ── emoji (ngoài BMP) ──
    ".a::before { content: '🎉' }",
]

TEMPLATES = [
    "<div>@children</div>",
    "<div>{{ $children }}</div>",
    "<div>{!! $children !!}</div>",
    "<div>@children()</div>",
    "<div>@children ( )</div>",
    "<div>@CHILDREN</div>",
    "<div>không có gì</div>",
    "<div>@children</div><div>@children</div>",
    "@verbatim <code>@children</code> @endverbatim",
    "@verbatim @children @endverbatim <div>@children</div>",
    "@verbatim @children @endverbatim @verbatim @children @endverbatim",
    "<div>@childrenXyz</div>",
    "<div>@children</div>\n@verbatim\n{{ $children }}\n@endverbatim",
    "<p>Nội dung tiếng Việt @children ở giữa</p>",
    "<div>{{ $children }}</div><div>@children</div>",
]

EXPRESSIONS = ["$children", " $children ", "$children2", "children", "$other", ""]

IMPORTS = [
    "@import('a')",
    '@import("b.d")',
    "@import($__template__.'sessions.tasks')",
    "@import($__layout__.'base' as baseLayout)",
    "@import($__blade_custom_path__)",
    "@import($__blade_custom_path__ as alert)",
    "@import($x as $y)",
    "@import(['counter' => 'a.b', 'demo' => $__template__.'c'])",
    '@import({ counter: "a.b", demo: \'c\' })',
    "@import([\n  'a' => 'x',  // chú thích\n  'b' => 'y',\n])",
    "@import(['a' => 'x'] ) {{-- chú thích sau --}}\n<div>x</div>",
    "@import('a')\n@import('b')\n<div>hai import</div>",
    "@verbatim @import('trong-verbatim') @endverbatim @import('that')",
    "@verbatim\n  @import('tiếng Việt có dấu')\n@endverbatim\n@import('sau')",
    "@import(  )",
    "@import(",
    "không có import nào",
    "@IMPORT('hoa')",
    "@import('a.b.c.d')",
    "@import(['x' => 'p', 'x' => 'q'])",
    "@import('có-gạch-nối')",
    "@import('tên_có_gạch_dưới')",
]


STRUCTURES = [
    "<counter></counter>",
    "<counter />",
    "<counter/>",
    "<counter >",
    "</counter>",
    "<counter><counter></counter></counter>",
    "<counter><other></counter>",
    "<counter></other>",
    "<counter><demo></counter></demo>",
    "<div>Tiếng Việt có dấu ở đây rất dài</div>\n<counter>",
    "dòng 1\ndòng 2 tiếng Việt\n<counter>",
    "<script><counter></script><counter/>",
    "<style><counter></style>",
    "<textarea><counter></textarea>",
    "<title><counter></title>",
    "{{-- <counter> --}}<counter/>",
    "<!-- <counter> --><counter/>",
    "{{-- chưa đóng comment <counter>",
    "<!-- chưa đóng <counter>",
    "<counter attr='a>b'></counter>",
    '<counter attr="a>b"></counter>',
    "<counter attr='a\\'b'></counter>",
    "< counter></counter>",
    "<3invalid>",
    "<counter",
    "",
    "không có thẻ nào",
    "<demo/>",
]


def main() -> int:
    for css in CSS:
        print(json.dumps({'fn': 'scope_class_for', 'args': [[css]]}, ensure_ascii=False))
        print(json.dumps({'fn': 'scope_css', 'args': [css, 's123']}, ensure_ascii=False))
        print(json.dumps({'fn': 'extract_scoped_css',
                          'args': [f'<style scoped>{css}</style><style>{css}</style>']},
                         ensure_ascii=False))

    # §16: `<style scoped>` in ra làm VÍ DỤ trong comment / @verbatim không phải
    # CSS thật. Bỏ sót còn nguy hơn lọt CSS: scope class suy từ chính nội dung
    # CSS, nên lọt một block giả là đổi class của MỌI element trong view.
    for wrapper in ('{{-- %s --}}', '@verbatim %s @endverbatim'):
        print(json.dumps({'fn': 'extract_scoped_css',
                          'args': [wrapper % '<style scoped>.gia{color:red}</style>']},
                         ensure_ascii=False))
        print(json.dumps({'fn': 'extract_scoped_css',
                          'args': [(wrapper % '<style scoped>.gia{}</style>')
                                   + '<style scoped>.that{}</style>']},
                         ensure_ascii=False))

    # Nhiều block gộp lại — hash phải tính trên chuỗi đã nối
    print(json.dumps({'fn': 'scope_class_for', 'args': [CSS[:3]]}, ensure_ascii=False))
    print(json.dumps({'fn': 'scope_class_for', 'args': [[]]}, ensure_ascii=False))

    for tpl in TEMPLATES:
        print(json.dumps({'fn': 'count_children_placeholders', 'args': [tpl]}, ensure_ascii=False))
        print(json.dumps({'fn': 'replace_children_for_blade', 'args': [tpl]}, ensure_ascii=False))
        print(json.dumps({'fn': 'replace_children_for_legacy_js', 'args': [tpl]}, ensure_ascii=False))
        print(json.dumps({'fn': 'validate_children_placeholders', 'args': [tpl]}, ensure_ascii=False))

    for expr in EXPRESSIONS:
        print(json.dumps({'fn': 'is_children_expression', 'args': [expr]}, ensure_ascii=False))

    for code in IMPORTS:
        print(json.dumps({'fn': 'parse_imports', 'args': [code]}, ensure_ascii=False))
        print(json.dumps({'fn': 'remove_imports', 'args': [code]}, ensure_ascii=False))

    for path in ["'a'", '"b.d"', "$__template__.'x.y'", "$__custom__", "a-b", "", "''", "$x.'a.'"]:
        print(json.dumps({'fn': 'extract_tag_from_path', 'args': [path]}, ensure_ascii=False))

    for src in STRUCTURES:
        print(json.dumps({'fn': 'validate_imported_tag_structure',
                          'args': [src, {'counter': 'a.b', 'demo': 'c.d'}]}, ensure_ascii=False))
        print(json.dumps({'fn': 'validate_imported_tag_structure',
                          'args': [src, {}]}, ensure_ascii=False))

    print(f'  {len(CSS) * 3 + 2 + len(TEMPLATES) * 4 + len(EXPRESSIONS) + len(IMPORTS) * 2 + 8 + len(STRUCTURES) * 2} phép gọi', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main())
