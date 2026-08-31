#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")"&&pwd)";WORK="$(mktemp -d)";trap 'rm -rf "$WORK"' EXIT
"$DIR/cases.py" >"$WORK/cases" 2>"$WORK/corpus";TOTAL=$(wc -l<"$WORK/cases"|tr -d ' ');echo "Corpus: $TOTAL event ($(cat "$WORK/corpus"|xargs)); 3 phép/event"
PYTHONHASHSEED=0 "$DIR/oracle.py"<"$WORK/cases">"$WORK/oracle";"$DIR/subject.php"<"$WORK/cases">"$WORK/subject"
if diff -u "$WORK/oracle" "$WORK/subject">"$WORK/diff";then echo "✅ PARITY: khớp $TOTAL/$TOTAL event";exit 0;fi
echo "❌ PARITY HỎNG";grep -E '^[-+]\{' "$WORK/diff"|head -40;exit 1
