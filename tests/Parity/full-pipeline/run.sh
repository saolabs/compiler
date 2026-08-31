#!/usr/bin/env bash
#
# Cổng parity ĐẦU-CUỐI (docs/05-roadmap.md — cổng nghiệm thu P3/P4).
#
# Các cổng khác kiểm TỪNG MODULE. Cổng này đi qua API công khai
# `SaolaCompiler::compile()` — thứ tự ráp có thể sai dù mọi mảnh đều đúng, và
# không cổng nào khác chạm tới đường đó.
#
# Oracle là pipeline CŨ đầy đủ: Node ráp đầu vào (parseSaoFile → preprocess →
# ráp → injectSsrStylesheets) còn Python sinh output (sao2blade + sao2js).
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

"$DIR/cases.js" > "$WORK/cases.jsonl" 2>/dev/null
TOTAL=$(wc -l < "$WORK/cases.jsonl" | tr -d ' ')
echo "Corpus: $TOTAL file .sao (lang dò từ nguồn, như index.js)"

"$DIR/oracle.js"   < "$WORK/cases.jsonl" > "$WORK/oracle.txt"
"$DIR/subject.php" < "$WORK/cases.jsonl" > "$WORK/subject.txt"

if diff -u "$WORK/oracle.txt" "$WORK/subject.txt" > "$WORK/diff.txt"; then
    echo "✅ BYTE PARITY đầu-cuối: khớp $TOTAL/$TOTAL"
    exit 0
fi

echo "❌ BYTE PARITY đầu-cuối HỎNG: $(grep -c '^-[a-z]' "$WORK/diff.txt" || true) / $TOTAL ca lệch"
echo
python3 - "$WORK/oracle.txt" "$WORK/subject.txt" <<'PY'
import base64, json, sys
o = {l.split('\t',1)[0]: json.loads(l.split('\t',1)[1]) for l in open(sys.argv[1])}
s = {l.split('\t',1)[0]: json.loads(l.split('\t',1)[1]) for l in open(sys.argv[2])}
shown = 0
for k in o:
    if o[k] == s.get(k) or shown >= 3:
        continue
    shown += 1
    print(f'\n### {k}')
    for field in ('blade', 'js'):
        a = base64.b64decode(o[k].get(field, '')).decode('utf-8', 'replace') if o[k].get('ok') else o[k].get('error','')
        b = base64.b64decode(s.get(k,{}).get(field, '')).decode('utf-8', 'replace') if s.get(k,{}).get('ok') else s.get(k,{}).get('error','')
        if a == b:
            continue
        for i, (x, y) in enumerate(zip(a, b)):
            if x != y:
                print(f'  [{field}] lệch tại byte {i}')
                print(f'    oracle : {a[max(0,i-70):i+70]!r}')
                print(f'    subject: {b[max(0,i-70):i+70]!r}')
                break
        else:
            print(f'  [{field}] khác độ dài: {len(a)} vs {len(b)}')
PY
exit 1
