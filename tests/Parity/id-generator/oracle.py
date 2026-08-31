#!/usr/bin/env python3
"""Oracle: chạy dãy thao tác bằng HydrateIdGenerator của compiler Python."""
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

from common.hydrate_id import HydrateIdGenerator  # noqa: E402

DISPATCH = {
    'nextElement':        lambda g, a: g.next_element(*a),
    'pushElement':        lambda g, a: g.push_element(*a),
    'pushReactive':       lambda g, a: g.push_reactive(*a),
    'pushCase':           lambda g, a: g.push_case(*a),
    'pushLoopIteration':  lambda g, a: g.push_loop_iteration(*a),
    'pushBlock':          lambda g, a: g.push_block(*a),
    'pushComponent':      lambda g, a: g.push_component(),
    'nextOutput':         lambda g, a: g.next_output(),
    'nextComponent':      lambda g, a: g.next_component(),
    'nextBlockOutlet':    lambda g, a: g.next_block_outlet(),
    'nextYield':          lambda g, a: g.next_yield(),
    'depth':              lambda g, a: g.get_depth(),
    'formatJsId':         lambda g, a: g.format_js_id(*a),
    'reset':              lambda g, a: g.reset(),
    'popScope':           lambda g, a: g.pop_scope(),
}


def render(value) -> str:
    """popScope trả về object scope; chỉ so tiền tố của nó là đủ và ổn định."""
    if value is None:
        return 'null'
    if isinstance(value, bool):
        return 'true' if value else 'false'
    if isinstance(value, int):
        return str(value)
    if isinstance(value, str):
        return value
    return 'scope:' + getattr(value, 'prefix', '?')


def main() -> int:
    gen = HydrateIdGenerator()

    for index, line in enumerate(sys.stdin):
        line = line.strip()
        if not line:
            continue
        op = json.loads(line)
        result = DISPATCH[op['op']](gen, op['args'])
        print(f"{index}\t{op['op']}\t{render(result)}")

    return 0


if __name__ == '__main__':
    sys.exit(main())
