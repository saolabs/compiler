#!/usr/bin/env python3
import base64,json,sys
left=open(sys.argv[1],encoding='utf-8').read().splitlines()
right=open(sys.argv[2],encoding='utf-8').read().splitlines()
for a,b in zip(left,right):
 if a==b: continue
 name,aj=a.split('\t',1); _,bj=b.split('\t',1)
 av=json.loads(aj);bv=json.loads(bj)
 print('First mismatch:',name)
 if not av.get('ok') or not bv.get('ok'):
  print('oracle:',av);print('subject:',bv);break
 x=base64.b64decode(av['base64']).decode();y=base64.b64decode(bv['base64']).decode()
 i=next((i for i,(p,q) in enumerate(zip(x,y)) if p!=q),min(len(x),len(y)))
 print('byte:',i,'oracle-len:',len(x),'subject-len:',len(y))
 print('oracle:',repr(x[max(0,i-180):i+320]))
 print('subject:',repr(y[max(0,i-180):i+320]))
 break
