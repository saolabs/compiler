#!/usr/bin/env bash
# Cổng parity cho BladeHydrateProcessor (Phase 3), chạy đủ bốn mode id.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$DIR"
while [[ "$ROOT" != "/" && ! -d "$ROOT/builder/.reference/python/src" ]]; do
    ROOT="$(dirname "$ROOT")"
done
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

find "$ROOT/saola/resources" -name '*.sao' | sort > "$WORK/files.txt"
find "$DIR/../source-split/fixtures" -name '*.sao' | sort >> "$WORK/files.txt"
"$DIR/../blade-emit/corpus.js" < "$WORK/files.txt" > "$WORK/assembled.tsv"
"$DIR/cases.py" < "$WORK/assembled.tsv" > "$WORK/cases.jsonl" 2> "$WORK/corpus.log"
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
echo "Corpus: $TOTAL input × 4 mode ($(tail -1 "$WORK/corpus.log" | sed 's/^ *//'))"

for MODE in terse compact md5 raw; do
    SAOLA_ID_MODE="$MODE" "$DIR/oracle.py" < "$WORK/cases.jsonl" > "$WORK/oracle-$MODE.txt"
    SAOLA_ID_MODE="$MODE" "$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject-$MODE.txt"
    if ! diff -u "$WORK/oracle-$MODE.txt" "$WORK/subject-$MODE.txt" > "$WORK/diff-$MODE.txt"; then
        echo "❌ $MODE: $(grep -c '^-{' "$WORK/diff-$MODE.txt" || true) input lệch"
        grep -E '^[-+]\{' "$WORK/diff-$MODE.txt" | head -8 | cut -c1-400
        exit 1
    fi
done

echo "✅ PARITY: khớp $((TOTAL * 4))/$((TOTAL * 4))"
