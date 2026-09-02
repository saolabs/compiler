#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"; WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
"$DIR/cases.js" > "$WORK/cases.jsonl"; TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' '); echo "Corpus: $TOTAL view"
"$DIR/../_golden.sh" "$DIR" < "$WORK/cases.jsonl" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt"
if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    cp "$WORK/subject.txt" "$DIR/expected.txt"
    echo "📸 golden: ghi lại expected.txt"
    exit 0
fi
if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt";then echo "✅ GOLDEN (byte): khớp $TOTAL/$TOTAL";exit 0;fi
echo "❌ BYTE GOLDEN LỆCH"; "$DIR/diagnose.php" "$WORK/oracle.txt" "$WORK/subject.txt"; exit 1
