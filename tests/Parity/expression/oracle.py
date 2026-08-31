#!/usr/bin/env python3
"""Oracle: chuyển biểu thức bằng chính compiler Python.

stdin  : <tên hàm><TAB><biểu thức dạng JSON>   (đúng định dạng corpus.tsv)
stdout : <tên hàm><TAB><input JSON><TAB><output JSON>

Cả hai phía chạy với tập user_methods RỖNG. Corpus không ghi lại ngữ cảnh
<script setup> của từng view, nên cố định trạng thái là cách duy nhất để phép
so sánh có nghĩa — và cả hai bên đều cố định như nhau.
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


sys.path.insert(0, os.path.join(_repo_root(), 'builder', '.reference', 'python', 'src'))

from common.php_converter import php_to_js                      # noqa: E402
from common.php_js_converter import php_to_js_advanced          # noqa: E402
from common.php_js_converter import set_user_methods            # noqa: E402

FUNCS = {
    'php_to_js': php_to_js,
    'php_to_js_advanced': php_to_js_advanced,
}


def main() -> int:
    set_user_methods(None, 'parity.view')

    rows: list[tuple[str, str, str]] = []

    # Converter in cảnh báo thẳng ra stdout (`[sao2js] Cảnh báo: ...`). Nếu
    # không chặn, chúng lọt vào giữa output và làm hỏng phép diff.
    with contextlib.redirect_stdout(sys.stderr):
        for line in sys.stdin:
            line = line.rstrip('\n')
            if not line:
                continue

            func_name, raw = line.split('\t', 1)
            expr = json.loads(raw)

            try:
                result = FUNCS[func_name](expr)
            except Exception as exc:  # noqa: BLE001
                result = f'<<ERROR {type(exc).__name__}>>'

            rows.append((func_name, raw, json.dumps(result, ensure_ascii=False)))

    for func_name, raw, result in rows:
        print(f'{func_name}\t{raw}\t{result}')

    return 0


if __name__ == '__main__':
    sys.exit(main())
