#!/usr/bin/env bash
#
# Cổng parity cho SymbolCollector (docs/05-roadmap.md — Phase 1).
#
# Oracle là JAVASCRIPT (builder/src/preprocessor/symbol-collector.js).
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

"$DIR/oracle.js"   < "$WORK/files.txt" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/files.txt" > "$WORK/subject.txt"

if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ PARITY: khớp $TOTAL/$TOTAL file"
    exit 0
fi

MISMATCH=$(grep -c '^-[a-z]' "$WORK/diff.txt" || true)
echo "❌ PARITY HỎNG: $MISMATCH / $TOTAL file lệch"
echo
echo "File lệch đầu tiên:"
grep -E '^[-+][a-z]' "$WORK/diff.txt" | head -2 | cut -c1-400
exit 1
