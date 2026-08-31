#!/usr/bin/env python3
"""Dựng project sandbox cho cổng node-transport.

Không đụng vào project thật: tạo một cây thư mục tạm có sao.config.json riêng,
copy view thật vào, cộng thêm một file "stress" được thiết kế để ép ký tự
nhiều byte rơi đúng ranh giới chunk của stdout.

Dùng: make-corpus.py <thư-mục-đích>
"""
import json
import os
import shutil
import sys


def repo_root() -> str:
    p = os.path.abspath(__file__)
    while p != os.path.dirname(p):
        p = os.path.dirname(p)
        if os.path.isdir(os.path.join(p, 'builder', 'src')):
            return p
    raise RuntimeError('Không tìm thấy repo root')


ROOT = repo_root()

CONFIG = {
    "paths": {
        "resources": "resources",
        "saoView": "resources/saola",
        "bladeView": "resources/views",
        "compiled": "resources/js/saola",
        "public": "public/static/saola",
    },
    "contexts": {
        "web": {
            "name": "Web",
            "app": [],
            "views": {"web": "web/views"},
            "blade": {"web": "web"},
            "compiled": {"views": "web/views", "app": "web/app", "registry": "web/registry.js"},
            "registry": {"lazy": False},
        }
    },
}


def stress_sources() -> dict[str, str]:
    """View sinh output LỚN và DÀY ĐẶC ký tự nhiều byte.

    Node đọc pipe theo chunk (mặc định 64 KB). Bug ⑤ sinh ra khi một ký tự
    UTF-8 nằm vắt qua ranh giới chunk.

    Hai điều làm việc bắt lỗi thành CHẮC CHẮN thay vì may rủi:

      1. Nội dung gần như toàn ký tự nhiều byte, không có khoảng ASCII dài —
         nên hầu hết mọi vị trí byte đều nằm GIỮA một ký tự.
      2. Nhiều file với kích thước LỆCH NHAU — mỗi file được chunk độc lập,
         nên ranh giới rơi vào nhiều offset nội dung khác nhau.

    Chỉ dựa vào một view thật (như docs/directives.sao) là mong manh: sửa nội
    dung view đó một chút là cổng mất răng mà không ai biết.
    """
    # Trộn ký tự 2 byte (á, à, ô) và 3 byte (ộ, ề, ữ) để phần dư offset trải đều
    dense = 'áàảãạăắằẳđêếềểộợựữứừốồổỗơớờởãõũụ'
    out = {}

    for idx, rows in enumerate([700, 733, 769, 811, 853, 907, 971, 1033]):
        lines = ['@states({ n: 0 })', '<template>', '<div class="stress">']
        for i in range(rows):
            # độ dài lệch nhau theo dòng → offset tích luỹ trải rộng
            width = 60 + (i * 7 + idx * 13) % 41
            body = (dense * 10)[:width]
            lines.append(f'    <p class="l{i}">{body}{{{{ n }}}}{body}</p>')
        lines += ['</div>', '</template>']
        out[f'zz-stress-{idx}.sao'] = '\n'.join(lines) + '\n'

    return out


def main() -> int:
    dest = sys.argv[1]
    views = os.path.join(dest, 'resources', 'saola', 'web', 'views')
    os.makedirs(views, exist_ok=True)

    with open(os.path.join(dest, 'sao.config.json'), 'w', encoding='utf-8') as f:
        json.dump(CONFIG, f, indent=4, ensure_ascii=False)

    # View thật: giữ nguyên cây thư mục để view path không đổi
    real = os.path.join(ROOT, 'saola', 'resources', 'saola', 'web', 'views')
    copied = 0
    if os.path.isdir(real):
        for dirpath, _, files in os.walk(real):
            for name in sorted(files):
                if not name.endswith('.sao'):
                    continue
                rel = os.path.relpath(os.path.join(dirpath, name), real)
                target = os.path.join(views, rel)
                os.makedirs(os.path.dirname(target), exist_ok=True)
                shutil.copy2(os.path.join(dirpath, name), target)
                copied += 1

    stress = stress_sources()
    for name, source in stress.items():
        with open(os.path.join(views, name), 'w', encoding='utf-8') as f:
            f.write(source)

    print(f'  {copied} view thật + {len(stress)} stress', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main())
