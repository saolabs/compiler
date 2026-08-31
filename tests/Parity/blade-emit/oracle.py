#!/usr/bin/env python3
"""Oracle: chạy sao2blade của compiler Python.

stdin  : <đường dẫn><TAB><JSON chuỗi blade đã ráp>   (đúng dạng corpus)
stdout : <đường dẫn><TAB><JSON output .blade.php>
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
    raise RuntimeError('Không tìm thấy repo root')


ROOT = _repo_root()
sys.path.insert(0, os.path.join(ROOT, 'builder', '.reference', 'python', 'src'))
sys.path.insert(0, os.path.join(ROOT, 'builder', '.reference', 'python', 'src', 'sao2blade'))

from blade_compiler import BladeTemplateCompiler  # noqa: E402


def main() -> int:
    rows = []

    # Compiler in cảnh báo ra stdout; chặn để output chỉ còn kết quả diff được.
    with contextlib.redirect_stdout(sys.stderr):
        for line in sys.stdin:
            line = line.rstrip('\n')
            if not line:
                continue

            rel, raw = line.split('\t', 1)
            content = json.loads(raw)

            try:
                out = BladeTemplateCompiler().compile(content)
            except Exception as exc:  # noqa: BLE001
                out = f'__ERROR__ {type(exc).__name__}: {exc}'

            rows.append((rel, json.dumps(out, ensure_ascii=False)))

    for rel, out in rows:
        print(f'{rel}\t{out}')

    return 0


if __name__ == '__main__':
    sys.exit(main())
