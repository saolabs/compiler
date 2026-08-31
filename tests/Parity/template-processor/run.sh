#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

"$DIR/cases.js" > "$WORK/cases.jsonl" 2> "$WORK/corpus.txt"
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
echo "Corpus: $TOTAL template ($(cat "$WORK/corpus.txt" | xargs))"
PYTHONHASHSEED=0 "$DIR/oracle.py" < "$WORK/cases.jsonl" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt"

if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ PARITY: khớp $TOTAL/$TOTAL template"
    exit 0
fi
echo "❌ PARITY HỎNG"
grep -E '^[-+](\{|\[)' "$WORK/diff.txt" | head -40
exit 1
