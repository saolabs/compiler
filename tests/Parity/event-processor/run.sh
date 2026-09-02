#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")"&&pwd)";WORK="$(mktemp -d)";trap 'rm -rf "$WORK"' EXIT
cat "$DIR/cases.jsonl" >"$WORK/cases" 2>"$WORK/corpus";TOTAL=$(wc -l<"$WORK/cases"|tr -d ' ');echo "Corpus: $TOTAL event ($(cat "$WORK/corpus"|xargs)); 3 phép/event"
PYTHONHASHSEED=0 "$DIR/../_golden.sh" "$DIR"<"$WORK/cases">"$WORK/oracle";"$DIR/subject.php"<"$WORK/cases">"$WORK/subject"
if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    cp "$WORK/subject" "$DIR/expected.txt"
    echo "📸 golden: ghi lại expected.txt"
    exit 0
fi
if diff -u "$WORK/oracle" "$WORK/subject">"$WORK/diff";then echo "✅ GOLDEN: khớp $TOTAL/$TOTAL event";exit 0;fi
echo "❌ GOLDEN LỆCH";grep -E '^[-+]\{' "$WORK/diff"|head -40;exit 1
