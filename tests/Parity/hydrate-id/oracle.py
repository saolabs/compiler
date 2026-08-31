#!/usr/bin/env python3
"""Oracle: mã hoá hydrate id bằng CHÍNH compiler Python đang chạy production.

Đọc base_id từ stdin (mỗi dòng một id), in ra `mode<TAB>base_id<TAB>hash`
cho cả bốn mode. Đây là chuẩn đối chiếu — bản PHP phải khớp từng byte.
"""
import importlib
import os
import sys

def _repo_root() -> str:
    """Đi ngược lên tới thư mục chứa builder/.reference/python/src — không phụ thuộc độ sâu."""
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, 'builder', '.reference', 'python', 'src')):
            return path
    raise RuntimeError('Không tìm thấy repo root (thư mục chứa builder/.reference/python/src)')


ROOT = _repo_root()
sys.path.insert(0, os.path.join(ROOT, 'builder', '.reference', 'python', 'src'))

MODES = ('terse', 'compact', 'md5', 'raw')


def main() -> int:
    base_ids = [line.rstrip('\n') for line in sys.stdin if line.strip()]

    for mode in MODES:
        # hydrate_id đọc SAOLA_ID_MODE lúc import, nên phải nạp lại module
        # cho mỗi mode. (Chính thói quen đọc env trong module này là lý do
        # bản PHP truyền mode qua tham số — xem docs/02-public-api.md §5.)
        os.environ['SAOLA_ID_MODE'] = mode
        import common.hydrate_id as hid
        importlib.reload(hid)

        for base_id in base_ids:
            print(f'{mode}\t{base_id}\t{hid.hydrate_hash(base_id)}')

    return 0


if __name__ == '__main__':
    sys.exit(main())
