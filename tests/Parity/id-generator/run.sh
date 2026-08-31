#!/usr/bin/env bash
#
# Cổng parity cho HydrateIdGenerator (docs/05-roadmap.md — Phase 1).
#
# Chạy cùng một dãy thao tác ngẫu nhiên qua cả hai bản cài đặt, so từng giá trị
# trả về. Yêu cầu: 0 dòng lệch.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OPS_COUNT="${1:-5000}"
SEED="${2:-20260830}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

"$DIR/ops.py" "$OPS_COUNT" "$SEED" > "$WORK/ops.jsonl"
echo "Dãy thao tác: $OPS_COUNT (seed $SEED)"

"$DIR/oracle.py"   < "$WORK/ops.jsonl" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/ops.jsonl" > "$WORK/subject.txt"

if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ PARITY: khớp $OPS_COUNT/$OPS_COUNT thao tác"
    exit 0
fi

echo "❌ PARITY HỎNG"
echo
echo "20 khác biệt đầu (- = Python đúng, + = PHP sai):"
grep -E '^[-+][0-9]' "$WORK/diff.txt" | head -20
exit 1
