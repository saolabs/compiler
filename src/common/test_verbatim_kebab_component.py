"""Khoá ba lỗi sinh mã của component tag kebab-case có thân @verbatim.

Cả ba đều sinh ra file KHÔNG parse được (Vite 500 / Blade ParseError), nên
test đi thẳng vào output của hai CLI thay vì mock từng hàm.
"""
import os, re, subprocess, sys, tempfile

SRC = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

FIXTURE = """<template>
    <div class="wrap">
        <code-window label="PHP" lang="php">
@verbatim
System::context('admin', [
    'prefix' => 'admin',
]);
@endverbatim
        </code-window>
    </div>
</template>
"""

COMPONENT = """@props({ lang: 'none' })
<template>
    <pre><code class="language-{{ lang }}">@children</code></pre>
</template>
"""


def _compile(kind, src_file, out_file):
    cli = os.path.join(SRC, kind, 'cli.py')
    r = subprocess.run(
        [sys.executable, cli, src_file, out_file, 'Probe', 'web.probe', 'Probe'],
        cwd=os.path.join(SRC, kind), capture_output=True, text=True,
    )
    assert os.path.exists(out_file), f'{kind} không sinh output: {r.stderr[:400]}'
    with open(out_file, encoding='utf-8') as f:
        return f.read()


def _build(tmp):
    comp_dir = os.path.join(tmp, 'components')
    os.makedirs(comp_dir, exist_ok=True)
    with open(os.path.join(comp_dir, 'code-window.sao'), 'w', encoding='utf-8') as f:
        f.write(COMPONENT)
    src = os.path.join(tmp, 'probe.sao')
    with open(src, 'w', encoding='utf-8') as f:
        f.write("@import('components.code-window')\n" + FIXTURE)
    return src


def test_verbatim_khong_vo_chuoi_nhay_don():
    """Thân @verbatim rơi vào this.text('...') — nháy đơn và xuống dòng phải escape."""
    with tempfile.TemporaryDirectory() as tmp:
        js = _compile('sao2js', _build(tmp), os.path.join(tmp, 'probe.js'))
    for lit in re.findall(r"this\.text\('((?:[^'\\]|\\.)*)'\)", js):
        assert '\n' not in lit
    # Không còn nháy đơn TRẦN (chưa escape) trong bất kỳ this.text nào
    assert not re.search(r"this\.text\('[^'\\]*'[^)]", js), 'chuỗi nháy đơn bị vỡ'


def test_tag_kebab_khong_sinh_bien_php_sai():
    """<code-window> -> $__code-window__0_content là ParseError của PHP."""
    with tempfile.TemporaryDirectory() as tmp:
        blade = _compile('sao2blade', _build(tmp), os.path.join(tmp, 'probe.blade.php'))
    assert '$__code-window__' not in blade
    for var in re.findall(r'\$__\w*[\w-]*__\d+_content', blade):
        assert '-' not in var, f'tên biến PHP không hợp lệ: {var}'


def test_class_interpolation_khong_bi_cat_theo_khoang_trang():
    """class="language-{{ lang }}" không được vỡ thành ['language-{{','$lang','}}']."""
    with tempfile.TemporaryDirectory() as tmp:
        comp = os.path.join(tmp, 'code-window.sao')
        with open(comp, 'w', encoding='utf-8') as f:
            f.write(COMPONENT)
        blade = _compile('sao2blade', comp, os.path.join(tmp, 'cw.blade.php'))
    assert "'language-{{'" not in blade
    assert re.search(r"'language-'\s*\.\s*\(", blade), blade


def test_import_trong_verbatim_khong_dang_ky_component():
    """@import trong @verbatim là văn bản mẫu, không phải directive thật."""
    sys.path[:0] = [SRC, os.path.join(SRC, 'sao2blade')]
    from common.import_parser import ImportParser
    src = ("<template>\n@verbatim\n@import(__template__ + 'demo.card')\n"
           "@endverbatim\n</template>")
    assert ImportParser().parse_imports(src) == {}


