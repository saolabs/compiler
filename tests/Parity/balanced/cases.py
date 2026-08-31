#!/usr/bin/env python3
"""Chuỗi đầu vào cho cổng Balanced.

Cổng end-to-end trên file .sao thật KHÔNG chạm tới các khác biệt tinh vi giữa
hai biến thể quét ngoặc — đã kiểm: phá `prevChar` hay đổi 3 bộ đếm thành 1 đều
không làm cổng đó đỏ, vì file thật luôn có ngoặc cân bằng.

File này ép đúng vào đó: ngoặc lệch loại, dấu '=' ở vị trí 0, nháy escape,
delimiter nằm trong chuỗi.
"""
import json
import sys

CASES = [
    # ── thường ────────────────────────────────────────────────────────
    "a, b, c",
    "a",
    "",
    "   ",
    "a, b,",
    ",a",
    # ── ngoặc lồng cân bằng ───────────────────────────────────────────
    "f(a, b), c",
    "[1, 2], 3",
    "{a: 1, b: 2}, c",
    "{ items: arr[0], count: 2 }",
    "f(g(h(a, b))), c",
    # ── NGOẶC LỆCH LOẠI — nơi 3-bộ-đếm khác 1-bộ-đếm ─────────────────
    "(a], b",
    "[a), b",
    "{a], b",
    "(a, b],  c",
    "[[a), b]",
    "{(a], b}",
    "a), b",
    "a(, b",
    "a], b",
    "a[, b",
    # ── chuỗi & escape ────────────────────────────────────────────────
    "'a, b', c",
    '"a, b", c',
    "`a, b`, c",
    r"'có \' escape, vẫn trong chuỗi', sau",
    r'"escape \" rồi, vẫn trong", sau',
    "'chuỗi chưa đóng, phần còn lại",
    r"'\\', a",
    # ── dấu '=' ───────────────────────────────────────────────────────
    "=x",
    "=x!",
    "!=x",
    "a = b",
    "a == b",
    "a === b",
    "a => b",
    "a != b",
    "a <= b",
    "a >= b",
    "a = b = c",
    "f(a = 1) = 2",
    "[a, b] = f(x)",
    "{a, b} = f(x)",
    "'a = b' = c",
    "a[0] = 1",
    "= ",
    "=",
    # ── tiếng Việt ────────────────────────────────────────────────────
    "tên = 'giá trị', khác = 2",
    "'chào, bạn', x",
]


def main() -> int:
    for case in CASES:
        print(json.dumps(case, ensure_ascii=False))
    print(f'  {len(CASES)} chuỗi', file=sys.stderr)
    return 0


if __name__ == '__main__':
    sys.exit(main())
