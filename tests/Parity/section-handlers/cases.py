#!/usr/bin/env python3
import json


CASES = [
    [{"op": "section", "line": "@section('title', 'Hello')"}],
    [{"op": "section", "line": "@section(\"title\", \"Hello \\\"Saola\\\"\")"}],
    [{"op": "section", "line": "@section('title', $name . ' | App')"}],
    [{"op": "section", "line": "@section('title', fn($a, nested(1, 2)))"}],
    [{"op": "section", "line": "@section('empty', )"}],
    [{"op": "section", "line": "@section('body')"}, {"op": "append", "value": "Hello"}, {"op": "endsection"}],
    [{"op": "section", "line": "@section('body')"}, {"op": "append", "value": "<b>Hello</b>"}, {"op": "endsection"}],
    [{"op": "section", "line": "@section('body')"}, {"op": "append", "value": False}, {"op": "append", "value": "A"}, {"op": "append", "value": 1}, {"op": "endsection"}],
    [{"op": "endsection"}],
    [{"op": "block", "line": "@block('main')"}, {"op": "append", "value": "<main>x</main>"}, {"op": "endblock"}],
    [{"op": "block", "line": "@block('main', ['class' => $class])"}, {"op": "append", "value": "body"}, {"op": "endblock"}],
    [{"op": "block", "line": "@block($dynamic)"}, {"op": "append", "value": "body"}, {"op": "endblock"}],
    [{"op": "endblock"}],
    [{"op": "section", "line": "broken"}, {"op": "block", "line": "broken"}],
    [{"op": "section", "line": "@section('outer')"}, {"op": "append", "value": "before"}, {"op": "section", "line": "@section('inner')"}, {"op": "append", "value": "inside"}, {"op": "endsection"}, {"op": "endsection"}],
]


for index, operations in enumerate(CASES, 1):
    print(json.dumps({"name": f"section-{index:02d}", "operations": operations}, ensure_ascii=False))
