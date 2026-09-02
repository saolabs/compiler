#!/usr/bin/env bash
#
# Cổng golden cho HydrateIdGenerator (docs/05-roadmap.md — Phase 1).
#
# Chạy cùng một dãy thao tác ngẫu nhiên qua cả hai bản cài đặt, so từng giá trị
# trả về. Yêu cầu: 0 dòng lệch.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OPS_COUNT="${1:-5000}"
SEED="${2:-20260830}"
export SAOLA_GOLDEN_KEY="$OPS_COUNT-$SEED"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

"$DIR/ops.php" "$OPS_COUNT" "$SEED" > "$WORK/ops.jsonl"
echo "Dãy thao tác: $OPS_COUNT (seed $SEED)"

"$DIR/../_golden.sh" "$DIR"   < "$WORK/ops.jsonl" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/ops.jsonl" > "$WORK/subject.txt"

if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    cp "$WORK/subject.txt" "$DIR/expected-$SAOLA_GOLDEN_KEY.txt"
    echo "📸 golden: ghi lại expected-$SAOLA_GOLDEN_KEY.txt"
    exit 0
fi
if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ GOLDEN: khớp $OPS_COUNT/$OPS_COUNT thao tác"
    exit 0
fi

echo "❌ GOLDEN LỆCH"
echo
echo "20 khác biệt đầu (- = golden đã chốt, + = output hiện tại):"
grep -E '^[-+][0-9]' "$WORK/diff.txt" | head -20
exit 1
