#!/usr/bin/env python3
import contextlib
import json
import os
import sys


def root():
    path = os.path.abspath(__file__)
    while path != os.path.dirname(path):
        path = os.path.dirname(path)
        if os.path.isdir(os.path.join(path, "builder", ".reference", "python", "src")):
            return path
    raise RuntimeError("Không tìm thấy repo root")


repo = root()
sys.path.insert(0, os.path.join(repo, "builder", ".reference", "python", "src"))
sys.path.insert(0, os.path.join(repo, "builder", ".reference", "python", "src", "sao2js"))

from binding_directive_service import BindingDirectiveService  # noqa: E402
from class_binding_handler import ClassBindingHandler  # noqa: E402
from echo_processor import EchoProcessor  # noqa: E402
from show_directive_handler import ShowDirectiveHandler  # noqa: E402
from style_directive_handler import StyleDirectiveHandler  # noqa: E402
from template_analyzer import TemplateAnalyzer  # noqa: E402


def main():
    binding = BindingDirectiveService()
    analyzer = TemplateAnalyzer()
    for line in sys.stdin:
        if not line.strip():
            continue
        case = json.loads(line)
        content = case.get("content", "")
        states = set(case.get("states", []))
        with contextlib.redirect_stdout(sys.stderr):
            result = {
                "binding_all": binding.process_all_binding_directives(content),
                "binding_bind": binding.process_bind_directive(content),
                "binding_val": binding.process_val_directive(content),
                "class": ClassBindingHandler(states).process_class_directive(content),
                "conditional": analyzer.analyze_conditional_structures(
                    content, case.get("vars"), case.get("await", False), case.get("fetch", False)
                ),
                "echo": EchoProcessor(states, case.get("typescript", False)).process_echo_expressions(content),
                "sections": analyzer.analyze_sections_info(
                    case.get("sections", []), case.get("vars"), case.get("await", False),
                    case.get("fetch", False), states, case.get("blade")
                ),
                "show": ShowDirectiveHandler(states).process_show_directive(content),
                "style": StyleDirectiveHandler(states).process_style_directive(content),
            }
        print(json.dumps({"name": case["name"], "result": result}, ensure_ascii=False, sort_keys=True, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
