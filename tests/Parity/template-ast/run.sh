#!/usr/bin/env bash
# Cổng golden cho TemplateASTParser (Phase 4.1).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cat "$DIR/cases.jsonl" > "$WORK/cases.jsonl" 2> "$WORK/corpus.log"
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
echo "Corpus: $TOTAL cây ($(sed 's/^ *//' "$WORK/corpus.log"))"

"$DIR/../_golden.sh" "$DIR" < "$WORK/cases.jsonl" > "$WORK/oracle.txt" 2> "$WORK/oracle.err"
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt" 2> "$WORK/subject.err"

if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    cp "$WORK/subject.txt" "$DIR/expected.txt"
    echo "📸 golden: ghi lại expected.txt"
    exit 0
fi
if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ GOLDEN: khớp $TOTAL/$TOTAL"
    exit 0
fi

echo "❌ GOLDEN LỆCH: $(grep -c '^-{' "$WORK/diff.txt" || true) / $TOTAL cây lệch"
echo
grep -E '^[-+]\{' "$WORK/diff.txt" | sed -n '1,4p' | cut -c1-600
echo
echo "Chi tiết: $WORK/diff.txt"
exit 1
