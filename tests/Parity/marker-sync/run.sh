#!/usr/bin/env bash
#
# Cổng BẤT BIẾN I2 — id trong .blade.php phải khớp id trong .js.
#
# Khác mọi cổng còn lại: chúng so PHP với Python ("port có giống gốc không"),
# cổng này so BLADE với JS trong cùng một lần biên dịch ("SSR và CSR có nói
# cùng ngôn ngữ không").
#
# Lệch id ⇒ hydrate không tìm thấy element ⇒ DOM nhân đôi. Sửa đúng MỘT phía là
# lệch ngay, mà parity vẫn xanh vì cả hai bản port đều sai giống nhau.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

"$DIR/../full-pipeline/cases.js" > "$WORK/cases.jsonl" 2>/dev/null
"$DIR/../full-pipeline/subject.php" < "$WORK/cases.jsonl" > "$WORK/out.txt" 2>/dev/null

"$DIR/pairs.php" "$WORK/out.txt" > "$WORK/pairs.jsonl"

"$DIR/check.php" < "$WORK/pairs.jsonl"
