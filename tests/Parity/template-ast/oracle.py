#!/usr/bin/env python3
"""Serialize Python TemplateASTParser output into canonical JSON."""

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


ROOT = repo_root()
sys.path.insert(0, os.path.join(ROOT, "builder", ".reference", "python", "src"))
sys.path.insert(0, os.path.join(ROOT, "builder", ".reference", "python", "src", "sao2js"))
from template_ast import Node, TemplateASTParser  # noqa: E402


def normalize(value):
    if isinstance(value, Node):
        data = {"type": type(value).__name__}
        data.update({key: normalize(item) for key, item in value.__dict__.items()})
        return data
    if isinstance(value, set):
        return sorted(value)
    if isinstance(value, (list, tuple)):
        return [normalize(item) for item in value]
    if isinstance(value, dict):
        return {key: normalize(value[key]) for key in sorted(value)}
    return value


def main() -> int:
    for line in sys.stdin:
        if not line.strip():
            continue
        case = json.loads(line)
        with contextlib.redirect_stdout(sys.stderr):
            try:
                result = normalize(TemplateASTParser(set(case["states"])).parse(case["template"]))
            except Exception as exc:  # noqa: BLE001
                result = {"error": type(exc).__name__, "message": str(exc)}
        print(json.dumps({"name": case["name"], "result": result}, ensure_ascii=False, sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
