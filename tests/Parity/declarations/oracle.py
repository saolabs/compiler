#!/usr/bin/env python3
"""Oracle: chạy DeclarationTracker của compiler Python.

stdin  : mỗi dòng một đường dẫn .sao
stdout : <đường dẫn><TAB><JSON danh sách khai báo>
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

from common.declaration_tracker import DeclarationTracker  # noqa: E402


def main() -> int:
    rows = []

    with contextlib.redirect_stdout(sys.stderr):
        for line in sys.stdin:
            file = line.strip()
            if not file:
                continue

            try:
                with open(file, encoding='utf-8') as f:
                    content = f.read()
                # Instance MỚI mỗi file — tracker giữ trạng thái giữa các lần gọi
                decls = DeclarationTracker().parse_all_declarations(content)
                # Bỏ 'position' — xem giải thích trong run.sh
                payload = [{k: v for k, v in d.items() if k != 'position'} for d in decls]
            except Exception as exc:  # noqa: BLE001
                payload = {'__error__': type(exc).__name__}

            rows.append((os.path.relpath(file, ROOT),
                         json.dumps(payload, ensure_ascii=False, separators=(',', ':'))))

    for rel, payload in rows:
        print(f'{rel}\t{payload}')

    return 0


if __name__ == '__main__':
    sys.exit(main())
