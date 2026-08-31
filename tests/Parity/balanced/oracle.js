#!/usr/bin/env node
/**
 * Oracle: chạy CẢ HAI biến thể quét ngoặc của bản JS.
 *
 *   symbol-collector.js      — _splitTopLevel / _findAssignmentEquals  (3 bộ đếm)
 *   expression-transformer.js — _splitTopLevelStr / _findFirstEquals    (1 bộ đếm)
 *
 * Hai bản này KHÔNG giống nhau; cổng phải kiểm cả hai.
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

const PRE = path.join(repoRoot(), 'builder', 'src', 'preprocessor');
const SymbolCollector = require(path.join(PRE, 'symbol-collector.js'));
const ExpressionTransformer = require(path.join(PRE, 'expression-transformer.js'));

const collector = new SymbolCollector();
const transformer = new ExpressionTransformer(collector, {});

function run(label, fn) {
    try {
        return { ok: true, value: fn() };
    } catch (error) {
        return { ok: false, value: error.constructor.name };
    }
}

for (const line of fs.readFileSync(0, 'utf-8').split('\n')) {
    if (!line.trim()) continue;
    const input = JSON.parse(line);

    // Báo CẶP CHUỖI hai bên dấu '=' chứ không phải chỉ số thô.
    //
    // JS đánh chỉ số theo code unit UTF-16, PHP theo byte — với "tên = 1" thì
    // JS trả 4 còn PHP trả 5. Nhưng cả hai đều cắt chuỗi bằng chỉ số của CHÍNH
    // MÌNH, nên hai nửa thu được giống hệt nhau. Cái cần khớp là hành vi quan
    // sát được, không phải biểu diễn nội bộ.
    const halves = (fn) => run('x', () => {
        const at = fn();
        return at === -1 ? null : [input.slice(0, at), input.slice(at + 1)];
    });

    const result = {
        splitStrict: run('a', () => collector._splitTopLevel(input, ',')),
        findStrict: halves(() => collector._findAssignmentEquals(input)),
        splitLoose: run('c', () => transformer._splitTopLevelStr(input, ',')),
        findLoose: halves(() => transformer._findFirstEquals(input)),
        extract: run('e', () => collector._extractBalancedParens(input, 0)),
    };

    process.stdout.write(`${JSON.stringify(input)}\t${JSON.stringify(result)}\n`);
}
