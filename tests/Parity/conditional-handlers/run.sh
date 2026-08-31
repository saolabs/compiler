#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
"$DIR/cases.py" > "$WORK/cases.jsonl"; TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
PYTHONHASHSEED=0 "$DIR/oracle.py" < "$WORK/cases.jsonl" > "$WORK/oracle.txt" 2>/dev/null
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt" 2>/dev/null
echo "Corpus: $TOTAL chuỗi thao tác conditional/switch"
if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then echo "✅ PARITY: khớp $TOTAL/$TOTAL"; exit 0; fi
echo "❌ PARITY HỎNG"; sed -n '1,50p' "$WORK/diff.txt"; exit 1
