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
from loop_handlers import LoopHandlers  # noqa: E402
from template_processor import ReactiveScope  # noqa: E402


class Processor:
    def __init__(self):
        self.reactive_scope_stack = [ReactiveScope()]

    def _generate_child_id(self, kind):
        return self.reactive_scope_stack[-1].next_child_id(kind)

    @staticmethod
    def _make_rc_id(child_id):
        return f"`rc-${{__VIEW_ID__}}-{child_id}`"

    def _push_reactive_scope(self, prefix, loop_var=None):
        self.reactive_scope_stack.append(ReactiveScope(prefix, loop_var))

    def _pop_reactive_scope(self):
        if len(self.reactive_scope_stack) > 1:
            self.reactive_scope_stack.pop()


for line in sys.stdin:
    if not line.strip():
        continue
    case = json.loads(line)
    handler = LoopHandlers(set(case.get("states", [])), Processor(), case.get("typescript", False))
    stack = case.get("stack", []).copy()
    output, returns = [], []
    with contextlib.redirect_stdout(sys.stderr):
        for operation in case["ops"]:
            op = operation["op"]
            if op == "append": output.append(operation["value"]); returns.append(None)
            elif op == "foreach": returns.append(handler.process_foreach_directive(operation["line"], stack, output, operation.get("attribute", False)))
            elif op == "endforeach": returns.append(handler.process_endforeach_directive(stack, output))
            elif op == "for": returns.append(handler.process_for_directive(operation["line"], stack, output, operation.get("attribute", False)))
            elif op == "endfor": returns.append(handler.process_endfor_directive(stack, output))
            elif op == "while": returns.append(handler.process_while_directive(operation["line"], stack, output, operation.get("attribute", False)))
            elif op == "endwhile": returns.append(handler.process_endwhile_directive(stack, output))
    print(json.dumps({"name": case["name"], "result": {"output": output, "returns": returns, "stack": stack}}, ensure_ascii=False, sort_keys=True, separators=(",", ":")))
