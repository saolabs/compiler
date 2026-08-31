#!/usr/bin/env python3
import contextlib
import json
import os
import sys

path = os.path.abspath(__file__)
while path != os.path.dirname(path):
    path = os.path.dirname(path)
    if os.path.isdir(os.path.join(path, 'builder', '.reference', 'python', 'src')):
        break
sys.path[:0] = [os.path.join(path, 'builder', '.reference', 'python', 'src'), os.path.join(path, 'builder', '.reference', 'python', 'src', 'sao2js')]
from template_processor import TemplateProcessor  # noqa: E402

for line in sys.stdin:
    if not line.strip():
        continue
    case = json.loads(line)
    processor = TemplateProcessor(set(case['states']), case['typescript'])
    with contextlib.redirect_stdout(sys.stderr):
        template, sections = processor.process_template(case['source'])
    print(json.dumps({'name': case['name'], 'result': {'template': template, 'sections': sections}}, ensure_ascii=False, sort_keys=True, separators=(',', ':')))
