#!/usr/bin/env python3
import contextlib
import json
import os
import sys


METHODS = [
    'parse_extends', 'parse_vars', 'parse_props', 'parse_let_directives',
    'parse_const_directives', 'parse_usestate_directives', 'parse_states_directives',
    'parse_fetch', 'parse_init', 'parse_view_type', 'parse_block_directives',
    'parse_endblock_directives', 'parse_useblock_directives', 'parse_onblock_directives',
]


def root() -> str:
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, 'builder', '.reference', 'python', 'src')):
            return path
    raise RuntimeError('Không tìm thấy repo root')


repo = root()
sys.path.insert(0, os.path.join(repo, 'builder', '.reference', 'python', 'src'))
sys.path.insert(0, os.path.join(repo, 'builder', '.reference', 'python', 'src', 'sao2js'))
from parsers import DirectiveParsers  # noqa: E402


def main() -> int:
    parser = DirectiveParsers()
    for line in sys.stdin:
        if not line.strip():
            continue
        case = json.loads(line)
        results = {}
        for method in METHODS:
            with contextlib.redirect_stdout(sys.stderr):
                try:
                    results[method] = getattr(parser, method)(case['source'])
                except Exception as exc:  # noqa: BLE001
                    results[method] = {'error': type(exc).__name__, 'message': str(exc)}
        print(json.dumps({'name': case['name'], 'results': results}, ensure_ascii=False, sort_keys=True, separators=(',', ':')))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
