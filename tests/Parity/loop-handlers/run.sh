#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
cat "$DIR/cases.jsonl" > "$WORK/cases.jsonl"
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
PYTHONHASHSEED=0 "$DIR/../_golden.sh" "$DIR" < "$WORK/cases.jsonl" > "$WORK/oracle.txt" 2>/dev/null
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt" 2>/dev/null
echo "Corpus: $TOTAL chuỗi thao tác loop"
if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    cp "$WORK/subject.txt" "$DIR/expected.txt"
    echo "📸 golden: ghi lại expected.txt"
    exit 0
fi
if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ GOLDEN: khớp $TOTAL/$TOTAL"
    exit 0
fi
echo "❌ GOLDEN LỆCH"
sed -n '1,60p' "$WORK/diff.txt"
exit 1
