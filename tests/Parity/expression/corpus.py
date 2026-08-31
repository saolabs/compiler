#!/usr/bin/env python3
"""Bóc corpus biểu thức THẬT bằng cách gài spy vào compiler Python.

Cùng thủ thuật với SAOLA_ID_MODE=raw ở cổng hydrate-id: thay vì đoán xem
php_to_js() nhận vào cái gì, ta bọc chính hàm đó rồi compile cả 56 view và ghi
lại mọi input nó thực sự thấy.

Kết quả là phân bố input THẬT của app, không phải test bịa — kể cả các biểu
thức mà người viết test không nghĩ ra.

    tests/Parity/expression/corpus.py > corpus.tsv

Mỗi dòng: <tên hàm><TAB><biểu thức đã escape>
"""
import contextlib
import json
import os
import sys


def _repo_root() -> str:
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, 'builder', '.reference', 'python', 'src')):
            return path
    raise RuntimeError('Không tìm thấy repo root (thư mục chứa builder/.reference/python/src)')


ROOT = _repo_root()
SRC = os.path.join(ROOT, 'builder', '.reference', 'python', 'src')
sys.path.insert(0, SRC)
sys.path.insert(0, os.path.join(SRC, 'sao2js'))

seen: set[tuple[str, str]] = set()


def install_spies() -> None:
    """Bọc các hàm chuyển biểu thức TRƯỚC khi module nào import chúng.

    `from X import f` sao chép tham chiếu, nên phải vá thuộc tính module trước
    khi bất kỳ ai import — vá sau là các module đã giữ bản gốc rồi.
    """
    import common.php_js_converter as pjc

    original_advanced = pjc.php_to_js_advanced

    def spy_advanced(expr, *args, **kwargs):
        seen.add(('php_to_js_advanced', expr if isinstance(expr, str) else ''))
        return original_advanced(expr, *args, **kwargs)

    pjc.php_to_js_advanced = spy_advanced

    import common.php_converter as pc

    original_to_js = pc.php_to_js

    def spy_to_js(expr, *args, **kwargs):
        seen.add(('php_to_js', expr if isinstance(expr, str) else ''))
        return original_to_js(expr, *args, **kwargs)

    pc.php_to_js = spy_to_js


def compile_all() -> int:
    from main_compiler import BladeCompiler

    sao_root = os.path.join(ROOT, 'saola', 'resources')
    files = [
        os.path.join(root, name)
        for root, _, names in os.walk(sao_root)
        for name in names
        if name.endswith('.sao')
    ]

    ok = 0
    for path in files:
        try:
            with open(path, encoding='utf-8') as f:
                source = f.read()
            # Instance mới mỗi view: converter là singleton module-level nên
            # trạng thái rò giữa các view (xem FIX(F3) trong php_js_converter).
            BladeCompiler().compile_blade_to_js(source, 'parity.view', 'View', 'View')
            ok += 1
        except Exception:  # noqa: BLE001 — view lỗi vẫn đã kịp nhả biểu thức
            pass

    print(f'  compile: {ok}/{len(files)} view', file=sys.stderr)
    return len(files)


def main() -> int:
    print('Bóc corpus biểu thức...', file=sys.stderr)
    install_spies()

    # Compiler in cảnh báo thẳng ra stdout (print, không phải logger). Đẩy hết
    # sang stderr để stdout chỉ còn corpus sạch, diff được.
    with contextlib.redirect_stdout(sys.stderr):
        compile_all()

    rows = sorted(seen)
    for func, expr in rows:
        if expr == '':
            continue
        # JSON escape: biểu thức có xuống dòng, tab, nháy — phải giữ nguyên byte
        print(f'{func}\t{json.dumps(expr, ensure_ascii=False)}')

    print(f'  biểu thức: {len(rows)} duy nhất', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main())
