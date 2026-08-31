#!/usr/bin/env python3
"""Oracle: chạy BladeHydrateProcessor của compiler Python."""

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


root = repo_root()
sys.path.insert(0, os.path.join(root, "builder", ".reference", "python", "src"))
sys.path.insert(0, os.path.join(root, "builder", ".reference", "python", "src", "sao2blade"))
from hydrate_processor import BladeHydrateProcessor  # noqa: E402


def main() -> int:
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        call = json.loads(line)
        template, states, scope, has_extends = call["args"]
        try:
            value = BladeHydrateProcessor(set(states), scope).process(template, has_extends)
            result = {"ok": True, "value": value}
        except Exception as exc:  # noqa: BLE001
            result = {"ok": False, "value": type(exc).__name__}
        print(f"{line}\t{json.dumps(result, ensure_ascii=False, separators=(',', ':'))}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
