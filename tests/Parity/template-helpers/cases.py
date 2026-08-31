#!/usr/bin/env python3
"""Corpus for the small, active helpers used by sao2js/template_processor.py."""

import json
import os
import sys


SYNTHETIC = [
    {"content": '<input @val($userState->name)>', "states": ["userState"]},
    {"content": '<input @bind($user[\'name\'])>', "states": ["user"]},
    {"content": '<input @val(User::find($id)->profile->displayName)>', "states": ["id"]},
    {"content": '<input @bind($rows[0]["name"]) @val($plain)>', "states": ["plain"]},
    {"content": '<input @val(fn($a, nested($b)))>', "states": ["a"]},
    {"content": '<input @val($broken>', "states": ["broken"]},
    {"content": "<div @style(['color' => $textColor])></div>", "states": ["textColor"]},
    {"content": "<div @style(['font-size' => size(1, 2), 'content' => 'a,b'])></div>"},
    {"content": "<div @style([\n 'display' => $visible ? 'block' : 'none'\n])></div>", "states": ["visible"]},
    {"content": "<div @style(['color' => 'red']) @style(['margin' => 0])></div>"},
    {"content": "<div @style(['broken'])></div>"},
    {"content": "<div @class('card static')></div>"},
    {"content": "<div @class('active', $isActive)></div>", "states": ["isActive"]},
    {"content": "<div @class(['base', 'active' => $isActive, 'busy' => $loading])></div>"},
    {"content": "<div @class({'ready': $ok ? true : false, plain})></div>"},
    {"content": "<div @class(['ignored-key' => 'actual-static'])></div>"},
    {"content": "<div @class($computedClass)></div>"},
    {"content": "<div @class([\n 'wide' => check($width, fn(1, 2)),\n 'compact'\n])></div>"},
    {"content": "<div @class($broken></div>"},
    {"content": "<p>Hello {{ $name }}</p>"},
    {"content": "<p>{!! $html !!}</p>"},
    {"content": "<p>{{ $count + 1 }} / {!! $raw !!}</p>", "states": ["count"]},
    {"content": "<p>{{ $count }}</p>", "states": ["count"], "typescript": True},
    {"content": "<a title=\"Hello {{ $name }}\">x</a>"},
    {"content": "<a title=\"{{ $title }}\">x</a>", "states": ["title"]},
    {"content": "<a title=\"A {{ $title }} B {!! $raw !!}\">x</a>", "states": ["title"]},
    {"content": "<input @checked($enabled)>", "states": ["enabled"]},
    {"content": "<option @selected(true)>x</option>"},
    {"content": "<input @attr('data-count', $count) title=\"{{ $title }}\">", "states": ["count", "title"]},
    {"content": "<input @attr(['data-x' => $x, 'plain' => 1]) value=\"{{ $value }}\">", "states": ["x", "value"]},
    {"content": "<input {{ $checked ? 'checked' : '' }} />"},
    {"content": "<div title=\"{{  $spaced  }}\">{{  $spaced  }}</div>"},
    {"content": "<!-- {{ $comment }} --><x-tag data-x=\"{{ $x }}\"></x-tag>"},
    {"content": "Unicode {{ $tên }} 🚀", "states": ["tên"]},
    {"content": '<div @show($isVisible)></div>', "states": ["isVisible"]},
    {"content": '<div @show($count > 0 && check($count))></div>', "states": ["count"]},
    {"content": '<div @show(true)></div>'},
    {"content": '<div @show(\n $ready\n)></div>', "states": ["ready"]},
    {"content": '<div @show($broken></div>', "states": ["broken"]},
    {
        "content": "@if($users) ${users} @endif",
        "vars": "let { users = [], title = null } = this.__vars__;",
        "await": True,
    },
    {
        "content": "@switch($status) ${App.Helper.escString(status)} @endswitch",
        "vars": "let { status = null } = this.__vars__;",
        "fetch": True,
    },
    {
        "content": "@if($ready) static @endif",
        "vars": "let { users = [] } = this.__vars__;",
        "await": True,
    },
    {
        "sections": [
            "this.__section('hero', title);",
            "this.__section('body', `Hello ${users}`);",
            "this.__section('hero', `Updated ${title}`);",
        ],
        "vars": "let { title = '', users = [] } = this.__vars__;",
        "await": True,
    },
    {
        "sections": ["App.Helper.section(\"main\", `static`)"],
        "vars": "let { users = [] } = this.__vars__;",
        "fetch": True,
    },
    {
        "sections": ["this.__block('main', `Hello count`)", "garbage"],
        "states": ["count"],
        "await": True,
        "blade": "@block('main') @startMarker('x') <b>@hydrate('h') Count</b> @endMarker('x') @endblock",
    },
    {
        "sections": ["this.__block('zero', `0`)"] ,
        "blade": "@block('zero')0@endblock",
    },
    {"content": "Unicode Tiếng Việt 🚀 @show($mở)", "states": ["mở"]},
    {"content": "@else text", "vars": None, "await": True},
    {"content": "@if($x) (x) @endif", "vars": "let { x = 1 } = this.__vars__;"},
]


def root():
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, "builder", ".reference", "python", "src")):
            return path
    raise RuntimeError("Không tìm thấy repo root")


def main():
    for index, case in enumerate(SYNTHETIC, 1):
        print(json.dumps({"name": f"synthetic-{index:02d}", **case}, ensure_ascii=False))

    repo = root()
    files = []
    for base in [
        os.path.join(repo, "saola", "resources"),
        os.path.join(repo, "php-compiler", "tests", "Parity", "source-split", "fixtures"),
    ]:
        for directory, _, names in os.walk(base):
            for name in names:
                if name.endswith(".sao"):
                    files.append(os.path.join(directory, name))
    for path in sorted(files):
        with open(path, encoding="utf-8-sig") as handle:
            content = handle.read()
        print(json.dumps({
            "name": os.path.relpath(path, repo),
            "content": content,
            "vars": "let { users = [], title = null } = this.__vars__;",
            "await": True,
        }, ensure_ascii=False))

    print(f"  {len(SYNTHETIC)} ca tổng hợp + {len(files)} file thật/fixture", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
