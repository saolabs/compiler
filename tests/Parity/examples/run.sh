#!/usr/bin/env bash
#
# Cổng GOLDEN cho examples/ — khác các cổng còn lại.
#
# Cổng parity so PHP với Python. Cổng này so PHP với ẢNH CHỤP output đã commit
# (`examples/expected/`). Hai bảo chứng khác nhau:
#
#   parity  → "PHP làm giống Python"     (mất khi Python bị gỡ ở P6)
#   golden  → "PHP không đổi ngoài ý muốn"  (còn mãi)
#
# Golden được SINH RA bởi chính compiler, không viết tay: output viết tay chỉ
# chứng minh người viết nghĩ gì, không chứng minh compiler làm gì.
#
# Output đổi CÓ CHỦ Ý → chạy examples/regenerate.sh rồi review `git diff`.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EX="$DIR/../../../examples"
SAOC="$DIR/../../../bin/saoc"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

pascal() {
    printf '%s' "$1" | awk -F'[-_ ]' '{for(i=1;i<=NF;i++) printf "%s%s", toupper(substr($i,1,1)), substr($i,2)}'
}

total=0
failed=0

for sao in "$EX"/src/*.sao; do
    stem="$(basename "$sao" .sao)"
    name="$(pascal "${stem#*-}")"
    lang=js
    grep -qiE '<script[^>]*\blang=["'"'"']?(ts|typescript)' "$sao" && lang=ts

    php "$SAOC" compile "$sao" \
        --view-path="examples.${stem}" --fn="$name" --factory="${name}Factory" \
        --lang="$lang" --asset-prefix='static/examples/assets/' --json \
    | python3 -c "
import json, sys, pathlib
d = json.load(sys.stdin)
w = pathlib.Path('$WORK')
(w / '$stem.blade.php').write_text(d['blade'] or '', encoding='utf-8')
(w / '$stem.$lang').write_text(d['js'] or '', encoding='utf-8')
"

    for ext in "blade.php" "$lang"; do
        total=$((total + 1))
        want="$EX/expected/$stem.$ext"
        got="$WORK/$stem.$ext"
        if [[ ! -f "$want" ]]; then
            echo "  ❌ $stem.$ext — thiếu golden (chạy examples/regenerate.sh)"
            failed=$((failed + 1))
        elif ! diff -q "$want" "$got" > /dev/null; then
            echo "  ❌ $stem.$ext lệch:"
            diff -u "$want" "$got" | grep -E '^[-+][^-+]' | head -6 | sed 's/^/      /'
            failed=$((failed + 1))
        fi
    done
done

echo "Corpus: $total file golden ($(ls "$EX"/src/*.sao | wc -l | tr -d ' ') example × blade + js)"

if [[ $failed -eq 0 ]]; then
    echo "✅ GOLDEN: khớp $total/$total"
    exit 0
fi

echo "❌ GOLDEN HỎNG: $failed/$total file lệch"
exit 1
