#!/usr/bin/env python3
"""Kiểm bất biến QUAN TRỌNG NHẤT: id trong .blade.php và .js phải KHỚP.

Mọi cổng khác so PHP với Python — tức "bản port có giống bản gốc không". Cổng
này so BLADE với JS trong CÙNG một lần biên dịch: "SSR và CSR có nói cùng một
ngôn ngữ không". Hai câu hỏi hoàn toàn khác nhau.

Lệch id ⇒ hydrate không tìm thấy element ⇒ DOM nhân đôi. Đó là lớp lỗi mà cả
dự án này tồn tại để chống, vậy mà không cổng nào kiểm — sửa đúng một phía là
lệch ngay, và parity vẫn xanh vì cả hai bản port đều sai giống nhau.

stdin  : mỗi dòng một JSON {name, blade, js}
stdout : báo cáo; exit 1 nếu lệch
"""
import json
import re
import sys

BLADE_ELEMENT = re.compile(r"\$__VIEW_ID__ \. '-([A-Za-z0-9_]+)'")
BLADE_MARKER = re.compile(r"@(?:start|end)Marker\(\s*'([a-z]+)'\s*,\s*'([A-Za-z0-9_]+)'\s*\)")
JS_ELEMENT = re.compile(r"this\.html\(`([A-Za-z0-9_]+)`")
# Chỉ so output REACTIVE. sao2js phát this.output cho MỌI {{ }}, còn blade chỉ
# đặt marker cho cái reactive — output tĩnh không bao giờ đổi nên không cần neo
# SSR. Đó là thiết kế, không phải lệch.
#
# "Reactive" = cờ true HOẶC stateKeys không rỗng. Chỉ đọc cờ là sai: props sinh
# ra `this.output(id, parent, false, ["source"], ...)` — cờ false nhưng vẫn phụ
# thuộc state, và blade VẪN đặt marker cho nó.
JS_OUTPUT_ALL = re.compile(
    r"this\.output\(`([A-Za-z0-9_]+)`\s*,\s*[^,]+,\s*(true|false)\s*,\s*\[([^\]]*)\]"
)


def _js_reactive_outputs(js):
    return {
        oid for oid, flag, keys in JS_OUTPUT_ALL.findall(js)
        if flag == 'true' or keys.strip() != ''
    }
JS_REACTIVE = re.compile(r"this\.reactive\(`([A-Za-z0-9_]+)`")


# Lệch ĐÃ BIẾT, chưa sửa. Gate vẫn xanh nhưng in ra để không ai quên; ca MỚI
# thì gate đỏ ngay.
#
# Đây KHÔNG phải allowlist để giấu lỗi — mỗi dòng là một lỗi thật, hẹp, có ghi
# lý do. Danh sách này chỉ được co lại, không được nở ra.
KNOWN = {
    '05-nested-wrapper.sao':
        '<template> lồng trong <template>: blade đi vào cấp trong (e21), sao2js thì không',
    '13-unclosed.sao':
        'thẻ bọc không đóng — input hỏng có chủ ý, hành vi không định nghĩa',
}


def check(name, blade, js):
    problems = []

    b_el = set(BLADE_ELEMENT.findall(blade))
    j_el = set(JS_ELEMENT.findall(js))
    if b_el != j_el:
        if b_el - j_el:
            problems.append(f'element chỉ có ở BLADE: {sorted(b_el - j_el)}')
        if j_el - b_el:
            problems.append(f'element chỉ có ở JS: {sorted(j_el - b_el)}')

    b_out = {i for kind, i in BLADE_MARKER.findall(blade) if kind == 'output'}
    j_out = _js_reactive_outputs(js)
    if b_out != j_out:
        if b_out - j_out:
            problems.append(f'output marker chỉ có ở BLADE: {sorted(b_out - j_out)}')
        if j_out - b_out:
            problems.append(f'output marker chỉ có ở JS: {sorted(j_out - b_out)}')

    b_rc = {i for kind, i in BLADE_MARKER.findall(blade) if kind == 'reactive'}
    j_rc = set(JS_REACTIVE.findall(js))
    if b_rc != j_rc:
        if b_rc - j_rc:
            problems.append(f'reactive marker chỉ có ở BLADE: {sorted(b_rc - j_rc)}')
        if j_rc - b_rc:
            problems.append(f'reactive marker chỉ có ở JS: {sorted(j_rc - b_rc)}')

    return problems


def main() -> int:
    total = bad = known_hit = 0

    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        case = json.loads(line)
        total += 1

        problems = check(case['name'], case['blade'], case['js'])
        if not problems:
            continue

        reason = next((r for k, r in KNOWN.items() if case['name'].endswith(k)), None)
        if reason is not None:
            known_hit += 1
            print(f"  ⚠️  ĐÃ BIẾT {case['name'].split('/')[-1]} — {reason}")
            continue

        bad += 1
        if bad <= 30:
            print(f"\n  ❌ {case['name']}")
            for p in problems:
                print(f'       {p}')

    print(f"\nCorpus: {total} view ({known_hit} lệch đã biết)")
    if bad:
        print(f'❌ MARKER SYNC HỎNG: {bad}/{total} view lệch MỚI (ngoài danh sách đã biết)')
        return 1
    print(f'✅ MARKER SYNC: {total - known_hit}/{total} view khớp, {known_hit} lệch đã biết')
    return 0


if __name__ == '__main__':
    sys.exit(main())
