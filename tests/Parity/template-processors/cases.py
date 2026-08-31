#!/usr/bin/env python3
import json
import os
import re
import sys

SYNTHETIC = [
    "@yield('content')", "@yield($slot)", "x @out($a + fn($b, 2)) y",
    "@include('parts.card')", "@include('parts.card', ['x' => 1])", "@include($path)",
    "@include($path, ['x' => $x])", "<div @attr('data-x', $x)></div>",
    "<div @attr(['class' => $class, 'title' => 'hello'])></div>", "@attr()",
    "<div @wrap></div>", "@viewId", "{!! $html !!}", "{{ $name }}", "{{ ['a' => 1] }}",
    "{ $plain }", "@useState(0, $x, $setX)", "@ssr", "@SSR", "@serverside",
    "@csr", "@clientSide", "plain", "@template()", "@view()", "@wrapper()", "@endwrapper",
    "<div @yieldattr('class', 'theme')></div>",
    "<div @yieldattr('class', 'theme', 'base') @yieldattr('title', 'heading')></div>",
    "<div @yieldon('class', 'theme')></div>",
    "<div @yieldWatch('title', 'heading', 'untitled')></div>",
    "<div @yieldon(['class' => 'theme', '#content' => 'body', '#children' => 'items'])></div>",
    "@subscribe($stateKey)",
    "@subscribe($stateKey, 'class')",
    "@subscribe([$stateKey, $contentState])",
    "@subscribe(['class' => $stateKey, '#children' => [$a, $b]])",
    "@subscribe([$stateKey, $contentState], '#children')",
    "@template(tag: 'section', subscribe: [$stateKey, $user], class: 'hero')",
    "@view(['tag' => 'main', 'subscribe' => false, 'id' => 'root'])",
    "@wrap('article', ['class' => 'card', 'follow' => [$stateKey]])",
    "@wrapper(['id' => 'shell'])",
]


def root():
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, "builder", ".reference", "python", "src")):
            return path
    raise RuntimeError


for index, source in enumerate(SYNTHETIC, 1):
    print(json.dumps({"name": f"synthetic-{index:02d}", "source": source}, ensure_ascii=False))

repo = root()
pattern = re.compile(r"@(?:include|attr|yield|viewId|useState|ssr|SSR|serverside|csr|clientSide|wrap|wrapper|template|view|out)\b|\{\{|\{!!|\{\s*\$")
count = 0
for base in [os.path.join(repo, "saola", "resources"), os.path.join(repo, "php-compiler", "tests", "Parity", "source-split", "fixtures")]:
    for directory, _, names in os.walk(base):
        for name in sorted(names):
            if not name.endswith(".sao"):
                continue
            path = os.path.join(directory, name)
            with open(path, encoding="utf-8-sig") as handle:
                for lineno, source in enumerate(handle, 1):
                    source = source.rstrip("\n")
                    if pattern.search(source):
                        count += 1
                        print(json.dumps({"name": f"{os.path.relpath(path, repo)}:{lineno}", "source": source}, ensure_ascii=False))
print(f"  {len(SYNTHETIC)} ca tổng hợp + {count} dòng thật", file=sys.stderr)
