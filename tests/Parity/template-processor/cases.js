#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

function root() {
    let dir = __dirname;
    while (dir !== path.dirname(dir)) {
        dir = path.dirname(dir);
        if (fs.existsSync(path.join(dir, 'builder', 'src', 'index.js'))) return dir;
    }
    throw new Error('repo root not found');
}

const synthetic = [
    { name: 'plain', source: '<div>Hello</div>' },
    { name: 'echo-static', source: '<h1>{{ $title }}</h1>' },
    { name: 'echo-reactive', source: '<h1>{{ $title }}</h1>', states: ['title'] },
    { name: 'raw-reactive', source: '<main>{!! $html !!}</main>', states: ['html'] },
    { name: 'if-static', source: '@if($ok)\n<p>yes</p>\n@else\n<p>no</p>\n@endif' },
    { name: 'if-reactive', source: '@if($ok)\n<p>{{ $title }}</p>\n@endif', states: ['ok', 'title'] },
    { name: 'foreach', source: '@foreach($items as $item)\n<span>{{ $item }}</span>\n@endforeach', states: ['items'] },
    { name: 'foreach-key', source: '@foreach($items as $item)\n@key($item->id)\n<span>{{ $item->name }}</span>\n@endforeach', states: ['items'] },
    { name: 'for', source: '@for($i = 0; $i < $count; $i++)\n<b>{{ $i }}</b>\n@endfor', states: ['count'] },
    { name: 'while', source: '@while($ready)\n<i>wait</i>\n@endwhile', states: ['ready'] },
    { name: 'switch', source: '@switch($kind)\n@case(1)\none\n@break\n@default\nother\n@endswitch', states: ['kind'] },
    { name: 'section', source: "@section('title', 'Home')\n@section('body')\n<div>Body</div>\n@endsection" },
    { name: 'block', source: "@block('hero')\n<section>Hero</section>\n@endblock" },
    { name: 'server-client', source: '@ssr\nserver\n@endssr\n@csr\nclient\n@endcsr' },
    { name: 'event', source: '<button @click(handleClick(@event, $id))>Go</button>' },
    { name: 'event-state', source: '<button @click($count++)>{{ $count }}</button>', states: ['count'] },
    { name: 'multiline-event', source: '<button @click(\n handleClick($id,\n @event)\n)>Go</button>' },
    { name: 'multiline-view', source: "@template(\n tag: 'section',\n subscribe: [$title],\n class: 'hero'\n)" },
    { name: 'include', source: "@include('parts.card', ['title' => $title])", states: ['title'] },
    { name: 'children', source: '<main>@children</main>' },
    { name: 'php', source: '@php\n$x = 1;\n@endphp' },
    { name: 'let-const', source: '@let($x = 1)\n@const($y = $x + 2)' },
    { name: 'auth', source: '@auth\nsecret\n@endauth' },
    { name: 'inline-if', source: '<div @if($ok) class="active" @endif>ok</div>' },
    { name: 'pre', source: '<pre>\n  keep spaces\n</pre>' },
    { name: 'typescript-for', source: '@for($i = 0; $i < $count; $i++)\n{{ $i }}\n@endfor', states: ['count'], typescript: true },
];

for (const value of synthetic) process.stdout.write(JSON.stringify({ states: [], typescript: false, ...value }) + '\n');

const repo = root();
const Compiler = require(path.join(repo, 'builder', 'src', 'index.js'));
const compiler = new Compiler();
const bases = [path.join(repo, 'saola', 'resources'), path.join(repo, 'php-compiler', 'tests', 'Parity', 'source-split', 'fixtures')];
let real = 0;
function walk(dir) {
    if (!fs.existsSync(dir)) return;
    for (const entry of fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
        const file = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(file);
        else if (entry.name.endsWith('.sao')) {
            try {
                const raw = fs.readFileSync(file, 'utf8');
                const parts = compiler.parseSaoFile(raw, file);
                const states = [...new Set((raw.match(/\$[A-Za-z_]\w*/g) || []).map(v => v.slice(1)))].sort();
                process.stdout.write(JSON.stringify({ name: path.relative(repo, file), source: parts.blade, states, typescript: false }) + '\n');
                real++;
            } catch (_) {}
        }
    }
}
for (const base of bases) walk(base);
process.stderr.write(`  ${synthetic.length} ca tổng hợp + ${real} template thật/fixture\n`);
