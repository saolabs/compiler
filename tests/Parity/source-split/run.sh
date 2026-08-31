#!/usr/bin/env bash
#
# Cổng parity cho SourceSplitter (docs/05-roadmap.md — Phase 1).
#
# Oracle ở đây là JAVASCRIPT (builder/src/index.js::parseSaoFile), không phải
# Python — phần này nằm ở phía Node.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Đi ngược lên tới thư mục chứa builder/src — không phụ thuộc độ sâu
ROOT="$DIR"
while [[ "$ROOT" != "/" && ! -d "$ROOT/builder/src" ]]; do
    ROOT="$(dirname "$ROOT")"
done
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# File thật chứng minh không hồi quy; fixture ép các ca biên mà 56 view không
# chạm tới (thẻ bọc lồng nhau, thẻ không đóng, BOM, khai báo trùng, @ssr...).
find "$ROOT/saola/resources" -name '*.sao' | sort > "$WORK/files.txt"
REAL=$(wc -l < "$WORK/files.txt" | tr -d ' ')
find "$DIR/fixtures" -name '*.sao' | sort >> "$WORK/files.txt"
TOTAL=$(wc -l < "$WORK/files.txt" | tr -d ' ')
echo "Corpus: $REAL file .sao thật + $((TOTAL - REAL)) fixture = $TOTAL"

"$DIR/oracle.js"   < "$WORK/files.txt" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/files.txt" > "$WORK/subject.txt"

if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ PARITY: khớp $TOTAL/$TOTAL file"
    exit 0
fi

MISMATCH=$(grep -c '^-[a-z]' "$WORK/diff.txt" || true)
echo "❌ PARITY HỎNG: $MISMATCH / $TOTAL file lệch"
echo
echo "File lệch đầu tiên:"
grep -E '^[-+][a-z]' "$WORK/diff.txt" | head -2 | cut -c1-400
exit 1
