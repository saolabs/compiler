#!/usr/bin/env bash
#
# Cổng parity cho Balanced — tiện ích quét ngoặc dùng chung.
#
# Tồn tại vì cổng end-to-end trên file .sao thật KHÔNG có răng ở đây: file thật
# luôn có ngoặc cân bằng, nên phá `prevChar` hay đổi 3 bộ đếm thành 1 đều không
# làm cổng kia đỏ. Cổng này ép thẳng vào input méo.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

"$DIR/cases.py" > "$WORK/cases.jsonl" 2>/dev/null
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
echo "Corpus: $TOTAL chuỗi × 5 phép quét = $((TOTAL * 5))"

"$DIR/oracle.js"   < "$WORK/cases.jsonl" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt"

if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ PARITY: khớp $((TOTAL * 5))/$((TOTAL * 5))"
    exit 0
fi

echo "❌ PARITY HỎNG: $(grep -c '^-"' "$WORK/diff.txt" || true) chuỗi lệch"
echo
grep -E '^[-+]"' "$WORK/diff.txt" | head -8 | cut -c1-300
exit 1
