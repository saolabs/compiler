#!/usr/bin/env bash
# Cổng golden cho BladeHydrateProcessor (Phase 3), chạy đủ bốn mode id.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Corpus ĐÓNG BĂNG. Bản sinh cũ (cases.py) gài spy vào compiler Python để bắt
# đúng bộ tham số mà BladeHydrateProcessor nhận cho từng view thật — không còn
# làm được khi Python đã gỡ.
#
# ponytail: ảnh chụp 17 ca tổng hợp + 103 view thật tại thời điểm gỡ Python.
# Nó KHÔNG tự bắt view .sao mới thêm sau này. Muốn bắt lại thì gài spy tương
# đương vào SaolaCompiler bên PHP rồi sinh lại file này.
cp "$DIR/cases.jsonl" "$WORK/cases.jsonl"
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
echo "Corpus: $TOTAL input × 4 mode (đóng băng: 17 tổng hợp + 103 thật)"

for MODE in terse compact md5 raw; do
    SAOLA_ID_MODE="$MODE" "$DIR/../_golden.sh" "$DIR" < "$WORK/cases.jsonl" > "$WORK/oracle-$MODE.txt"
    SAOLA_ID_MODE="$MODE" "$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject-$MODE.txt"
    if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
        cp "$WORK/subject-$MODE.txt" "$DIR/expected-$MODE.txt"
        echo "📸 golden: ghi lại expected-$MODE.txt"
        continue
    fi
    if ! diff -u "$WORK/oracle-$MODE.txt" "$WORK/subject-$MODE.txt" > "$WORK/diff-$MODE.txt"; then
        echo "❌ $MODE: $(grep -c '^-{' "$WORK/diff-$MODE.txt" || true) input lệch"
        grep -E '^[-+]\{' "$WORK/diff-$MODE.txt" | head -8 | cut -c1-400
        exit 1
    fi
done

echo "✅ GOLDEN: khớp $((TOTAL * 4))/$((TOTAL * 4))"
