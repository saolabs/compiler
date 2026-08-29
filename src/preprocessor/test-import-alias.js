/**
 * Self-check cho alias @import dùng ở directive nhận đường dẫn view.
 *
 * Alias là ĐIỂM NEO lúc biên dịch, không phải biến. Trước khi có
 * _resolveViewAlias, `@extends(layout)` ra `@extends($layout)` — biến PHP không
 * tồn tại ở SSR, còn JS ra `superView: layout` → ReferenceError. Lệch âm thầm.
 *
 * Chạy: node src/preprocessor/test-import-alias.js
 */
const assert = require('assert');
const path = require('path');
const { execFileSync } = require('child_process');
const SaolaPreprocessor = require('./index');
const ExpressionTransformer = require('./expression-transformer');

function run(content) {
    return new SaolaPreprocessor().preprocessRaw(content);
}

/** Tên suy ra phải KHỚP Python — nếu lệch thì thẻ và @extends trỏ hai nơi. */
function checkDeriveMatchesPython() {
    const cases = [
        "__layout__ + 'docs'",
        "__layout__ + 'docs.test-layout'",
        "__template__ + 'sessions.tasks'",
        "'a'",
        '"b.d"',
        "'web.components.code-block'",
        '__blade_custom_path__',
    ];

    const py = `
import json, sys
sys.path.insert(0, ${JSON.stringify(path.join(__dirname, '..'))})
from common.import_parser import ImportParser
p = ImportParser()
print(json.dumps([p._extract_tag_from_path(c) for c in json.load(sys.stdin)]))
`;
    const out = execFileSync('python3', ['-c', py], { input: JSON.stringify(cases) });
    const fromPython = JSON.parse(out.toString());
    const fromJs = cases.map(c => ExpressionTransformer.deriveImportName(c));

    assert.deepStrictEqual(fromJs, fromPython,
        'JS và Python suy tên @import khác nhau:\n' +
        cases.map((c, i) => `  ${c} → js=${fromJs[i]} py=${fromPython[i]}`).join('\n'));
}

function demo() {
    // 1. @extends(alias) phải ra ĐÚNG path, không phải $alias
    const out = run(`@import(__layout__ + 'base' as layout)
@states({ x: 1 })
<template>
    @extends(layout)
    <p>{{ x }}</p>
</template>`);
    assert(/@extends\(\s*\$__layout__\s*\.\s*'base'\s*\)/.test(out), out);
    assert(!/@extends\(\s*\$layout\s*\)/.test(out), 'alias vẫn bị coi là biến:\n' + out);

    // 2. Viết thẳng path cho ra Y HỆT dạng alias — đây là điều kiện để
    //    sao2blade và sao2js không thể trỏ hai nơi khác nhau.
    const direct = run(`@states({ x: 1 })
<template>
    @extends(__layout__ + 'base')
    <p>{{ x }}</p>
</template>`);
    const strip = s => s.replace(/@import\([^)]*\)\s*/g, '').trim();
    assert.strictEqual(strip(out), strip(direct));

    // 3. @include(alias) + props giữ nguyên đối số sau
    const inc = run(`@import('web.parts.card' as card)
@states({ n: 0 })
<template>
    @include(card, {value: n})
</template>`);
    assert(/@include\('web\.parts\.card',\s*\['value'\s*=>\s*\$n\]\)/.test(inc), inc);

    // 4. Dạng object @import({tên: path})
    const obj = run(`@import({ shell: __layout__ + 'shell' })
@states({ x: 1 })
<template>
    @extends(shell)
    <p>{{ x }}</p>
</template>`);
    assert(/@extends\(\s*\$__layout__\s*\.\s*'shell'\s*\)/.test(obj), obj);

    // 5. KHÔNG có 'as' → tên suy ra từ đoạn chuỗi cuối, dùng được luôn
    const noAs = run(`@import(__layout__ + 'docs')
@states({ x: 1 })
<template>
    @extends(docs)
</template>`);
    assert(/@extends\(\s*\$__layout__\s*\.\s*'docs'\s*\)/.test(noAs), noAs);

    // 6. Tên suy ra có dấu '-' vẫn dùng được: alias khớp NGUYÊN đối số nên
    //    không bị tokenizer đọc thành phép trừ ($test - $layout).
    const kebab = run(`@import(__layout__ + 'a.test-layout')
@states({ x: 1 })
<template>
    @extends(test-layout)
</template>`);
    assert(/@extends\(\s*\$__layout__\s*\.\s*'a\.test-layout'\s*\)/.test(kebab), kebab);
    assert(!/\$test\s*-\s*\$layout/.test(kebab), 'kebab bị đọc thành phép trừ:\n' + kebab);

    // 7. Alias tường minh thắng tên suy ra khi trùng
    const win = run(`@import('x.docs')
@import(__layout__ + 'other' as docs)
@states({ x: 1 })
<template>
    @extends(docs)
</template>`);
    assert(/@extends\(\s*\$__layout__\s*\.\s*'other'\s*\)/.test(win), win);

    // 8. Không phải alias thì KHÔNG đụng vào
    const plain = run(`@import('web.parts.card' as card)
@states({ x: 1 })
<template>
    @extends(__layout__ + 'docs')
    @include('web.parts.other')
</template>`);
    assert(/@extends\(\s*\$__layout__\s*\.\s*'docs'\s*\)/.test(plain), plain);
    assert(plain.includes("@include('web.parts.other')"), plain);

    // 9. Alias chỉ khớp identifier TRẦN ở đúng vị trí đối số đường dẫn;
    //    state trùng tên ở chỗ khác vẫn là biến.
    const noTouch = run(`@import('web.parts.card' as card)
@states({ card: 'x' })
<template>
    <p>{{ card }}</p>
</template>`);
    assert(noTouch.includes('$card'), noTouch);

    checkDeriveMatchesPython();

    console.log('test-import-alias: OK');
}

demo();
