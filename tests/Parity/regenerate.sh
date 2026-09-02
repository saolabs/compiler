#!/usr/bin/env bash
#
# Ghi lại ảnh chụp golden cho mọi cổng.
#
# Golden do CHÍNH compiler sinh ra, không viết tay. Chạy file này KHI VÀ CHỈ KHI
# output đổi có chủ ý, rồi review `git diff` — diff chính là bản mô tả thay đổi
# hành vi của compiler.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export SAOLA_GOLDEN_REGENERATE=1

fail=0
for gate in "$DIR"/*/; do
    name="$(basename "$gate")"
    [[ -f "$gate/run.sh" ]] || continue
    grep -q '_golden.sh' "$gate/run.sh" || continue   # cổng không dùng golden shim

    printf '▶ %-24s' "$name"
    if out=$("$gate/run.sh" 2>&1); then
        echo "$(grep -c '📸' <<< "$out") ảnh chụp"
    else
        echo "❌ HỎNG"; echo "$out" | tail -5 | sed 's/^/    /'; fail=1
    fi
done

exit $fail
