#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

"$DIR/cases.py" > "$WORK/cases.jsonl" 2> "$WORK/corpus.log"
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
echo "Corpus: $TOTAL nguồn ($(sed 's/^ *//' "$WORK/corpus.log")); 14 phép parse/nguồn"
"$DIR/oracle.py" < "$WORK/cases.jsonl" > "$WORK/oracle.txt" 2>/dev/null
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt" 2>/dev/null

if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ PARITY: khớp $TOTAL/$TOTAL nguồn"
    exit 0
fi

echo "❌ PARITY HỎNG: $(grep -c '^-{' "$WORK/diff.txt" || true) / $TOTAL nguồn lệch"
grep -E '^[-+]\{' "$WORK/diff.txt" | sed -n '1,4p' | cut -c1-700
exit 1
