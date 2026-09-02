#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)";WORK="$(mktemp -d)";trap 'rm -rf "$WORK"' EXIT
cat "$DIR/cases.jsonl">"$WORK/cases" 2>"$WORK/log";TOTAL=$(wc -l<"$WORK/cases"|tr -d ' ')
"$DIR/../_golden.sh" "$DIR"<"$WORK/cases">"$WORK/oracle" 2>/dev/null;"$DIR/subject.php"<"$WORK/cases">"$WORK/subject" 2>/dev/null
echo "Corpus: $TOTAL dòng ($(sed 's/^ *//' "$WORK/log")); 24 phép/dòng"
if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    cp "$WORK/subject" "$DIR/expected.txt"
    echo "📸 golden: ghi lại expected.txt"
    exit 0
fi
if diff -u "$WORK/oracle" "$WORK/subject">"$WORK/diff";then echo "✅ GOLDEN: khớp $TOTAL/$TOTAL";exit 0;fi
echo "❌ GOLDEN LỆCH";sed -n '1,80p' "$WORK/diff";exit 1
