#!/usr/bin/env python3
import base64,contextlib,io,json,os,sys
def root():
 p=os.path.abspath(__file__)
 while p!=os.path.dirname(p):
  p=os.path.dirname(p)
  if os.path.isdir(os.path.join(p,'builder','.reference','python','src')):return p
 raise RuntimeError
sys.path.insert(0,os.path.join(root(),'builder','.reference','python','src'))
from sao2js.main_compiler import BladeCompiler
compiler=BladeCompiler()
for raw in sys.stdin:
 raw=raw.rstrip('\n')
 if not raw:continue
 c=json.loads(raw)
 try:
  with contextlib.redirect_stdout(io.StringIO()):
   value=compiler.compile_blade_to_js(c['code'],c['view'],c['functionName'],c['factoryName'])
  result={'ok':True,'base64':base64.b64encode(value.encode()).decode()}
 except Exception as e:
  result={'ok':False,'error':type(e).__name__+':'+str(e)}
 print(c['name']+'\t'+json.dumps(result,separators=(',',':'),ensure_ascii=False))
