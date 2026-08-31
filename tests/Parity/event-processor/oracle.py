#!/usr/bin/env python3
import contextlib,json,os,sys
p=os.path.abspath(__file__)
while p!=os.path.dirname(p):
 p=os.path.dirname(p)
 if os.path.isdir(os.path.join(p,'builder','.reference','python','src')):break
sys.path[:0]=[os.path.join(p,'builder','.reference','python','src'),os.path.join(p,'builder','.reference','python','src','sao2js')]
from event_directive_processor import EventDirectiveProcessor
for line in sys.stdin:
 if not line.strip():continue
 c=json.loads(line);h=EventDirectiveProcessor(set(c['states']))
 with contextlib.redirect_stdout(sys.stderr):r={'directive':h.process_event_directive(c['event'],c['expression']),'items':h.process_event_items(c['expression']),'split':h.split_by_comma(c['expression'])}
 print(json.dumps({'name':c['name'],'result':r},ensure_ascii=False,sort_keys=True,separators=(',',':')))
