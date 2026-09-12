#!/usr/bin/env bash
#
# Oracle JS đã GỠ (10/09/2026) — cổng này giờ là GOLDEN.
#
# Oracle cũ là `builder/src/index.js::parseSaoFile`. Nhưng builder thật gửi
# thẳng source tới `php bin/saoc` (xem comment đầu builder/src/index.js), nên
# bản JS là CODE CHẾT: nó không còn là nguồn sự thật, chỉ còn là bản sao cũ.
#
# Đo lúc gỡ: 117/117 file "lệch", trong đó 107 file chỉ khác `style: null` (JS)
# với `style: ''` (PHP) — nhiễu biểu diễn. 10 file còn lại lệch THẬT và PHP
# đúng: ví dụ home/contact.sao có `<style scoped>` ở dòng 57, PHP tách được,
# JS trả null.
#
# Đúng lý lẽ đã dùng khi gỡ oracle Python (xem ../_golden.sh): giữ oracle chết
# thì một bản vá SỬA ĐÚNG trong PHP bị đánh đỏ chỉ vì bản cũ còn giữ lỗi cũ.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Đi ngược lên tới thư mục chứa builder/src — không phụ thuộc độ sâu
ROOT="$DIR"
while [[ "$ROOT" != "/" && ! -d "$ROOT/builder/src" ]]; do
    ROOT="$(dirname "$ROOT")"
done
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# File thật chứng minh không hồi quy; fixture ép các ca biên mà 56 view không
# chạm tới (thẻ bọc lồng nhau, thẻ không đóng, BOM, khai báo trùng, @ssr...).
find "$ROOT/saola/resources" -name '*.sao' | sort > "$WORK/files.txt"
REAL=$(wc -l < "$WORK/files.txt" | tr -d ' ')
find "$DIR/fixtures" -name '*.sao' | sort >> "$WORK/files.txt"
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
