#!/usr/bin/env bash
#
# Cổng cho đường vận chuyển Node↔PHP (docs/05-roadmap.md §7 bug ⑤ và ⑥).
#
# Mọi cổng khác gọi thẳng `bin/saoc` hoặc `SaolaCompiler::compile()`. KHÔNG cổng
# nào chạy `builder/src/index.js` thật — nơi Node spawn PHP, giải mã stdout,
# ráp registry và ghi file. Bug ⑤ (vỡ UTF-8 ở ranh giới chunk) sống trong đúng
# vùng đó và lọt qua 28 cổng xanh.
#
# Ba phép kiểm, trên một project SANDBOX (không đụng project thật):
#
#   1. TOÀN VẸN     — không ký tự U+FFFD nào trong output
#   2. TRUNG THỰC   — file index.js ghi ra == output `saoc compile` trực tiếp
#   3. TÁI LẬP      — hai lần build liên tiếp ra kết quả giống hệt
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$DIR"
while [[ "$ROOT" != "/" && ! -d "$ROOT/builder/src" ]]; do
    ROOT="$(dirname "$ROOT")"
done
CLI="$ROOT/builder/src/cli.js"
if [[ -x "$ROOT/compiler/bin/saoc" ]]; then
    SAOC="$ROOT/compiler/bin/saoc"
else
    SAOC="$ROOT/php-compiler/bin/saoc"
fi

SANDBOX="$(mktemp -d)"
trap 'rm -rf "$SANDBOX"' EXIT

"$DIR/make-corpus.js" "$SANDBOX" 2>&1 | sed 's/^/  /'
echo "Corpus: $(find "$SANDBOX/resources/saola" -name '*.sao' | wc -l | tr -d ' ') view trong sandbox"

( cd "$SANDBOX" && node "$CLI" web ) > "$SANDBOX/build1.log" 2>&1 || {
    echo "❌ Build thất bại:"; tail -20 "$SANDBOX/build1.log"; exit 1
}
cp -R "$SANDBOX/resources/js" "$SANDBOX/pass1-js"
cp -R "$SANDBOX/resources/views" "$SANDBOX/pass1-views"

failed=0

# ── 1. Toàn vẹn ───────────────────────────────────────────────────────
# `|| true`: grep trả 1 khi KHÔNG khớp. Với `set -e` + `pipefail` thì đúng
# lúc output sạch lại là lúc script tự chết — bẫy kinh điển.
corrupt=$(grep -rl $'\xef\xbf\xbd' "$SANDBOX/resources/js" "$SANDBOX/resources/views" 2>/dev/null | wc -l | tr -d ' ' || true)
if [[ "$corrupt" -ne 0 ]]; then
    echo "  ❌ TOÀN VẸN: $corrupt file chứa ký tự thay thế U+FFFD (UTF-8 vỡ)"
    grep -rl $'\xef\xbf\xbd' "$SANDBOX/resources/js" 2>/dev/null | sed "s|$SANDBOX/|      |" | head -5 || true
    failed=$((failed + 1))
else
    echo "  ✓ TOÀN VẸN: không có ký tự U+FFFD"
fi

# ── 2. Trung thực ─────────────────────────────────────────────────────
python3 - "$SANDBOX" "$SAOC" <<'PY' || failed=$((failed + 1))
import json, os, re, subprocess, sys
sandbox, saoc = sys.argv[1], sys.argv[2]
views = os.path.join(sandbox, 'resources/saola/web/views')
jsdir = os.path.join(sandbox, 'resources/js/saola/web/views')
blade = os.path.join(sandbox, 'resources/views/web')

def pascal(s):
    return ''.join(w[:1].upper() + w[1:] for w in re.split(r'[-_\s]+', s))

bad = checked = 0
for dirpath, _, files in os.walk(views):
    for name in sorted(files):
        if not name.endswith('.sao'):
            continue
        sao = os.path.join(dirpath, name)
        rel = os.path.relpath(sao, views)[:-4]
        view = 'web.' + rel.replace(os.sep, '.')
        stem = os.path.basename(rel)

        found = next((os.path.join(jsdir, os.path.dirname(rel), stem + e)
                      for e in ('.js', '.ts')
                      if os.path.exists(os.path.join(jsdir, os.path.dirname(rel), stem + e))), None)
        lang = 'ts' if (found or '').endswith('.ts') else 'js'

        p = subprocess.run(['php', saoc, 'compile', sao, f'--view-path={view}',
                            f'--fn={pascal(stem)}',
                            f"--factory={''.join(pascal(x) for x in view.split('.'))}",
                            f'--lang={lang}', '--json'],
                           capture_output=True, text=True)
        if p.returncode != 0:
            print(f'      saoc lỗi: {view}'); bad += 1; continue
        out = json.loads(p.stdout)

        for path, want in ((found, out.get('js') or ''),
                           (os.path.join(blade, rel + '.blade.php'), out.get('blade') or '')):
            if not path or not os.path.exists(path):
                continue
            checked += 1
            got = open(path, encoding='utf-8').read()
            if got != want:
                bad += 1
                if bad <= 3:
                    i = next((k for k, (a, b) in enumerate(zip(got, want)) if a != b), min(len(got), len(want)))
                    print(f'      {os.path.basename(path)} lệch tại byte {i}')
                    print(f'        index.js: {got[max(0,i-50):i+50]!r}')
                    print(f'        saoc    : {want[max(0,i-50):i+50]!r}')

if bad:
    print(f'  ❌ TRUNG THỰC: {bad}/{checked} file khác output saoc trực tiếp')
    sys.exit(1)
print(f'  ✓ TRUNG THỰC: {checked}/{checked} file khớp saoc trực tiếp')
PY

# ── 3. Tái lập ────────────────────────────────────────────────────────
( cd "$SANDBOX" && node "$CLI" web ) > "$SANDBOX/build2.log" 2>&1 || {
    echo "  ❌ TÁI LẬP: build lần hai thất bại"; failed=$((failed + 1)); }

# Bỏ dòng timestamp trong registry/views trước khi so
norm() { find "$1" -type f | sort | while read -r f; do
    echo "== ${f#$1}"; grep -v 'Generated at:' "$f"; done; }

if diff <(norm "$SANDBOX/pass1-js") <(norm "$SANDBOX/resources/js") > "$SANDBOX/repro.diff" \
   && diff <(norm "$SANDBOX/pass1-views") <(norm "$SANDBOX/resources/views") >> "$SANDBOX/repro.diff"; then
    echo "  ✓ TÁI LẬP: hai lần build ra kết quả giống hệt"
else
    echo "  ❌ TÁI LẬP: hai lần build khác nhau"
    grep -E '^[<>]' "$SANDBOX/repro.diff" | head -6 | sed 's/^/      /' || true
    failed=$((failed + 1))
fi

echo
if [[ $failed -eq 0 ]]; then
    echo "✅ NODE TRANSPORT: 3/3 phép kiểm đạt"
    exit 0
fi
echo "❌ NODE TRANSPORT: $failed/3 phép kiểm hỏng"
exit 1
