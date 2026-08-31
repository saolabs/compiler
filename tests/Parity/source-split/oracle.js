#!/usr/bin/env node
/**
 * Oracle: tách file .sao bằng CHÍNH parseSaoFile của Saola Builder.
 *
 * Khác các cổng khác, oracle ở đây là JavaScript chứ không phải Python —
 * parseSaoFile nằm ở phía Node (builder/src/index.js). Xem
 * docs/01-architecture.md §3 về việc vì sao phần này cũng phải port.
 *
 * stdin  : mỗi dòng một đường dẫn file .sao
 * stdout : <đường dẫn><TAB><JSON các phần tách được>
 */
'use strict';

const fs = require('fs');
const path = require('path');

function repoRoot() {
    let dir = __dirname;
    while (dir !== path.dirname(dir)) {
        dir = path.dirname(dir);
        if (fs.existsSync(path.join(dir, 'builder', 'src', 'index.js'))) return dir;
    }
    throw new Error('Không tìm thấy repo root (thư mục chứa builder/src/index.js)');
}

const ROOT = repoRoot();
const Compiler = require(path.join(ROOT, 'builder', 'src', 'index.js'));
const compiler = new Compiler();

// `ssrContent` không được port: luôn là '' và không nơi nào đọc.
const FIELDS = ['declarations', 'blade', 'bladeWithSSR', 'script', 'style', 'cleanedContent', 'wrapperType'];

const input = fs.readFileSync(0, 'utf-8');

for (const line of input.split('\n')) {
    const file = line.trim();
    if (!file) continue;

    let payload;
    try {
        const parts = compiler.parseSaoFile(fs.readFileSync(file, 'utf-8'), file);
        payload = {};
        for (const key of FIELDS) {
            payload[key] = parts[key] === undefined ? null : parts[key];
        }
    } catch (error) {
        payload = { __error__: error.constructor.name };
    }

    process.stdout.write(`${path.relative(ROOT, file)}\t${JSON.stringify(payload)}\n`);
}
