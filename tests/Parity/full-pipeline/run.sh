#!/usr/bin/env bash
#
# Cổng parity ĐẦU-CUỐI (docs/05-roadmap.md — cổng nghiệm thu P3/P4).
#
# Các cổng khác kiểm TỪNG MODULE. Cổng này đi qua API công khai
# `SaolaCompiler::compile()` — thứ tự ráp có thể sai dù mọi mảnh đều đúng, và
# không cổng nào khác chạm tới đường đó.
#
# Oracle là pipeline CŨ đầy đủ: Node ráp đầu vào (parseSaoFile → preprocess →
# ráp → injectSsrStylesheets) còn Python sinh output (sao2blade + sao2js).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

"$DIR/cases.js" > "$WORK/cases.jsonl" 2>/dev/null
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
echo "Corpus: $TOTAL file .sao (lang dò từ nguồn, như index.js)"

"$DIR/../_golden.sh" "$DIR" < "$WORK/cases.jsonl" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt"

if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    cp "$WORK/subject.txt" "$DIR/expected.txt"
    echo "📸 golden: ghi lại expected.txt"
    exit 0
fi
if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ GOLDEN (byte) đầu-cuối: khớp $TOTAL/$TOTAL"
    exit 0
fi

echo "❌ GOLDEN đầu-cuối LỆCH: $(grep -c '^-[a-z]' "$WORK/diff.txt" || true) / $TOTAL ca lệch"
echo
"$DIR/report-diff.php" "$WORK/oracle.txt" "$WORK/subject.txt"
exit 1
