#!/usr/bin/env python3
import contextlib
import json
import os
import sys

path = os.path.abspath(__file__)
while path != os.path.dirname(path):
    path = os.path.dirname(path)
    if os.path.isdir(os.path.join(path, "builder", ".reference", "python", "src")):
        break
sys.path[:0] = [os.path.join(path, "builder", ".reference", "python", "src"), os.path.join(path, "builder", ".reference", "python", "src", "sao2js")]
from template_processors import TemplateProcessors  # noqa: E402

handler = TemplateProcessors()
for line in sys.stdin:
    if not line.strip():
        continue
    case = json.loads(line)
    with contextlib.redirect_stdout(sys.stderr):
        result = {
            "template": handler.process_template_line(case["source"]),
            "server": handler.process_serverside_directive(case["source"]),
            "client": handler.process_clientside_directive(case["source"]),
        }
    print(json.dumps({"name": case["name"], "result": result}, ensure_ascii=False, sort_keys=True, separators=(",", ":")))
