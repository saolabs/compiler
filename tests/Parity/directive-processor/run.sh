#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)";WORK="$(mktemp -d)";trap 'rm -rf "$WORK"' EXIT
"$DIR/cases.py">"$WORK/cases" 2>"$WORK/log";TOTAL=$(wc -l<"$WORK/cases"|tr -d ' ')
"$DIR/oracle.py"<"$WORK/cases">"$WORK/oracle" 2>/dev/null;"$DIR/subject.php"<"$WORK/cases">"$WORK/subject" 2>/dev/null
echo "Corpus: $TOTAL dòng ($(sed 's/^ *//' "$WORK/log")); 24 phép/dòng"
if diff -u "$WORK/oracle" "$WORK/subject">"$WORK/diff";then echo "✅ PARITY: khớp $TOTAL/$TOTAL";exit 0;fi
echo "❌ PARITY HỎNG";sed -n '1,80p' "$WORK/diff";exit 1
