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

python3 - "$WORK/out.txt" > "$WORK/pairs.jsonl" <<'PY'
import base64, json, sys
for line in open(sys.argv[1]):
    name, raw = line.rstrip('\n').split('\t', 1)
    r = json.loads(raw)
    if not r.get('ok'):
        continue
    print(json.dumps({
        'name': name,
        'blade': base64.b64decode(r['blade']).decode('utf-8', 'replace'),
        'js': base64.b64decode(r['js']).decode('utf-8', 'replace'),
    }, ensure_ascii=False))
PY

"$DIR/check.py" < "$WORK/pairs.jsonl"
