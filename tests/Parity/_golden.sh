#!/usr/bin/env bash
#
# Phát lại ảnh chụp golden — thay chỗ của `oracle.py` đã gỡ.
#
# Cổng parity cũ chứng minh "PHP làm giống Python". Bảo chứng đó hết giá trị khi
# Python không còn là nguồn sự thật, và nó còn cản đường: một bản vá sửa đúng
# trong PHP bị đánh đỏ chỉ vì bản Python còn giữ lỗi cũ.
#
# Golden chứng minh "PHP không đổi ngoài ý muốn" — bảo chứng còn giá trị mãi.
# Ảnh chụp do CHÍNH compiler sinh (xem regenerate.sh), không viết tay: output
# viết tay chỉ chứng minh người viết nghĩ gì, không chứng minh compiler làm gì.
#
# Đổi output CÓ CHỦ Ý → chạy `./regenerate.sh` rồi review `git diff`.
set -euo pipefail

GATE="${1:?cần đường dẫn thư mục cổng}"
# hydrate-processor chạy nhiều mode id, mỗi mode một ảnh chụp riêng.
# Cổng chạy nhiều lần với tham số khác nhau (id-generator: 2 seed) hoặc nhiều
# mode (hydrate-processor) thì mỗi lần một ảnh chụp riêng.
SUFFIX="${SAOLA_ID_MODE:+-$SAOLA_ID_MODE}${SAOLA_GOLDEN_KEY:+-$SAOLA_GOLDEN_KEY}"
FILE="$GATE/expected${SUFFIX}.txt"

# Nuốt stdin y như oracle cũ, để phía gọi không vỡ ống.
cat > /dev/null

# Lúc ghi lại golden thì chưa có gì để phát; nhiều cổng gọi shim và subject
# trên cùng MỘT dòng nên shim chết là run.sh chết trước khi kịp ghi.
if [[ "${SAOLA_GOLDEN_REGENERATE:-}" == "1" ]]; then
    exit 0
fi

if [[ ! -f "$FILE" ]]; then
    echo "❌ thiếu ảnh chụp golden: $FILE" >&2
    echo "   chạy: $(dirname "$GATE")/regenerate.sh" >&2
    exit 1
fi

cat "$FILE"
