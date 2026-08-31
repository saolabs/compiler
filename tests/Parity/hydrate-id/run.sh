#!/usr/bin/env bash
#
# Cổng parity cho HydrateId (docs/05-roadmap.md — Phase 1).
#
# So sánh bản PHP với compiler Python đang chạy production trên toàn bộ corpus,
# cả bốn mode id. Yêu cầu: 0 dòng lệch. Không phải "gần khớp".
#
#   ./run.sh            chạy đối chiếu
#   ./run.sh --rebuild  dựng lại corpus trước khi chạy
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CORPUS="$DIR/corpus.txt"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

if [[ ! -f "$CORPUS" || "${1:-}" == "--rebuild" ]]; then
    "$DIR/corpus.py" > "$CORPUS"
fi

TOTAL=$(wc -l < "$CORPUS" | tr -d ' ')
echo "Corpus: $TOTAL base_id × 4 mode = $((TOTAL * 4)) phép so sánh"

"$DIR/oracle.py"    < "$CORPUS" > "$WORK/oracle.txt"
"$DIR/subject.php"  < "$CORPUS" > "$WORK/subject.txt"

if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ PARITY: khớp $((TOTAL * 4))/$((TOTAL * 4))"
    exit 0
fi

MISMATCH=$(grep -c '^-[a-z]' "$WORK/diff.txt" || true)
echo "❌ PARITY HỎNG: $MISMATCH dòng lệch"
echo
echo "20 khác biệt đầu (- = Python đúng, + = PHP sai):"
grep -E '^[-+][a-z]' "$WORK/diff.txt" | head -40
exit 1
