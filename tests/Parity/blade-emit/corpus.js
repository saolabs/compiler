#!/usr/bin/env node
/**
 * Dựng corpus đầu vào cho sao2blade.
 *
 * sao2blade KHÔNG nhận file .sao thô — nó nhận chuỗi blade đã ráp mà
 * index.js::processSaoFile dựng lên: khai báo đã qua preprocessor + template
 * bọc trong thẻ wrapper + các khối <style scoped> nguyên văn.
 *
 * File này tái dựng đúng chuỗi đó bằng bản JS, để oracle Python và subject PHP
 * cùng nhận MỘT đầu vào — mọi khác biệt lộ ra đều thuộc về emitter.
 *
 * stdin  : mỗi dòng một đường dẫn .sao
 * stdout : <đường dẫn><TAB><JSON chuỗi blade đã ráp>
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

const ASSET_PREFIX = 'static/saola/web/assets/';
const compiler = new Compiler();

for (const line of fs.readFileSync(0, 'utf-8').split('\n')) {
    const file = line.trim();
    if (!file) continue;

    let assembled;
    try {
        const fileContent = fs.readFileSync(file, 'utf-8');
        const parts = compiler.parseSaoFile(fileContent, file);
        const bladeParts = new SaolaPreprocessor().preprocess(parts, { assetPrefix: ASSET_PREFIX });

        assembled = '';
        if (bladeParts.declarations.length > 0) {
            assembled = bladeParts.declarations.join('\n') + '\n\n';
        }

        const templateContent = bladeParts.bladeWithSSR || bladeParts.blade;
        assembled += parts.wrapperType
            ? `<${parts.wrapperType}>\n${templateContent}\n</${parts.wrapperType}>`
            : templateContent;

        // <style scoped> đi kèm nguyên văn: sao2blade hash chính nội dung CSS
        // để suy ra class scope, y hệt sao2js làm.
        const scoped = fileContent.match(/<style[^>]*\bscoped\b[^>]*>[\s\S]*?<\/style>/gi) || [];
        if (scoped.length) assembled += '\n' + scoped.join('\n');
    } catch (error) {
        assembled = `__CORPUS_ERROR__ ${error.constructor.name}`;
    }

    process.stdout.write(`${path.relative(ROOT, file)}\t${JSON.stringify(assembled)}\n`);
}
