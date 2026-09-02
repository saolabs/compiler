#!/usr/bin/env bash
#
# Cổng golden cho ScopedStyle và ChildrenSlot (Phase 3).
#
# Ca then chốt: hash djb2 của scope class. Bản Python lặp theo CODEPOINT; lặp
# theo byte là hash lệch với CSS có tiếng Việt, mà scope class lệch nghĩa là
# toàn bộ CSS scoped của view đó chết.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

cat "$DIR/cases.jsonl" > "$WORK/cases.jsonl" 2>/dev/null
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
echo "Corpus: $TOTAL phép gọi"

"$DIR/../_golden.sh" "$DIR"   < "$WORK/cases.jsonl" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt"

if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    cp "$WORK/subject.txt" "$DIR/expected.txt"
    echo "📸 golden: ghi lại expected.txt"
    exit 0
fi
if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ GOLDEN: khớp $TOTAL/$TOTAL"
    exit 0
fi

echo "❌ GOLDEN LỆCH: $(grep -c '^-{' "$WORK/diff.txt" || true) phép gọi lệch"
echo
grep -E '^[-+]\{' "$WORK/diff.txt" | head -8 | cut -c1-300
exit 1
