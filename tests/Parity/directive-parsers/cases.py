#!/usr/bin/env python3
"""Corpus for sao2js/parsers.py public directive methods."""

import json
import os


SYNTHETIC = [
    "@extends('layouts.app')",
    "@extends($theme.'.layout', ['title' => $title])",
    "@vars($a = 0, $items = ['x', 'y'], $url = 'http://x')",
    "@vars({a = 1, b, c = fn(1, 2)})",
    "@props(['name' => 'Saola', 'count' => 0, 'plain'])",
    "@props({name: 'Saola', count: total, plain})",
    "@let($a = 1, $b = fn(1, [2, 3]))\n@let([$x, $y] = pair())",
    "@const($a = 1, $b = ['x' => 2])\n@const([$x, $y] = pair())",
    "@useState(0, $count, $setCount)\n@useState(['a' => 1], $data, $setData)",
    "@states(['count' => 0, 'name' => 'x'])",
    "@states({ count: 0, url: 'http://x', plain })",
    "@states($count = 0, $name = 'x', $plain)",
    "@fetch('/api/items')",
    "@fetch(route('api.items'), ['page' => 1], ['Accept' => 'json'])",
    "@fetch(['url' => '/api', 'method' => 'POST'])",
    "@onInit(console.log('x'))",
    "@onInit(<style>.x { color:red }</style><script>boot()</script>)",
    "@viewType('layout')\n@viewtype(component)",
    "@block('main')\nbody\n@endBlock\n@useBlock('main', 'fallback')",
    "@onBlock(['#children' => 'document.body', 'title' => 'block-title'])",
    "@block('broken')",
    "@endblock",
    "@verbatim @vars($bad = 1) @endverbatim\n@vars($good = 2)",
    "<script>const x = '@let($bad = 1)'</script>\n@let($good = 2)",
]


def root() -> str:
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, "builder", ".reference", "python", "src")):
            return path
    raise RuntimeError("Không tìm thấy repo root")


def main() -> int:
    for index, source in enumerate(SYNTHETIC, 1):
        print(json.dumps({"name": f"synthetic-{index:02d}", "source": source}, ensure_ascii=False))
    repo = root()
    files = []
    for base in [os.path.join(repo, "saola", "resources"), os.path.join(repo, "php-compiler", "tests", "Parity", "source-split", "fixtures")]:
        for directory, _, names in os.walk(base):
            for name in names:
                if name.endswith('.sao'):
                    files.append(os.path.join(directory, name))
    for path in sorted(files):
        with open(path, encoding='utf-8-sig') as handle:
            source = handle.read()
        print(json.dumps({"name": os.path.relpath(path, repo), "source": source}, ensure_ascii=False))
    print(f"  {len(SYNTHETIC)} ca tổng hợp + {len(files)} file thật/fixture", file=__import__('sys').stderr)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
