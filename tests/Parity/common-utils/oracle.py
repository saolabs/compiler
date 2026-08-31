#!/usr/bin/env python3
"""Oracle: chạy scoped_style + children_slot của compiler Python."""
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


sys.path.insert(0, os.path.join(_repo_root(), 'builder', '.reference', 'python', 'src'))

from common.scoped_style import extract_scoped_css, scope_class_for, scope_css  # noqa: E402
from common import children_slot as cs                                         # noqa: E402
from common.import_parser import ImportParser                                   # noqa: E402
from common.template_structure import validate_imported_tag_structure           # noqa: E402

FUNCS = {
    'scope_class_for': scope_class_for,
    'scope_css': scope_css,
    'extract_scoped_css': extract_scoped_css,
    'count_children_placeholders': cs.count_children_placeholders,
    'replace_children_for_blade': cs.replace_children_for_blade,
    'replace_children_for_legacy_js': cs.replace_children_for_legacy_js,
    'validate_children_placeholders': cs.validate_children_placeholders,
    'is_children_expression': cs.is_children_expression,
    # ImportParser có trạng thái — instance MỚI cho mỗi phép gọi
    'parse_imports': lambda code: ImportParser().parse_imports(code),
    'remove_imports': lambda code: ImportParser().remove_imports(code),
    'extract_tag_from_path': lambda path: ImportParser()._extract_tag_from_path(path),
    # Trả None khi hợp lệ; ngoại lệ được oracle bắt và ghi tên lớp
    'validate_imported_tag_structure': validate_imported_tag_structure,
}


def main() -> int:
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue

        call = json.loads(line)

        try:
            value = FUNCS[call['fn']](*call['args'])
            result = {'ok': True, 'value': value}
        except Exception as exc:  # noqa: BLE001
            result = {'ok': False, 'value': type(exc).__name__}

        # separators compact để khớp json_encode của PHP (Python mặc định
        # chèn khoảng trắng sau ':' và ',')
        print(f"{line}\t{json.dumps(result, ensure_ascii=False, separators=(',', ':'))}")

    return 0


if __name__ == '__main__':
    sys.exit(main())
