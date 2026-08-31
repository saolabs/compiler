#!/usr/bin/env python3
import json, os, re, sys

SYNTHETIC = [
 ('click','handleClick',[]),('click','handleClick()',[]),('click',"handleClick(@event, 'x')",[]),
 ('change','$event->target->value',[]),('input','$Event.target.value',[]),
 ('click','$count++',['count']),('click','$count += 2',['count']),('click','$count = $count + 1',['count']),
 ('click','$count($count + 1)',['count']),('click','$setCount($count + 1)',['count']),
 ('submit','save($form, @event)',['form']),('click','first(), second($id), $count++',['count']),
 ('click','first(); second(); $count++',['count']),('change',"update(['x' => $x, 'nested' => ['y' => 2]])",['x']),
 ('click','outer(inner($id), @attr("data-id"), @event)',[]),
 ('click','(event) => run(event)',[]),('click','event => run(event)',[]),
 ('click','true',[]),('click','42',[]),('click',"'literal'",[]),
 ('click','',[]),('keydown','handle(@event->key)',[]),
]
for i,(event,expr,states) in enumerate(SYNTHETIC,1):
 print(json.dumps({'name':f'synthetic-{i:02d}','event':event,'expression':expr,'states':states},ensure_ascii=False))

def root():
 p=os.path.abspath(__file__)
 while p!=os.path.dirname(p):
  p=os.path.dirname(p)
  if os.path.isdir(os.path.join(p,'builder','.reference','python','src')): return p
 raise RuntimeError

EVENTS='click|dblclick|mousedown|mouseup|mouseover|mouseout|mousemove|mouseenter|mouseleave|wheel|keydown|keyup|keypress|input|change|submit|reset|invalid|focus|blur|focusin|focusout|touchstart|touchmove|touchend|touchcancel|dragstart|drag|dragend|dragenter|dragleave|dragover|drop|scroll|resize|contextmenu|copy|cut|paste|select|load|error|abort|animationstart|animationend|animationiteration|transitionstart|transitionend|transitionrun|transitioncancel|pointerdown|pointerup|pointermove|pointerover|pointerout|pointerenter|pointerleave|pointercancel'
pattern=re.compile(r'@(?:on)?('+EVENTS+r')(?:\.[A-Za-z]+)*\s*\(',re.I)
count=0
repo=root()
for base in [os.path.join(repo,'saola','resources'),os.path.join(repo,'php-compiler','tests','Parity','source-split','fixtures')]:
 for directory,_,names in os.walk(base):
  for name in sorted(names):
   if not name.endswith('.sao'):continue
   file=os.path.join(directory,name); source=open(file,encoding='utf-8-sig').read()
   for m in pattern.finditer(source):
    start=m.end()-1; depth=0; quote=None; escaped=False; end=None
    for pos in range(start,len(source)):
     ch=source[pos]
     if quote:
      if escaped:escaped=False
      elif ch=='\\':escaped=True
      elif ch==quote:quote=None
     elif ch in "'\"":quote=ch
     elif ch=='(':depth+=1
     elif ch==')':
      depth-=1
      if depth==0:end=pos;break
    if end is None:continue
    expr=source[start+1:end]; states=sorted(set(re.findall(r'\$([A-Za-z_]\w*)',expr)))
    count+=1
    print(json.dumps({'name':f'{os.path.relpath(file,repo)}:{source.count(chr(10),0,m.start())+1}','event':m.group(1).lower(),'expression':expr,'states':states},ensure_ascii=False))
print(f'  {len(SYNTHETIC)} ca tổng hợp + {count} directive thật',file=sys.stderr)
