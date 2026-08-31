#!/usr/bin/env python3
"""Dựng corpus base_id để đối chiếu.

Hai nguồn:
  1. THẬT   — compile mọi .sao trong repo với SAOLA_ID_MODE=raw rồi bóc id ra.
              Ở mode raw, id trong output CHÍNH LÀ base_id chưa mã hoá.
  2. TỔNG HỢP — sinh theo đúng văn phạm id, nhắm vào các ca mà 56 view thật
              không chạm tới: chỉ số >= 10, block-outlet, yield, component,
              lồng sâu, tên block có gạch dưới.
"""
import os
import random
import re
import subprocess
import sys
import tempfile

def _repo_root() -> str:
    """Đi ngược lên tới thư mục chứa builder/.reference/python/src — không phụ thuộc độ sâu."""
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, 'builder', '.reference', 'python', 'src')):
            return path
    raise RuntimeError('Không tìm thấy repo root (thư mục chứa builder/.reference/python/src)')


ROOT = _repo_root()
BLADE_CLI = os.path.join(ROOT, 'builder', '.reference', 'python', 'src', 'sao2blade', 'cli.py')
SAO_ROOT = os.path.join(ROOT, 'saola', 'resources')

# Bóc id từ @startMarker('kind', 'ID') / @endMarker(...) và @class([$X . '-ID'])
MARKER_RE = re.compile(r"@(?:start|end)Marker\(\s*'[^']*'\s*,\s*'([^']+)'")
CLASS_RE = re.compile(r"@class\(\[\s*\$__VIEW_ID__\s*\.\s*'-([^']+)'")


def harvest() -> set[str]:
    ids: set[str] = set()

    sao_files = [
        os.path.join(root, name)
        for root, _, names in os.walk(SAO_ROOT)
        for name in names
        if name.endswith('.sao')
    ]

    env = {**os.environ, 'SAOLA_ID_MODE': 'raw'}

    with tempfile.TemporaryDirectory() as tmp:
        out = os.path.join(tmp, 'out.blade.php')

        for path in sao_files:
            subprocess.run(
                [sys.executable, BLADE_CLI, path, out],
                capture_output=True, text=True, env=env,
            )
            if not os.path.exists(out):
                continue
            with open(out, encoding='utf-8') as f:
                content = f.read()
            ids.update(MARKER_RE.findall(content))
            ids.update(CLASS_RE.findall(content))
            os.remove(out)

    print(f'  thật:      {len(ids)} id từ {len(sao_files)} view', file=sys.stderr)
    return ids


TAGS = ['div', 'span', 'p', 'ul', 'li', 'a', 'h1', 'b', 'i', 'code', 'pre',
        'section', 'article', 'button', 'input', 'img', 'block']
LOOPS = ['foreach', 'for', 'while']
CONDS = ['if', 'switch']


def synthetic(count: int, seed: int = 20260830) -> set[str]:
    rng = random.Random(seed)
    ids: set[str] = set()

    # Các ca biên viết tay — chính là chỗ mã hoá terse dễ nhập nhằng nhất.
    ids.update({
        'div-1',
        'div-12',
        'block-outlet',
        'div-1-block-outlet',
        'div-1-output-1',
        'div-10-span-2',
        'div-1-span-10',
        'rc-if-12-case_3-div-1',
        'block-content-div-1',
        'block-my_block-div-1-block-outlet',
        'foreach-1-div-1',
        'yield-1',
        'component-1',
        'component-12-div-3',
        'block-workspace-div-2-div-3-rc-if-1-case_2-foreach-1',
        # Cặp từng va chạm thật khi bỏ sót block-outlet trong terse:
        'div-1-e-1',
        'div-1-block-outlet-span-1',
    })

    def segment() -> str:
        roll = rng.random()
        n = rng.choice([1, 2, 3, 9, 10, 11, 12, 99, 100])

        if roll < 0.45:
            return f'{rng.choice(TAGS)}-{n}'
        if roll < 0.60:
            return f'rc-{rng.choice(CONDS)}-{n}'
        if roll < 0.70:
            return f'{rng.choice(LOOPS)}-{n}'
        if roll < 0.78:
            return f'case_{n}'
        if roll < 0.86:
            return f'output-{n}'
        if roll < 0.92:
            return f'component-{n}'
        if roll < 0.97:
            return f'yield-{n}'
        return 'block-outlet'

    while len(ids) < count:
        depth = rng.randint(1, 7)
        parts = []
        if rng.random() < 0.25:
            parts.append('block-' + rng.choice(
                ['content', 'main', 'side_bar', 'workspace', 'a1']))
        parts.extend(segment() for _ in range(depth))
        ids.add('-'.join(parts))

    print(f'  tổng hợp:  {len(ids)} id', file=sys.stderr)
    return ids


def main() -> int:
    print('Dựng corpus base_id...', file=sys.stderr)
    ids = harvest() | synthetic(3000)

    for base_id in sorted(ids):
        print(base_id)

    print(f'  TỔNG:      {len(ids)} id duy nhất', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main())
