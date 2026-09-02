#!/usr/bin/env bash
#
# Cổng golden cho DeclarationTracker (docs/05-roadmap.md — Phase 3).
#
# Oracle là PYTHON (builder/.reference/python/src/common/declaration_tracker.py).
#
# `position` KHÔNG được so sánh. Python đánh chỉ số theo CODEPOINT, PHP theo
# BYTE, nên cùng một khai báo cho hai con số khác nhau khi phía trước có tiếng
# Việt hoặc BOM. Đã xác minh không consumer nào đọc field này — nó chỉ dùng nội
# bộ để sắp xếp và để lọc khai báo nằm trong thẻ bọc, và cả hai phép đó đều
# nhất quán trong hệ chỉ số của chính mình.
#
# THỨ TỰ thì vẫn được kiểm: cả hai bên phát ra danh sách ĐÃ SẮP XẾP, nên lỗi
# làm đảo thứ tự sẽ làm đảo mảng và cổng bắt được ngay.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Đi ngược lên tới thư mục chứa builder/.reference/python/src — không phụ thuộc độ sâu
ROOT="$DIR"
while [[ "$ROOT" != "/" && ! -d "$ROOT/compiler/src" ]]; do
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

"$DIR/../_golden.sh" "$DIR"   < "$WORK/files.txt" > "$WORK/oracle.txt"
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