def test_import_cu_phap_js_doi_sang_php():
    """__template__ + 'x' trong .sao phải thành $__template__ . 'x' bên Blade,
    nếu không PHP đọc __template__ như hằng số không tồn tại."""
    sys.path[:0] = [SRC, os.path.join(SRC, 'sao2blade')]
    from blade_compiler import BladeTemplateCompiler
    conv = BladeTemplateCompiler()._convert_path_to_php
    assert conv("__template__ + 'demo.card'") == "$__template__ . 'demo.card'"
    assert conv("$__template__.'a'") == "$__template__.'a'"       # PHP sẵn: giữ nguyên
    assert conv("'web.components.code-window'") == "'web.components.code-window'"
    assert conv("__template__ + 'a+b'") == "$__template__ . 'a+b'"  # + trong chuỗi giữ nguyên


def test_include_path_co_gach_noi_van_la_chuoi_trong_js():
    """@include('a.code-block') không được thành phép trừ code - block bên JS."""
    sys.path[:0] = [SRC, os.path.join(SRC, 'sao2js')]
    from template_ast import TemplateASTParser
    conv = TemplateASTParser()._convert_path_to_js
    parse = TemplateASTParser()._parse_include_params
    for raw in ["'web.components.code-block'", '"web.a-b.c-d"', "'web.components.codeblock'"]:
        path_php, _ = parse(raw + ", ['lang' => 'sao']")
        assert conv(path_php) == raw, f'{raw} -> {conv(path_php)}'
    # path động cú pháp JS vẫn giữ nguyên
    path_php, _ = parse("__component__ + 'code-block'")
    assert conv(path_php) == "__component__ + 'code-block'"


if __name__ == '__main__':
    for name, fn in sorted(globals().items()):
        if name.startswith('test_'):
            fn(); print('ok', name)


def test_class_interpolation_js_sinh_dynamic_class():
    """Bản JS của class="language-{{ lang }}" phải là 1 dynamic class, không phải
    3 static class rác ('language-{{', 'lang', '}}') — Prism dò `language-*` nên
    class sai là mất hẳn highlight ở phía client."""
    with tempfile.TemporaryDirectory() as tmp:
        comp = os.path.join(tmp, 'code-window.sao')
        with open(comp, 'w', encoding='utf-8') as f:
            f.write(COMPONENT)
        js = _compile('sao2js', comp, os.path.join(tmp, 'cw.js'))
    assert 'language-{{' not in js, js
    m = re.search(r"type: 'dynamic', factory: \(\) => `language-\$\{(\w+)\}`, stateKeys: \[([^\]]*)\]", js)
    assert m, js
    assert m.group(1) == 'lang' and '"lang"' in m.group(2), m.group(0)


def test_verbatim_giu_xuong_dong_trong_js():
    """@verbatim nằm trong <pre> — đổi '\\n' thành space làm cả khối code dồn về
    một dòng ở CSR trong khi Blade vẫn xuống dòng (SSR ≠ CSR)."""
    with tempfile.TemporaryDirectory() as tmp:
        js = _compile('sao2js', _build(tmp), os.path.join(tmp, 'probe.js'))
    lits = [l for l in re.findall(r"this\.text\('((?:[^'\\]|\\.)*)'\)", js) if 'System::context' in l]
    assert lits, js
    assert '\\n' in lits[0], lits[0]


VERBATIM_CHILDREN = """<template>
    <div>
@verbatim
&lt;article&gt;
  @children
&lt;/article&gt;
@endverbatim
        @children
    </div>
</template>
"""


def test_children_trong_verbatim_khong_bi_thay():
    """@children trong @verbatim là code minh hoạ, không phải slot: thay nó làm
    trang docs in ra `{!! $__ONE_CHILDREN_CONTENT__ !!}` thay vì cú pháp .sao,
    và làm validator tưởng component có 2 children placeholder."""
    with tempfile.TemporaryDirectory() as tmp:
        src = os.path.join(tmp, 'probe.sao')
        with open(src, 'w', encoding='utf-8') as f:
            f.write(VERBATIM_CHILDREN)
        blade = _compile('sao2blade', src, os.path.join(tmp, 'probe.blade.php'))
    verbatim = re.search(r'@verbatim(.*?)@endverbatim', blade, re.DOTALL).group(1)
    assert '@children' in verbatim, verbatim
    assert '__ONE_CHILDREN_CONTENT__' not in verbatim, verbatim
    # slot thật ngoài verbatim vẫn phải được thay
    assert blade.count('{!! $__ONE_CHILDREN_CONTENT__ !!}') == 1, blade
