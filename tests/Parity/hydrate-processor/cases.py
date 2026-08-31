#!/usr/bin/env python3
"""Dựng input cho BladeHydrateProcessor từ ca tổng hợp và pipeline thật."""

import contextlib
import json
import os
import sys


def repo_root() -> str:
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, "builder", ".reference", "python", "src")):
            return path
    raise RuntimeError("Không tìm thấy repo root")


SYNTHETIC = [
    ("<div>Hello</div>", [], "", False),
    ("<div>{{ count }}</div>", ["count"], "", False),
    ("<p>{!! html !!}</p>", ["html"], "", False),
    ("{{-- {{ count }} --}}\n<span>{{ other }}</span>", ["count"], "", False),
    ("<input disabled class=\"field active\">", [], "s123", False),
    ('<div class="language-{{ lang }} card" title="{{ title }}" @click(go()) @class([\'hot\' => $hot])></div>', ["lang", "title", "hot"], "scope", False),
    ("@if($ready)\n  <b>{{ count }}</b>\n@elseif($waiting)\n  wait\n@else\n  no\n@endif", ["ready", "waiting", "count"], "", False),
    ("@foreach($items as $item)\n@key(item.id)\n<div>{{ item.name }}</div>\n@endforeach", ["items"], "", False),
    ("@foreach($items as $item)\n<div>@foreach($item->children as $child)\n<span>{{ $child }}</span>\n@endforeach</div>\n@endforeach", ["items"], "", False),
    ("@while($i < 3)\n<i>{{ $i }}</i>\n@endwhile", [], "", False),
    ("@for($i = 0; $i < $count; $i++)\n@key($i)\n<br>\n@endfor", ["count"], "", False),
    ("@switch($status)\n@case('ok')\n<div>ok</div>\n@break\n@default\n<div>no</div>\n@endswitch", ["status"], "", False),
    ("@include('child', ['value' => $count])\n@yield('content')\n@useBlock('shell')", ["count"], "", True),
    ("@importInclude(card, 'card')\n<section>slot</section>\n@endImportInclude", [], "", False),
    ("@block('main')\n<div><span>x</span></div>\n@endblock", [], "", False),
    ("@ssr\n<div>{{ count }}</div>\n@endssr\n<p>{{ count }}</p>", ["count"], "", False),
    ("<div title='Xin chào' data-x='0' required></div>", [], "lớp-phạm-vi", False),
]


def emit(name: str, template: str, states, scope: str, has_extends: bool) -> None:
    print(json.dumps({
        "name": name,
        "args": [template, sorted(states), scope, has_extends],
    }, ensure_ascii=False))


def main() -> int:
    for index, args in enumerate(SYNTHETIC, start=1):
        emit(f"synthetic-{index:02d}", *args)

    root = repo_root()
    sys.path.insert(0, os.path.join(root, "builder", ".reference", "python", "src"))
    sys.path.insert(0, os.path.join(root, "builder", ".reference", "python", "src", "sao2blade"))
    import blade_compiler

    captures = []

    class CaptureProcessor:
        def __init__(self, state_variables=None, scope_class=""):
            self.states = sorted(state_variables or set())
            self.scope = scope_class or ""

        def process(self, template_content, has_extends=False):
            captures.append((template_content, self.states, self.scope, has_extends))
            return template_content

    blade_compiler.BladeHydrateProcessor = CaptureProcessor
    real = 0

    for line in sys.stdin:
        line = line.rstrip("\n")
        if not line:
            continue
        rel, raw = line.split("\t", 1)
        assembled = json.loads(raw)
        if assembled.startswith("__CORPUS_ERROR__"):
            continue

        captures.clear()
        with contextlib.redirect_stdout(sys.stderr):
            blade_compiler.BladeTemplateCompiler().compile(assembled)
        if len(captures) != 1:
            raise RuntimeError(f"{rel}: expected 1 hydrate call, got {len(captures)}")
        emit(rel, *captures[0])
        real += 1

    print(f"  {len(SYNTHETIC)} ca tổng hợp + {real} pipeline thật", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
