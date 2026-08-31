#!/usr/bin/env python3
import contextlib,json,os,sys
p=os.path.abspath(__file__)
while p!=os.path.dirname(p):
 p=os.path.dirname(p)
 if os.path.isdir(os.path.join(p,'builder','.reference','python','src')):break
sys.path[:0]=[os.path.join(p,'builder','.reference','python','src'),os.path.join(p,'builder','.reference','python','src','sao2js')]
from template_ast import TemplateASTParser
from render_generator import RenderGenerator
for line in sys.stdin:
 if not line.strip():continue
 c=json.loads(line)
 with contextlib.redirect_stdout(sys.stderr):
  ast=TemplateASTParser(set(c['states'])).parse(c['source'])
  out=RenderGenerator(set(c['states']),set(c['declared']),c['typescript'],c['scopeClass']).generate(ast,c['hasExtends'],c['extendsExpression'],c['extendsData'],None,set(c['prerendered']))
 print(json.dumps({'name':c['name'],'result':out},ensure_ascii=False,sort_keys=True,separators=(',',':')))
