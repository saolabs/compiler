#!/usr/bin/env bash
#
# Cổng parity cho chuyển đổi biểu thức (docs/05-roadmap.md — Phase 1).
#
# Corpus là biểu thức THẬT, bóc bằng cách gài spy vào php_to_js() rồi compile
# cả 56 view. Không phải test bịa — đây là phân bố input thật của app.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CORPUS="$DIR/corpus.tsv"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if [[ ! -f "$CORPUS" || "${1:-}" == "--rebuild" ]]; then
    "$DIR/corpus.py" > "$CORPUS"
fi

# Corpus thật chứng minh không hồi quy; corpus tổng hợp ép vào đúng các nhánh
# THẬT SỰ biến đổi (87% biểu thức thật đi qua converter mà không đổi).
cat "$CORPUS" > "$WORK/input.tsv"
"$DIR/synthetic.py" >> "$WORK/input.tsv" 2>/dev/null

REAL=$(wc -l < "$CORPUS" | tr -d ' ')
TOTAL=$(wc -l < "$WORK/input.tsv" | tr -d ' ')
echo "Corpus: $REAL biểu thức thật (56 view) + $((TOTAL - REAL)) tổng hợp = $TOTAL"

"$DIR/oracle.py"   < "$WORK/input.tsv" > "$WORK/oracle.txt" 2>/dev/null
"$DIR/subject.php" < "$WORK/input.tsv" > "$WORK/subject.txt" 2>/dev/null

if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ PARITY: khớp $TOTAL/$TOTAL"
    exit 0
fi

MISMATCH=$(grep -c '^-php_to_js' "$WORK/diff.txt" || true)
echo "❌ PARITY HỎNG: $MISMATCH / $TOTAL biểu thức lệch"
echo
echo "10 khác biệt đầu (- = Python đúng, + = PHP sai):"
grep -E '^[-+]php_to_js' "$WORK/diff.txt" | head -20
exit 1
