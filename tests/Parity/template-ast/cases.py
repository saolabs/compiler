#!/usr/bin/env python3
"""Corpus AST: production/fixtures plus focused parser edge cases."""

import json
import os
import re


def repo_root() -> str:
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, "builder", ".reference", "python", "src")):
            return path
    raise RuntimeError("Không tìm thấy repo root")


SYNTHETIC = [
    ("html", '<div id="app" class="one two">Xin chào {{ name }}</div>', ["name"]),
    ("echo-raw", '<p>{!! html !!} / {{ count(items) }}</p>', ["html", "items"]),
    ("if", "@if(active)\n<b>{{ label }}</b>\n@elseif(waiting)\nwait\n@else\nno\n@endif", ["active", "waiting", "label"]),
    ("inline-if", "<span>A @if(ok){{ yes }}@else{{ no }}@endif Z</span>", ["ok", "yes", "no"]),
    ("foreach", "@foreach(items as key => item)\n@key(item.id)\n<div>{{ item.name }}</div>\n@endforeach", ["items"]),
    ("legacy-foreach", "@foreach($items as $key => $item)\n{{ $item->name }}\n@endforeach", ["items"]),
    ("while", "@while($i < 5)\n@key($i)\n{{ $i }}\n@endwhile", ["i"]),
    ("for", "@for($i = 0; $i <= count; $i++)\n{{ i }}\n@endfor", ["count"]),
    ("switch", "@switch(status)\n@case('a')\nA\n@break\n@default\nB\n@endswitch", ["status"]),
    ("sections", "@section('title', title)\n@section('body')\n<div>Body</div>\n@endsection\n@yield('side', 'Default')", ["title"]),
    ("blocks", "@block('main')\nA\n@endBlock\n@useBlock('main')", []),
    ("include", "@include($__template__ . 'card', ['item' => $item])", ["item"]),
    ("import-include", "@importInclude(card, __template__ + 'card', ['item' => item])\n<span>@children</span>\n@endImportInclude", ["item"]),
    ("children-echo", "<main>{{ $children }}</main>", []),
    ("children-duplicate", "@children\n{{ $children }}", []),
    ("bindings", "<input @class(['active' => active, 'plain']) @attr({'data-id': id}) @style({'color': color}) @checked(done) @bind(name) />", ["active", "id", "color", "done", "name"]),
    ("dynamic-class", '<code class="language-{{ lang }} fixed {{ active ? \'on\' : \'\' }}"></code>', ["lang", "active"]),
    ("attrs", '<div :data-name="user.name" ::literal="x" title="Hi {{ name }}" disabled></div>', ["user", "name"]),
    ("yield-attr", '<meta content="@yield(\'description\', \'Fallback\')">', []),
    ("events", "<button @click.prevent.stop(handle(item.id, @event), count++, () => custom(item))>Go</button>", ["count", "item"]),
    ("state-setter", "<button @click(setCount(count + 1), $count++)>+</button>", ["count"]),
    ("transition", "<div @transition('fade')></div>", []),
    ("rawtext", "<script>if (a < b) { x = '{{ raw }}'; }</script><div>ok</div>", ["raw"]),
    ("rawtext-multiline", "<style>\na < b { color:red }\n</style>\n<textarea>Hi {{ name }}</textarea>", ["name"]),
    ("comments", "<!-- x --><!DOCTYPE html><p>A{{-- hidden --}}B</p>", []),
    ("void", "<img src='x'><br/><input required>", []),
    ("exec", "@exec($a = 1, call($a))\n@let($b = 2)\n@const($c = 3)", ["a", "b", "c"]),
    ("unicode", "\u00a0<section title='Tiếng Việt'>Chào 🦌 {{ tên }}</section>\u00a0", ["tên"]),
    ("malformed", "<div><span>x</div>tail</span>", []),
]


def state_names(source: str) -> list[str]:
    names = set(re.findall(r"\$([A-Za-z_]\w*)", source))
    for match in re.finditer(r"@(?:state|states|vars|props|computed)\s*\((.*?)\)", source, re.S | re.I):
        for part in match.group(1).split(","):
            lhs = part.split("=", 1)[0].strip().lstrip("$")
            if re.fullmatch(r"[A-Za-z_]\w*", lhs):
                names.add(lhs)
    return sorted(names)


def emit(name: str, template: str, states: list[str]) -> None:
    print(json.dumps({"name": name, "template": template, "states": states}, ensure_ascii=False))


def main() -> int:
    for name, template, states in SYNTHETIC:
        emit("synthetic:" + name, template, states)

    root = repo_root()
    files = []
    for base in [
        os.path.join(root, "saola", "resources"),
        os.path.join(root, "php-compiler", "tests", "Parity", "source-split", "fixtures"),
    ]:
        for directory, _, filenames in os.walk(base):
            for filename in filenames:
                if filename.endswith(".sao"):
                    files.append(os.path.join(directory, filename))

    for path in sorted(files):
        with open(path, "r", encoding="utf-8-sig") as handle:
            source = handle.read()
        emit(os.path.relpath(path, root), source, state_names(source))

    print(f"  {len(SYNTHETIC)} ca tổng hợp + {len(files)} file thật/fixture", file=__import__('sys').stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
