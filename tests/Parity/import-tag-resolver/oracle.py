#!/usr/bin/env python3
"""Oracle: chạy ImportTagResolver của compiler Python."""

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


sys.path.insert(0, os.path.join(repo_root(), "builder", ".reference", "python", "src"))
from common.import_tag_resolver import ImportTagResolver  # noqa: E402


def main() -> int:
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        call = json.loads(line)
        code, imports, target = call["args"]
        try:
            value = ImportTagResolver(imports=imports, target=target).resolve_tags(code)
            result = {"ok": True, "value": value}
        except Exception as exc:  # noqa: BLE001
            result = {"ok": False, "value": type(exc).__name__}

        print(f"{line}\t{json.dumps(result, ensure_ascii=False, separators=(',', ':'))}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
