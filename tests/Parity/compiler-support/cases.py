#!/usr/bin/env python3
import json, os, sys

SYNTHETIC = [
    '', '<script src="/app.js" defer></script>',
    '<style scoped>.x { color:red }</style><link REL="preload stylesheet" href="/a.css" media="screen">',
    '<script>import x from "x";\nconst a = 1;\nexport default { mounted() { return a }, value: 2 }</script>',
    '<script setup lang="ts">const x: number = 1; export default { async save() {}, run: (x) => x }</script>',
    '<script src="{{ asset(\'x.js\') }}" id="main" class="foo" async></script>',
    '<style id="theme" nonce="abc">body { color: blue }</style>',
]

for i, source in enumerate(SYNTHETIC, 1):
    print(json.dumps({'fn':'register','args':[source], 'name':f'synthetic-{i:02d}'}, ensure_ascii=False))

def root():
    p=os.path.abspath(__file__)
    while p != os.path.dirname(p):
        p=os.path.dirname(p)
        if os.path.isdir(os.path.join(p,'builder','.reference','python','src')): return p
    raise RuntimeError

repo=root(); count=0
for base in [os.path.join(repo,'saola','resources'), os.path.join(repo,'php-compiler','tests','Parity','source-split','fixtures')]:
    if not os.path.isdir(base): continue
    for directory, _, names in os.walk(base):
        for name in sorted(names):
            if not name.endswith('.sao'): continue
            path=os.path.join(directory,name)
            source=open(path,encoding='utf-8-sig').read()
            print(json.dumps({'fn':'register','args':[source], 'name':os.path.relpath(path,repo)}, ensure_ascii=False))
            count += 1

utility_calls = [
    ('format_fetch_config', [None]), ('format_fetch_config', [{}]),
    ('format_fetch_config', [{'url':'`/users/${id}`','method':'POST','headers':{'X-Test':'✓'},'cache':False,'limit':2}]),
    ('format_attrs', [{'a':'x','enabled':True,'n':2}]),
    ('format_attributes_to_json', [None]),
    ('format_attributes_to_json', [{'defer':True,'nonce':123,'name':'x'}]),
]
for i,(fn,args) in enumerate(utility_calls,1):
    print(json.dumps({'fn':fn,'args':args,'name':f'utils-{i:02d}'},ensure_ascii=False))
print(f'  7 ca tổng hợp + {count} source thật + {len(utility_calls)} utility', file=sys.stderr)
