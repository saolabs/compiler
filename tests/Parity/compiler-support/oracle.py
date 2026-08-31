#!/usr/bin/env python3
import json, os, sys

def root():
    p=os.path.abspath(__file__)
    while p != os.path.dirname(p):
        p=os.path.dirname(p)
        if os.path.isdir(os.path.join(p,'builder','.reference','python','src')): return p
    raise RuntimeError
sys.path.insert(0, os.path.join(root(),'builder','.reference','python','src'))
from sao2js.register_parser import RegisterParser
from common.compiler_utils import CompilerUtils

utils=CompilerUtils()
for raw in sys.stdin:
    raw=raw.rstrip('\n')
    if not raw: continue
    call=json.loads(raw); fn=call['fn']; args=call['args']
    if fn=='register':
        parser=RegisterParser(); value=parser.parse_register_content(*args)
        value['methodNames']=sorted(parser.get_user_method_names())
    else:
        value=getattr(utils,fn)(*args)
    print(raw+'\t'+json.dumps(value,ensure_ascii=False,separators=(',',':')))
