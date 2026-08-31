#!/usr/bin/env bash
#
# Sinh lại toàn bộ expected/ từ src/.
#
# Golden phải là OUTPUT THẬT của compiler, không viết tay. Output viết tay mù
# với khâu sinh code: nó chỉ chứng minh người viết nghĩ gì, không chứng minh
# compiler làm gì.
#
# Chạy khi output thay đổi CÓ CHỦ Ý. Luôn đọc `git diff` của expected/ sau đó —
# đó chính là thứ cần review.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SAOC="$DIR/../bin/saoc"

pascal() {
    printf '%s' "$1" | awk -F'[-_ ]' '{for(i=1;i<=NF;i++) printf "%s%s", toupper(substr($i,1,1)), substr($i,2)}'
}

mkdir -p "$DIR/expected"
count=0

for sao in "$DIR"/src/*.sao; do
    stem="$(basename "$sao" .sao)"
    # bỏ tiền tố số thứ tự khi dựng tên định danh: 01-basic → Basic
    name="$(pascal "${stem#*-}")"

    lang=js
    grep -qiE '<script[^>]*\blang=["'"'"']?(ts|typescript)' "$sao" && lang=ts

    php "$SAOC" compile "$sao" \
        --view-path="examples.${stem}" \
        --fn="$name" \
        --factory="${name}Factory" \
        --lang="$lang" \
        --asset-prefix='static/examples/assets/' \
        --json \
    | php -r '
        $data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $out = $argv[1];
        file_put_contents($out . "/" . $argv[2] . ".blade.php", $data["blade"] ?? "");
        file_put_contents($out . "/" . $argv[2] . "." . $argv[3], $data["js"] ?? "");
    ' "$DIR/expected" "$stem" "$lang"
    count=$((count + 1))
    echo "  ✓ $stem  →  expected/$stem.blade.php + expected/$stem.$lang"
done

echo
echo "Đã sinh $count example."
