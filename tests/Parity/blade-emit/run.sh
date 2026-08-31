#!/usr/bin/env bash
#
# Cổng parity cho Blade emitter (docs/05-roadmap.md — Phase 3).
#
# sao2blade KHÔNG nhận .sao thô — nó nhận chuỗi blade đã ráp mà
# index.js::processSaoFile dựng lên. corpus.js tái dựng đúng chuỗi đó bằng bản
# JS, nên oracle Python và subject PHP cùng nhận MỘT đầu vào và mọi khác biệt
# lộ ra đều thuộc về emitter.
#
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$DIR"
while [[ "$ROOT" != "/" && ! -d "$ROOT/builder/.reference/python/src" ]]; do
    ROOT="$(dirname "$ROOT")"
done
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

find "$ROOT/saola/resources" -name '*.sao' | sort > "$WORK/files.txt"
REAL=$(wc -l < "$WORK/files.txt" | tr -d ' ')
find "$DIR/../source-split/fixtures" -name '*.sao' | sort >> "$WORK/files.txt"
TOTAL=$(wc -l < "$WORK/files.txt" | tr -d ' ')
echo "Corpus: $REAL file .sao thật + $((TOTAL - REAL)) fixture = $TOTAL"

"$DIR/corpus.js" < "$WORK/files.txt" > "$WORK/corpus.tsv"

if grep -q '__CORPUS_ERROR__' "$WORK/corpus.tsv"; then
    echo "❌ Dựng corpus lỗi:"
    grep '__CORPUS_ERROR__' "$WORK/corpus.tsv" | head -3
    exit 1
fi

"$DIR/oracle.py"   < "$WORK/corpus.tsv" > "$WORK/oracle.txt" 2>/dev/null
"$DIR/subject.php" < "$WORK/corpus.tsv" > "$WORK/subject.txt" 2>/dev/null

if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ PARITY: khớp $TOTAL/$TOTAL file"
    exit 0
fi

echo "❌ PARITY HỎNG: $(grep -c '^-[a-z]' "$WORK/diff.txt" || true) / $TOTAL file lệch"
echo
grep -E '^[-+][a-z]' "$WORK/diff.txt" | head -2 | cut -c1-400
exit 1
