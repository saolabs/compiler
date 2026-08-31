#!/usr/bin/env node
'use strict';
const fs = require('fs');
const path = require('path');
function root(){let d=__dirname;while(d!==path.dirname(d)){d=path.dirname(d);if(fs.existsSync(path.join(d,'builder','src','index.js')))return d;}throw new Error('root');}
const synthetic=[
 {name:'plain',source:'<div>Hello</div>'},
 {name:'nested',source:'<main><h1>Title</h1><img src="x"></main>'},
 {name:'attrs',source:'<input disabled class="a b" data-x="1">'},
 {name:'echo-static',source:'<p>{{ $name }}</p>',declared:['name']},
 {name:'echo-reactive',source:'<p>{{ $name }}</p>',states:['name']},
 {name:'raw',source:'{!! $html !!}',states:['html']},
 {name:'if',source:'@if($ok)\n<p>yes</p>\n@else\n<p>no</p>\n@endif',states:['ok']},
 {name:'if-exec',source:'@if($ok)\n@exec($x = 1)\n{{ $x }}\n@endif',states:['ok']},
 {name:'foreach',source:'@foreach($items as $item)\n<span>{{ $item }}</span>\n@endforeach',states:['items']},
 {name:'foreach-key',source:'@foreach($items as $item)\n@key($item->id)\n<span>{{ $item->name }}</span>\n@endforeach',states:['items']},
 {name:'for',source:'@for($i = 0; $i < $count; $i++)\n<b>{{ $i }}</b>\n@endfor',states:['count']},
 {name:'while',source:'@while($i < 3)\n<i>{{ $i }}</i>\n@exec($i++)\n@endwhile',declared:['i']},
 {name:'switch',source:'@switch($kind)\n@case(1)\none\n@break\n@default\nother\n@endswitch',states:['kind']},
 {name:'class-bind',source:"<div @class(['active' => $ok, 'base'])></div>",states:['ok']},
 {name:'attrs-bind',source:"<div @attr('title', $title)></div>",states:['title']},
 {name:'style',source:"<div @style('color', $color)></div>",states:['color']},
 {name:'props-bind',source:'<input @checked($ok)>',states:['ok']},
 {name:'event',source:'<button @click(handleClick(@event, $id))>Go</button>'},
 {name:'event-mods',source:'<button @click.prevent.stop(handleClick())>Go</button>'},
 {name:'bind',source:'<input @bind($name)>',states:['name']},
 {name:'transition',source:"<div @transition('fade')>x</div>"},
 {name:'include',source:"@include('part.card', ['title' => $title])",states:['title']},
 {name:'children',source:'<main>@children</main>'},
 {name:'section',source:"@section('title', 'Home')\n@section('body')\n<p>Body</p>\n@endsection"},
 {name:'extends',source:"@block('body')\n<p>Body</p>\n@endblock",hasExtends:true,extendsExpression:"'layouts.app'",extendsData:'{x: 1}'},
 {name:'scope',source:'<div><span>x</span></div>',scopeClass:'s-abc'},
 {name:'typescript',source:'@foreach($items as $item)\n<button @click($item++)>{{ $item }}</button>\n@endforeach',states:['items'],typescript:true},
];
for(const c of synthetic)process.stdout.write(JSON.stringify({states:[],declared:[],typescript:false,scopeClass:'',hasExtends:false,extendsExpression:null,extendsData:null,prerendered:[],...c})+'\n');
const repo=root(),Compiler=require(path.join(repo,'builder','src','index.js')),compiler=new Compiler();let real=0;
function walk(dir){if(!fs.existsSync(dir))return;for(const e of fs.readdirSync(dir,{withFileTypes:true}).sort((a,b)=>a.name.localeCompare(b.name))){const f=path.join(dir,e.name);if(e.isDirectory())walk(f);else if(e.name.endsWith('.sao'))try{const raw=fs.readFileSync(f,'utf8'),p=compiler.parseSaoFile(raw,f),vars=[...new Set((raw.match(/\$[A-Za-z_]\w*/g)||[]).map(x=>x.slice(1)))].sort();process.stdout.write(JSON.stringify({name:path.relative(repo,f),source:p.blade,states:vars,declared:vars,typescript:false,scopeClass:'',hasExtends:false,extendsExpression:null,extendsData:null,prerendered:[]})+'\n');real++;}catch(_){}}}
walk(path.join(repo,'saola','resources'));walk(path.join(repo,'php-compiler','tests','Parity','source-split','fixtures'));
process.stderr.write(`  ${synthetic.length} ca tổng hợp + ${real} template thật/fixture\n`);
