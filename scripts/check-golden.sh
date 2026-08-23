#!/bin/bash
# Golden-diff cho compiler: recompile examples/sao/*.sao → examples/js + examples/blade
# rồi kiểm tra `git diff` có sạch không. Golden files được COMMIT — regenerate mà
# lệch nghĩa là compiler đổi hành vi output, phải review diff trước khi commit lại.
#
# Dùng cho: (a) chạy tay sau khi sửa compiler, (b) CI.
# Tham chiếu: docs/COMPILER_SYNC.md §0, docs/FIX_PLAN_2026-08-14.md §F5.
set -euo pipefail
cd "$(dirname "$0")/.."

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo "check-golden: không phải git repo — không diff được, bỏ qua." >&2
    exit 0
fi

# test-compile.js ghi ĐÈ trực tiếp vào examples/js|blade (đó là cách nó hoạt
# động — cũng là script dùng để REGENERATE golden có chủ ý). Script này chỉ
# mượn hành vi đó để lấy diff rồi phục hồi working tree — không phải để
# thay đổi golden. Dùng --update để giữ lại thay đổi (regenerate thật).
KEEP=0
if [[ "${1:-}" == "--update" ]]; then KEEP=1; fi

restore() {
    if [[ "$KEEP" -eq 0 ]]; then
        git checkout -- examples/js examples/blade 2>/dev/null || true
    fi
}
trap restore EXIT

echo "check-golden: recompiling examples/sao/*.sao..."
node test-compile.js >/tmp/check-golden.log 2>&1 || {
    echo "check-golden: test-compile.js LỖI:"; cat /tmp/check-golden.log; exit 1;
}

if git diff --quiet -- examples/js examples/blade; then
    echo "check-golden: OK — không lệch golden."
    exit 0
fi

echo "check-golden: LỆCH GOLDEN — compiler sinh output khác với examples/js|blade đã commit."
git diff --stat -- examples/js examples/blade
if [[ "$KEEP" -eq 1 ]]; then
    echo "check-golden: --update → đã GIỮ LẠI thay đổi, review rồi commit."
else
    echo "check-golden: đã phục hồi working tree (không --update). Chạy lại với --update để giữ diff."
fi
exit 1
