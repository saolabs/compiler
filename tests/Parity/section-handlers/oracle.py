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
sys.path.insert(0, os.path.join(path, "builder", ".reference", "python", "src"))
sys.path.insert(0, os.path.join(path, "builder", ".reference", "python", "src", "sao2js"))
from section_handlers import SectionHandlers  # noqa: E402


handler = SectionHandlers()
for line in sys.stdin:
    if not line.strip():
        continue
    case = json.loads(line)
    stack, output, sections, returns = [], [], [], []
    with contextlib.redirect_stdout(sys.stderr):
        for operation in case["operations"]:
            op = operation["op"]
            if op == "append":
                output.append(operation["value"])
                returns.append(None)
            elif op == "section":
                returns.append(handler.process_section_directive(operation["line"], stack, output, sections))
            elif op == "endsection":
                returns.append(handler.process_endsection_directive(stack, output, sections))
            elif op == "block":
                returns.append(handler.process_block_directive(operation["line"], stack, output, sections))
            elif op == "endblock":
                returns.append(handler.process_endblock_directive(stack, output, sections))
    print(json.dumps({"name": case["name"], "result": {"stack": stack, "output": output, "sections": sections, "returns": returns}}, ensure_ascii=False, sort_keys=True, separators=(",", ":")))
