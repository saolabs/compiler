#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
"$DIR/cases.js" > "$WORK/cases.jsonl"; TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' '); echo "Corpus: $TOTAL view"
"$DIR/oracle.py" < "$WORK/cases.jsonl" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt"
if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt";then echo "✅ BYTE PARITY: khớp $TOTAL/$TOTAL";exit 0;fi
echo "❌ BYTE PARITY HỎNG"; "$DIR/diagnose.py" "$WORK/oracle.txt" "$WORK/subject.txt"; exit 1
