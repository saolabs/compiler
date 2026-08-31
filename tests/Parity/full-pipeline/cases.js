#!/usr/bin/env node
/**
 * Dựng đầu vào cho cổng full-pipeline.
 *
 * Các cổng khác kiểm TỪNG MODULE. Cổng này kiểm API công khai
 * `SaolaCompiler::compile()` — thứ tự ráp các mảnh có thể sai dù mọi mảnh đều
 * đúng, và không cổng nào khác chạm tới đường đó.
 *
 * Đầu ra: mỗi dòng {name, source, view, fn, factory, lang}
 */
'use strict';
const fs = require('fs'), path = require('path');
function repoRoot() {
    let d = __dirname;
    while (d !== path.dirname(d)) { d = path.dirname(d); if (fs.existsSync(path.join(d, 'builder', 'src', 'index.js'))) return d; }
    throw new Error('Không tìm thấy repo root');
}
const ROOT = repoRoot();
const pascal = s => s.split(/[-_\s]+/).map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('');

function walk(dir, out) {
    if (!fs.existsSync(dir)) return out;
    for (const e of fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
        const f = path.join(dir, e.name);
        if (e.isDirectory()) walk(f, out);
        else if (e.name.endsWith('.sao')) out.push(f);
    }
    return out;
}

const files = [
    ...walk(path.join(ROOT, 'saola', 'resources'), []),
    ...walk(path.join(__dirname, '..', 'source-split', 'fixtures'), []),
    // examples/ cũng chạy qua đây: golden chỉ chứng minh "PHP không đổi", còn
    // cổng này chứng minh "PHP giống Python" — golden mới đáng tin.
    ...walk(path.join(__dirname, '..', '..', '..', 'examples', 'src'), []),
];
// 13-unclosed.sao TỪNG bị lọc khỏi corpus vì thẻ bọc không đóng cho hành vi
// không định nghĩa. Sau khi strip thẻ bọc biết đếm ĐỘ SÂU (§17), thẻ mở không
// có thẻ đóng khớp được để nguyên và CẢ HAI emitter coi nó là element HTML
// thường — hành vi đã xác định và khớp nhau, nên đưa lại vào cổng.

for (const file of files) {
    const rel = path.relative(ROOT, file);
    const stem = path.basename(file, '.sao');
    const view = 'parity.' + pascal(stem);
    const source = fs.readFileSync(file, 'utf-8');

    // Dò lang ĐÚNG như index.js:235 — Python không nhận tham số lang, nó tự dò
    // từ `<script setup lang="ts">`. Ép lang từ ngoài vào là so hai thứ khác nhau.
    const langMatch = source.match(/<script\s+setup\b[^>]*\blang=["']?([^"'\s>]+)["']?/i);
    const lang = langMatch && ['ts', 'typescript'].includes(langMatch[1].toLowerCase()) ? 'ts' : 'js';

    process.stdout.write(JSON.stringify({
        name: rel, source, view, fn: pascal(stem), factory: pascal(stem) + 'Factory', lang,
    }) + '\n');
}
process.stderr.write(`  ${files.length} file\n`);
