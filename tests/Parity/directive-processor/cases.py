#!/usr/bin/env python3
import json
import os
import sys

SYNTHETIC = [
    "@auth", "@guest", "@endauth", "@endguest", "@can('edit')", "@cannot(\"delete\")", "@endcan", "@endcannot",
    "@csrf", "@method('put')", "@error('email')", "@enderror", "@hasSection('hero')", "@endhassection",
    "@empty($items)", "@isset($user)", "@unless($ready)", "@endunless", "@php", "@endphp",
    "@json(['test' => true])", "@json(['test' => $state, 'other' => \"$name\"])", "@json($value)",
    "@lang('hello.world')", "@choice('apples', 2)", "@exec($a = 1, fn($a, 2))", "@out($a + $b)",
    "@out('$ignored' . \"$used\")", "@wrapper('main')", "@view('section', attrs = ['x' => 1])", "@wrapper", "@endwrapper",
    "@let($a = 1; $b = 2)", "@const($a = fn(1, 2))", "@useState(0, $count, $setCount)",
    "@useState(fn(1, 2), $value, $setValue)", "plain text", "@json(", "@method($dynamic)", "@choice('x', $count)",
]


def root():
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, "builder", ".reference", "python", "src")):
            return path
    raise RuntimeError("Không tìm thấy root")


for index, source in enumerate(SYNTHETIC, 1):
    print(json.dumps({"name": f"synthetic-{index:02d}", "source": source}, ensure_ascii=False))

repo = root()
files = []
for base in [os.path.join(repo, "saola", "resources"), os.path.join(repo, "php-compiler", "tests", "Parity", "source-split", "fixtures")]:
    for directory, _, names in os.walk(base):
        for name in names:
            if name.endswith(".sao"): files.append(os.path.join(directory, name))
count = 0
for file in sorted(files):
    with open(file, encoding="utf-8-sig") as handle:
        for lineno, source in enumerate(handle, 1):
            source = source.strip()
            if source.startswith("@"):
                count += 1
                print(json.dumps({"name": f"{os.path.relpath(file, repo)}:{lineno}", "source": source}, ensure_ascii=False))
print(f"  {len(SYNTHETIC)} ca tổng hợp + {count} dòng directive thật", file=sys.stderr)
