#!/usr/bin/env node
/**
 * Dựng project sandbox cho cổng node-transport.
 *
 * Không đụng vào project thật: tạo một cây thư mục tạm có sao.config.json
 * riêng, copy view thật vào, cộng thêm các file "stress" được thiết kế để ép
 * ký tự nhiều byte rơi đúng ranh giới chunk của stdout.
 *
 * Viết bằng Node vì cổng này vốn đã là cổng Node — dự án chỉ còn PHP và JS/TS.
 *
 * Dùng: make-corpus.js <thư-mục-đích>
 */
'use strict';
const fs = require('fs');
const path = require('path');

function repoRoot() {
    let p = __dirname;
    while (p !== path.dirname(p)) {
        p = path.dirname(p);
        if (fs.existsSync(path.join(p, 'builder', 'src'))) return p;
    }
    throw new Error('Không tìm thấy repo root');
}
const ROOT = repoRoot();

const CONFIG = {
    paths: {
        resources: 'resources',
        saoView: 'resources/saola',
        bladeView: 'resources/views',
        compiled: 'resources/js/saola',
        public: 'public/static/saola',
    },
    contexts: {
        web: {
            name: 'Web',
            app: [],
            views: { web: 'web/views' },
            blade: { web: 'web' },
            compiled: { views: 'web/views', app: 'web/app', registry: 'web/registry.js' },
            registry: { lazy: false },
        },
    },
};

/**
 * View sinh output LỚN và DÀY ĐẶC ký tự nhiều byte.
 *
 * Node đọc pipe theo chunk (mặc định 64 KB). Bug ⑤ sinh ra khi một ký tự UTF-8
 * nằm vắt qua ranh giới chunk.
 *
 * Hai điều làm việc bắt lỗi thành CHẮC CHẮN thay vì may rủi:
 *
 *   1. Nội dung gần như toàn ký tự nhiều byte, không có khoảng ASCII dài — nên
 *      hầu hết mọi vị trí byte đều nằm GIỮA một ký tự.
 *   2. Nhiều file với kích thước LỆCH NHAU — mỗi file được chunk độc lập, nên
 *      ranh giới rơi vào nhiều offset nội dung khác nhau.
 *
 * Chỉ dựa vào một view thật là mong manh: sửa nội dung view đó một chút là cổng
 * mất răng mà không ai biết.
 */
function stressSources() {
    // Trộn ký tự 2 byte (á, à, ô) và 3 byte (ộ, ề, ữ) để phần dư offset trải đều
    const dense = 'áàảãạăắằẳđêếềểộợựữứừốồổỗơớờởãõũụ';
    const out = {};

    [700, 733, 769, 811, 853, 907, 971, 1033].forEach((rows, idx) => {
        const lines = ['@states({ n: 0 })', '<template>', '<div class="stress">'];
        for (let i = 0; i < rows; i++) {
            // độ dài lệch nhau theo dòng → offset tích luỹ trải rộng
            const width = 60 + ((i * 7 + idx * 13) % 41);
            const body = [...dense.repeat(10)].slice(0, width).join('');
            lines.push(`    <p class="l${i}">${body}{{ n }}${body}</p>`);
        }
        lines.push('</div>', '</template>');
        out[`zz-stress-${idx}.sao`] = lines.join('\n') + '\n';
    });

    return out;
}

function copySao(from, to) {
    let copied = 0;
    for (const entry of fs.readdirSync(from, { withFileTypes: true }).sort((a, b) => a.name < b.name ? -1 : 1)) {
        const src = path.join(from, entry.name);
        if (entry.isDirectory()) {
            copied += copySao(src, path.join(to, entry.name));
        } else if (entry.name.endsWith('.sao')) {
            fs.mkdirSync(to, { recursive: true });
            fs.copyFileSync(src, path.join(to, entry.name));
            copied++;
        }
    }
    return copied;
}

const dest = process.argv[2];
const views = path.join(dest, 'resources', 'saola', 'web', 'views');
fs.mkdirSync(views, { recursive: true });
fs.writeFileSync(path.join(dest, 'sao.config.json'), JSON.stringify(CONFIG, null, 4), 'utf8');

// View thật: giữ nguyên cây thư mục để view path không đổi
const real = path.join(ROOT, 'saola', 'resources', 'saola', 'web', 'views');
const copied = fs.existsSync(real) ? copySao(real, views) : 0;

const stress = stressSources();
for (const [name, source] of Object.entries(stress)) {
    fs.writeFileSync(path.join(views, name), source, 'utf8');
}

process.stderr.write(`  ${copied} view thật + ${Object.keys(stress).length} stress\n`);
