#!/usr/bin/env bash
#
# Oracle JS đã GỠ (10/09/2026) — cổng này giờ là GOLDEN.
# Cùng lý do với cổng source-split: `builder/src/preprocessor/*.js` là code chết,
# builder thật gọi `php bin/saoc`. Xem source-split/run.sh và ../_golden.sh.
#
# Lệch cuối cùng trước khi gỡ gồm cả trang demo `modules/tagdirectives/index.sao`
# dùng directive viết trên thẻ (`#if`…) — thứ PHP hiểu còn bản JS chết không.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Đi ngược lên tới thư mục chứa builder/src — không phụ thuộc độ sâu
ROOT="$DIR"
while [[ "$ROOT" != "/" && ! -d "$ROOT/builder/src" ]]; do
    ROOT="$(dirname "$ROOT")"
done
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Dùng chung fixture với cổng source-split — cùng là file .sao, khác thứ được kiểm.
find "$ROOT/saola/resources" -name '*.sao' | sort > "$WORK/files.txt"
REAL=$(wc -l < "$WORK/files.txt" | tr -d ' ')
find "$DIR/../source-split/fixtures" -name '*.sao' | sort >> "$WORK/files.txt"
TOTAL=$(wc -l < "$WORK/files.txt" | tr -d ' ')
echo "Corpus: $REAL file .sao thật + $((TOTAL - REAL)) fixture = $TOTAL"

"$DIR/../_golden.sh" "$DIR" < "$WORK/files.txt" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/files.txt" > "$WORK/subject.txt"

if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    cp "$WORK/subject.txt" "$DIR/expected.txt"
    echo "📸 golden: ghi lại expected.txt"
    exit 0
fi
if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ GOLDEN: khớp $TOTAL/$TOTAL file"
    exit 0
fi

MISMATCH=$(grep -c '^-[a-z]' "$WORK/diff.txt" || true)
echo "❌ GOLDEN LỆCH: $MISMATCH / $TOTAL file lệch"
echo
echo "File lệch đầu tiên:"
grep -E '^[-+][a-z]' "$WORK/diff.txt" | head -2 | cut -c1-400
exit 1
