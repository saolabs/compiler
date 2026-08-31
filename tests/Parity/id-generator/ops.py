#!/usr/bin/env python3
"""Sinh dãy thao tác ngẫu nhiên cho HydrateIdGenerator.

Mỗi dòng là một JSON `{"op": ..., "args": [...]}`. Cả oracle Python lẫn subject
PHP chạy đúng dãy này rồi in giá trị trả về của từng thao tác; diff phải rỗng.

Dãy ngẫu nhiên tốt hơn assert viết tay ở chỗ nó chạm tới các tổ hợp push/pop
lồng nhau mà người viết test khó nghĩ ra — mà đó lại chính là nơi bộ đếm theo
scope dễ lệch.
"""
import json
import random
import sys

TAGS = ['div', 'span', 'p', 'ul', 'li', 'a', 'h1', 'code', 'button', 'img']
REACTIVE = ['if', 'switch', 'foreach', 'for', 'while']
BLOCKS = ['content', 'main', 'side_bar', 'workspace']


def generate(count: int, seed: int) -> list[dict]:
    rng = random.Random(seed)
    ops: list[dict] = []
    depth = 1  # scope gốc

    for _ in range(count):
        roll = rng.random()

        if roll < 0.16:
            ops.append({'op': 'nextElement', 'args': [rng.choice(TAGS)]})
        elif roll < 0.30:
            ops.append({'op': 'pushElement', 'args': [rng.choice(TAGS)]})
            depth += 1
        elif roll < 0.40:
            ops.append({'op': 'pushReactive', 'args': [rng.choice(REACTIVE)]})
            depth += 1
        elif roll < 0.47:
            ops.append({'op': 'pushCase', 'args': [rng.randint(1, 12)]})
            depth += 1
        elif roll < 0.53:
            blade = rng.choice(['$loop->index', '$i', None])
            ops.append({'op': 'pushLoopIteration', 'args': ['__loopIndex', blade]})
            depth += 1
        elif roll < 0.60:
            ops.append({'op': 'nextOutput', 'args': []})
        elif roll < 0.65:
            ops.append({'op': 'nextComponent', 'args': []})
        elif roll < 0.69:
            ops.append({'op': 'pushComponent', 'args': []})
            depth += 1
        elif roll < 0.72:
            ops.append({'op': 'nextBlockOutlet', 'args': []})
        elif roll < 0.76:
            ops.append({'op': 'nextYield', 'args': []})
        elif roll < 0.80:
            ops.append({'op': 'pushBlock', 'args': [rng.choice(BLOCKS)]})
            depth += 1
        elif roll < 0.84:
            ops.append({'op': 'depth', 'args': []})
        elif roll < 0.88:
            ops.append({'op': 'formatBladeHydrate', 'args': ['div-1-output-1']})
        elif roll < 0.91:
            ops.append({'op': 'formatJsId', 'args': ['div-1-output-1']})
        elif roll < 0.94 and depth > 8:
            # Thỉnh thoảng đặt lại — kiểm tra reset() dọn sạch mọi bộ đếm
            ops.append({'op': 'reset', 'args': []})
            depth = 1
        else:
            # Cố tình pop cả khi đã ở gốc: bản Python coi đó là no-op trả None,
            # bản PHP phải giống hệt chứ không được ném lỗi.
            ops.append({'op': 'popScope', 'args': []})
            depth = max(1, depth - 1)

    return ops


def main() -> int:
    count = int(sys.argv[1]) if len(sys.argv) > 1 else 5000
    seed = int(sys.argv[2]) if len(sys.argv) > 2 else 20260830

    for op in generate(count, seed):
        print(json.dumps(op, ensure_ascii=False, sort_keys=True))

    return 0


if __name__ == '__main__':
    sys.exit(main())
