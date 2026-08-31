#!/usr/bin/env python3
import contextlib, json, os, sys
p=os.path.abspath(__file__)
while p!=os.path.dirname(p):
 p=os.path.dirname(p)
 if os.path.isdir(os.path.join(p,'builder','.reference','python','src')):break
sys.path[:0]=[os.path.join(p,'builder','.reference','python','src'),os.path.join(p,'builder','.reference','python','src','sao2js')]
from directive_processors import DirectiveProcessor

SCALAR=['auth','endauth','can','endcan','csrf','method','error','enderror','hassection','endhassection','unless','endunless','json','lang','choice','exec','out']
METHOD={'hassection':'hassection','endhassection':'endhassection'}

def mutation(processor,name,source,end=None):
 stack=[];output=[]
 value=getattr(processor,f'process_{name}_directive')(source,stack,output)
 end_value=None
 if end is not None:end_value=getattr(processor,f'process_{end}_directive')(stack,output)
 return {'value':value,'end':end_value,'stack':stack,'output':output}

for line in sys.stdin:
 if not line.strip():continue
 case=json.loads(line);source=case['source'];p=DirectiveProcessor()
 with contextlib.redirect_stdout(sys.stderr):
  result={name:getattr(p,f"process_{METHOD.get(name,name)}_directive")(source) for name in SCALAR}
  result['empty']=mutation(p,'empty',source,'endempty')
  result['isset']=mutation(p,'isset',source,'endisset')
  result['php']=mutation(p,'php',source,'endphp')
  result['let']=mutation(p,'let',source)
  result['const']=mutation(p,'const',source)
  result['usestate']=mutation(p,'usestate',source)
  result['wrapper']=mutation(p,'wrapper',source,'endwrapper')
 print(json.dumps({'name':case['name'],'result':result},ensure_ascii=False,sort_keys=True,separators=(',',':')))
