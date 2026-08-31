#!/usr/bin/env node
'use strict';
const fs=require('fs'),path=require('path');
function root(){let d=__dirname;while(d!==path.dirname(d)){d=path.dirname(d);if(fs.existsSync(path.join(d,'builder','src','index.js')))return d;}throw new Error('root');}
const repo=root(),Compiler=require(path.join(repo,'builder','src','index.js')),compiler=new Compiler();
const synthetic=[
 ['plain','<template>\n<div>Hello</div>\n</template>'],
 ['props',"@props({ title: 'Hello', count: 2 })\n<template>\n<h1>{{ title }}</h1>\n</template>"],
 ['states',"@states({ count: 0 })\n@computed(double = $count * 2)\n<template>\n<button @click($count++)>{{ $double }}</button>\n</template>"],
 ['extends',"@extends('layouts.app', ['x' => 1])\n<template>\n@block('body')\n<p>Body</p>\n@endblock\n</template>"],
 ['typescript','<script setup lang="ts">\ninterface Item { id: number }\nconst local: Item = { id: 1 };\nexport default { mounted() {}, save: (x: number) => x }\n</script>\n<template>\n<div>{{ local.id }}</div>\n</template>'],
 ['assets','<script src="{{ asset(\'app.js\') }}" defer></script>\n<style scoped>.x { color:red }</style>\n<link rel="stylesheet" href="/app.css">\n<template><div class="x">x</div></template>'],
 ['loops',"@props({ items: [] })\n<template>\n@foreach(items as item)\n<button @click.prevent(select(item.id))>{{ item.name }}</button>\n@endforeach\n</template>"],
 ['wrapper',"<template>\n@wrapper('section', ['class' => 'page'])\n<div>inside</div>\n@endwrapper\n</template>"],
 ['verbatim',"<template>\n@verbatim\n<code>`x ${y}` and 'quote'</code>\n@endverbatim\n</template>"],
 ['await','@props({ users: [] })\n@await\n@fetch(\'/users\')\n<template>\n<div>{{ users.length }}</div>\n</template>'],
];
let index=0,real=0;
function pascal(s){return s.split(/[-_\s]+/).map(w=>w.charAt(0).toUpperCase()+w.slice(1)).join('');}
function emit(name,source,file){
 const parts=compiler.parseSaoFile(source,file||name+'.sao');
 const blade=compiler.preprocessor.preprocess(parts,{assetPrefix:'static/parity/assets/'});
 const scripts=parts.cleanedContent.match(/<script[^>]*>[\s\S]*?<\/script>/gi)||[];
 const styles=parts.cleanedContent.match(/<style[^>]*>[\s\S]*?<\/style>/gi)||[];
 const links=parts.cleanedContent.match(/<link\b(?=[^>]*\brel\s*=\s*["'][^"']*\bstylesheet\b[^"']*["'])[^>]*>/gi)||[];
 let code=blade.declarations.length?blade.declarations.join('\n')+'\n\n':'';
 if(scripts.length||styles.length||links.length)code+=[...scripts,...styles,...links].join('\n')+'\n\n';
 code+=parts.wrapperType?`<${parts.wrapperType}>\n${blade.blade}\n</${parts.wrapperType}>`:blade.blade;
 const component='Case'+String(++index).padStart(3,'0'),view='parity.'+pascal(name.replace(/[^A-Za-z0-9_-]+/g,'-'));
 process.stdout.write(JSON.stringify({name,code,view,functionName:component,factoryName:'Parity'+component})+'\n');
}
for(const [name,source] of synthetic)emit(name,source);
function walk(dir){if(!fs.existsSync(dir))return;for(const e of fs.readdirSync(dir,{withFileTypes:true}).sort((a,b)=>a.name.localeCompare(b.name))){const f=path.join(dir,e.name);if(e.isDirectory())walk(f);else if(e.name.endsWith('.sao')&&e.name!=='13-unclosed.sao')try{emit(path.relative(repo,f),fs.readFileSync(f,'utf8'),f);real++;}catch(_){}}}
walk(path.join(repo,'saola','resources'));walk(path.join(repo,'php-compiler','tests','Parity','source-split','fixtures'));
process.stderr.write(`  ${synthetic.length} ca tổng hợp + ${real} source thật\n`);
