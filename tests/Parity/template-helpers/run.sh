#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cat "$DIR/cases.jsonl" > "$WORK/cases.jsonl" 2> "$WORK/corpus.log"
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
echo "Corpus: $TOTAL nguồn ($(sed 's/^ *//' "$WORK/corpus.log")); 9 phép helper/nguồn"
PYTHONHASHSEED=0 "$DIR/../_golden.sh" "$DIR" < "$WORK/cases.jsonl" > "$WORK/oracle.txt" 2> "$WORK/oracle.err"
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt" 2> "$WORK/subject.err"

if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    cp "$WORK/subject.txt" "$DIR/expected.txt"
    echo "📸 golden: ghi lại expected.txt"
    exit 0
fi
if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ GOLDEN: khớp $TOTAL/$TOTAL nguồn"
    exit 0
fi

echo "❌ GOLDEN LỆCH: $(grep -c '^-{' "$WORK/diff.txt" || true) / $TOTAL nguồn lệch"
grep -E '^[-+]\{' "$WORK/diff.txt" | sed -n '1,6p' | cut -c1-900 || true
sed -n '1,12p' "$WORK/diff.txt"
echo "Oracle stderr:"; sed -n '1,8p' "$WORK/oracle.err"
echo "PHP stderr:"; sed -n '1,8p' "$WORK/subject.err"
exit 1
