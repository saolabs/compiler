#!/usr/bin/env node
/**
 * Oracle: chạy TOÀN BỘ pipeline cũ — Node ráp đầu vào, Python sinh output.
 *
 *   parseSaoFile → preprocess → ráp → sao2blade + sao2js → injectSsrStylesheets
 *
 * Chính là những gì index.js làm trước khi chuyển sang gọi php bin/saoc.
 */
'use strict';
const fs = require('fs'), path = require('path'), os = require('os');
const { execFileSync } = require('child_process');

function repoRoot() {
    let d = __dirname;
    while (d !== path.dirname(d)) { d = path.dirname(d); if (fs.existsSync(path.join(d, 'builder', 'src', 'index.js'))) return d; }
    throw new Error('Không tìm thấy repo root');
}
const ROOT = repoRoot();
const Compiler = require(path.join(ROOT, 'builder', 'src', 'index.js'));
const SaolaPreprocessor = require(path.join(ROOT, 'builder', 'src', 'preprocessor'));
const BLADE_CLI = path.join(ROOT, 'builder', '.reference', 'python', 'src', 'sao2blade', 'cli.py');
const JS_CLI = path.join(ROOT, 'builder', '.reference', 'python', 'src', 'sao2js', 'cli.py');
const ASSET_PREFIX = 'static/parity/assets/';

const compiler = new Compiler();
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'saola-fp-'));

function runPython(cli, input, args) {
    const inFile = path.join(tmp, 'in.txt'), outFile = path.join(tmp, 'out.txt');
    fs.writeFileSync(inFile, input, 'utf-8');
    if (fs.existsSync(outFile)) fs.unlinkSync(outFile);
    execFileSync('python3', [cli, inFile, outFile, ...args], { stdio: ['ignore', 'ignore', 'ignore'] });
    return fs.readFileSync(outFile, 'utf-8');
}


/**
 * Làm trắng `{{-- --}}` và `@verbatim`, GIỮ độ dài (nên offset còn dùng được).
 * Song sinh với Saola\Compiler\Support\BladeComment::blank().
 */
function blankComments(text) {
    if (!text) return text;
    return text.replace(
        /\{\{--[\s\S]*?--\}\}|@verbatim\b[\s\S]*?@endverbatim\b/gi,
        m => m.replace(/[^\n]/g, ' ')
    );
}

for (const raw of fs.readFileSync(0, 'utf-8').split('\n')) {
    if (!raw.trim()) continue;
    const c = JSON.parse(raw);
    let result;
    try {
        const parts = compiler.parseSaoFile(c.source, c.name);
        const bladeParts = new SaolaPreprocessor().preprocess(parts, { assetPrefix: ASSET_PREFIX });

        // Gom asset trên bản ĐÃ LÀM TRẮNG comment, cắt từ GỐC theo offset —
        // `<script>` nhắc trong chú thích mở một match chạy tới `</script>`
        // THẬT. Song sinh với SaolaCompiler::buildJsInput().
        const scanAssets = blankComments(parts.cleanedContent);
        const pick = re => {
            const out = [];
            const g = new RegExp(re.source, re.flags.includes('g') ? re.flags : re.flags + 'g');
            let m;
            while ((m = g.exec(scanAssets)) !== null) {
                out.push(parts.cleanedContent.slice(m.index, m.index + m[0].length));
            }
            return out;
        };
        const scripts = pick(/<script[^>]*>[\s\S]*?<\/script>/gi);
        const styles = pick(/<style[^>]*>[\s\S]*?<\/style>/gi);
        const links = pick(/<link\b(?=[^>]*\brel\s*=\s*["'][^"']*\bstylesheet\b[^"']*["'])[^>]*>/gi);

        // ── đầu vào Blade ──
        let bladeInput = bladeParts.declarations.length ? bladeParts.declarations.join('\n') + '\n\n' : '';
        const tpl = bladeParts.bladeWithSSR || bladeParts.blade;
        bladeInput += parts.wrapperType ? `<${parts.wrapperType}>\n${tpl}\n</${parts.wrapperType}>` : tpl;
        const scoped = c.source.match(/<style[^>]*\bscoped\b[^>]*>[\s\S]*?<\/style>/gi) || [];
        if (scoped.length) bladeInput += '\n' + scoped.join('\n');

        // ── đầu vào JS ──
        let jsInput = bladeParts.declarations.length ? bladeParts.declarations.join('\n') + '\n\n' : '';
        if (scripts.length || styles.length || links.length) jsInput += [...scripts, ...styles, ...links].join('\n') + '\n\n';
        jsInput += parts.wrapperType ? `<${parts.wrapperType}>\n${bladeParts.blade}\n</${parts.wrapperType}>` : bladeParts.blade;

        const blade = compiler.injectSsrStylesheets(runPython(BLADE_CLI, bladeInput, []), links);
        // KHÔNG truyền lang: cli.py chỉ nhận (in, out, fn, view, factory) và
        // main_compiler tự dò TS từ `<script setup lang="ts">` trong nguồn.
        const js = runPython(JS_CLI, jsInput, [c.fn, c.view, c.factory]);

        result = { ok: true, blade: Buffer.from(blade).toString('base64'), js: Buffer.from(js).toString('base64') };
    } catch (e) {
        result = { ok: false, error: e.constructor.name + ':' + String(e.message).slice(0, 200) };
    }
    process.stdout.write(c.name + '\t' + JSON.stringify(result) + '\n');
}
fs.rmSync(tmp, { recursive: true, force: true });
