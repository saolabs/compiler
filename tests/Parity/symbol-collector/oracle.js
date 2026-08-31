#!/usr/bin/env node
/**
 * Oracle: dựng bảng ký hiệu bằng bản JS trong builder/src/preprocessor.
 *
 * stdin  : mỗi dòng một đường dẫn .sao
 * stdout : <đường dẫn><TAB><JSON bảng ký hiệu, khoá đã sắp xếp>
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
    throw new Error('Không tìm thấy repo root');
}

const ROOT = repoRoot();
const SymbolCollector = require(path.join(ROOT, 'builder', 'src', 'preprocessor', 'symbol-collector.js'));

for (const line of fs.readFileSync(0, 'utf-8').split('\n')) {
    const file = line.trim();
    if (!file) continue;

    let payload;
    try {
        const symbols = new SymbolCollector().collect(fs.readFileSync(file, 'utf-8'));
        payload = {};
        // Sắp xếp khoá: Map của JS giữ thứ tự chèn, mảng PHP cũng vậy, nhưng
        // phép so sánh không nên phụ thuộc vào chi tiết đó.
        for (const name of [...symbols.keys()].sort()) {
            const info = symbols.get(name);
            payload[name] = {
                type: info.type,
                source: info.source,
                stateOf: info.stateOf === undefined ? null : info.stateOf,
                assetPath: info.assetPath === undefined ? null : info.assetPath,
                pattern: info.pattern === undefined ? null : info.pattern,
            };
        }
    } catch (error) {
        payload = { __error__: error.constructor.name };
    }

    process.stdout.write(`${path.relative(ROOT, file)}\t${JSON.stringify(payload)}\n`);
}
