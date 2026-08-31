#!/usr/bin/env node
/**
 * Oracle: chạy preprocessor bằng chính bản JS trong builder/src/preprocessor.
 *
 * Tách file bằng parseSaoFile của JS luôn — SourceSplitter đã có cổng parity
 * riêng, nên mọi khác biệt lộ ra ở đây đều thuộc về preprocessor.
 *
 * stdin  : mỗi dòng một đường dẫn .sao
 * stdout : <đường dẫn><TAB><JSON phần đã transform>
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
const Compiler = require(path.join(ROOT, 'builder', 'src', 'index.js'));
const SaolaPreprocessor = require(path.join(ROOT, 'builder', 'src', 'preprocessor'));

// Cố định để kết quả tái lập được; giá trị thật do index.js dựng từ sao.config
const ASSET_PREFIX = 'static/saola/web/assets/';

const compiler = new Compiler();

for (const line of fs.readFileSync(0, 'utf-8').split('\n')) {
    const file = line.trim();
    if (!file) continue;

    let payload;
    try {
        const parts = compiler.parseSaoFile(fs.readFileSync(file, 'utf-8'), file);
        // Instance mới mỗi file: symbolCollector giữ trạng thái giữa các lần gọi
        const out = new SaolaPreprocessor().preprocess(parts, { assetPrefix: ASSET_PREFIX });
        payload = {
            declarations: out.declarations,
            blade: out.blade,
            bladeWithSSR: out.bladeWithSSR === undefined ? null : out.bladeWithSSR,
        };
    } catch (error) {
        payload = { __error__: error.constructor.name, __message__: String(error.message) };
    }

    process.stdout.write(`${path.relative(ROOT, file)}\t${JSON.stringify(payload)}\n`);
}
