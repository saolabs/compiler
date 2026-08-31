#!/usr/bin/env python3
"""Corpus cho ImportTagResolver: ca tổng hợp + toàn bộ view có @import."""

import json
import os


def repo_root() -> str:
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, "builder", ".reference", "python", "src")):
            return path
    raise RuntimeError("Không tìm thấy repo root")


SYNTHETIC = [
    ("không có import", {}, "js"),
    ("<counter />", {"counter": "'sessions.tasks.count'"}, "js"),
    ("<counter/>", {"counter": "'sessions.tasks.count'"}, "blade"),
    ("<counter enabled />", {"counter": "'counter'"}, "js"),
    ("<counter :enabled />", {"counter": "'counter'"}, "js"),
    ('<tasks title="\'Custom Task List\'" />', {"tasks": "$__template__.'sessions.tasks'"}, "js"),
    ('<demo :users="$users" />', {"demo": "$__template__.'demo.fetch'"}, "blade"),
    ('<alert type="success" message="Xin chào!" />', {"alert": "$__blade_custom_path__"}, "js"),
    ('<item :data="[\'a\' => fn($x) => [$x]]" plain=abc />', {"item": "'item'"}, "js"),
    ("<projects></projects>", {"projects": "$__template__.'sessions.projects'"}, "js"),
    ("<projects>   \n\t </projects>", {"projects": "'projects'"}, "blade"),
    ("<projects>0</projects>", {"projects": "'projects'"}, "js"),
    ("<projects>\u00a0Xin chào\u00a0</projects>", {"projects": "'projects'"}, "blade"),
    ("<tasks><tasks>con</tasks></tasks>", {"tasks": "'tasks'"}, "js"),
    ("<tasks><tasks /></tasks>", {"tasks": "'tasks'"}, "blade"),
    (
        '<tasks><demo :users="$users" /></tasks>',
        {"tasks": "$__template__.'tasks'", "demo": "$__template__.'demo'"},
        "js",
    ),
    (
        '<alert type="success">\n  <demo />\n</alert>',
        {"alert": "$__blade_custom_path__", "demo": "$__template__.'demo'"},
        "blade",
    ),
    ("<task /><tasks />", {"task": "'one'", "tasks": "'many'"}, "js"),
    ("<CamelCase data-id='42' />", {"CamelCase": "'camel'"}, "js"),
    ("<x-item foo-bar=one />", {"x-item": "'hyphen'"}, "blade"),
    ("<counter>", {"counter": "'counter'"}, "js"),
    ("</counter>", {"counter": "'counter'"}, "js"),
    # Oracle tính close_end theo `</tag>` ngay cả khi regex cho phép whitespace.
    ("<counter>x</counter   >", {"counter": "'counter'"}, "blade"),
    ('<item value="[\"a\", {\'b\': (1)}]" />', {"item": "'item'"}, "js"),
    ('<item title="a\\\"b" />', {"item": "'item'"}, "js"),
    ('<item weird="unterminated />', {"item": "'item'"}, "js"),
]


def emit(code: str, imports: dict[str, str], target: str, name: str) -> None:
    print(json.dumps({
        "name": name,
        "args": [code, imports, target],
    }, ensure_ascii=False))


def main() -> int:
    for index, (code, imports, target) in enumerate(SYNTHETIC, start=1):
        emit(code, imports, target, f"synthetic-{index:02d}")

    root = repo_root()
    import sys
    sys.path.insert(0, os.path.join(root, "builder", ".reference", "python", "src"))
    from common.import_parser import ImportParser

    real = 0
    resources = os.path.join(root, "saola", "resources")
    for directory, _, files in os.walk(resources):
        for filename in sorted(files):
            if not filename.endswith(".sao"):
                continue
            path = os.path.join(directory, filename)
            with open(path, "r", encoding="utf-8") as handle:
                code = handle.read()
            imports = ImportParser().parse_imports(code)
            if not imports:
                continue
            real += 1
            emit(code, imports, "js", os.path.relpath(path, root))
            emit(code, imports, "blade", os.path.relpath(path, root) + ":blade")

    print(f"  {len(SYNTHETIC)} ca tổng hợp + {real * 2} ca từ view thật", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
