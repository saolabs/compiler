"""Scoped CSS ở tầng BIÊN DỊCH — dùng chung cho sao2blade và sao2js.

Trước đây scope được quyết định lúc CHẠY: AssetManager viết lại CSS thành
`[data-sao-scope="id"] .foo` rồi gắn attribute lên "node gốc của instance", mà
node gốc lấy từ marker của Wrapper. Trang extends layout KHÔNG có Wrapper, nên
không có node nào được gắn ⇒ toàn bộ `<style scoped>` của trang thành CSS chết.

Ở đây scope được quyết định lúc biên dịch: mọi element của view mang thêm một
class ổn định, và selector được ghép thẳng vào class đó (kiểu `data-v-` của Vue).
Không cần wrapper, không phụ thuộc thứ tự mount, và HTML từ Blade đã mang sẵn
class nên CSS ăn ngay từ lần sơn đầu.

scopeId suy từ CHÍNH nội dung CSS chứ không từ đường dẫn view: sao2blade không
có sẵn view path còn sao2js thì có, hash theo nội dung khiến hai nhánh tự ra
cùng một giá trị mà không phải truyền gì qua nhau. Hai view trùng y hệt CSS thì
dùng chung scope — vô hại, vì luật giống nhau.
"""

import re

# Selector bên trong các at-rule này KHÔNG phải selector CSS (from/to/50%),
# đụng vào là hỏng animation.
_SKIP_AT_RULES = ('keyframes', 'font-face', 'import', 'charset', 'namespace')

# At-rule bọc rule thật → phải đi vào trong mà scope.
_NESTED_AT_RULES = ('media', 'supports', 'container', 'layer', 'scope')


def extract_scoped_css(content):
    """Trả list nội dung của mọi <style ... scoped ...> trong file .sao."""
    out = []
    for m in re.finditer(r'<style([^>]*)>([\s\S]*?)</style>', content or '', re.IGNORECASE):
        if re.search(r'\bscoped\b', m.group(1) or '', re.IGNORECASE):
            out.append(m.group(2))
    return out


def scope_class_for(css_blocks):
    """Class scope ổn định từ nội dung CSS. '' nếu không có block scoped nào."""
    if not css_blocks:
        return ''
    joined = '\n'.join(css_blocks)
    # djb2 — cùng thuật toán với AssetManager.hashString phía client, để hai bên
    # còn đối chiếu được khi cần.
    h = 5381
    for ch in joined:
        h = ((h * 33) ^ ord(ch)) & 0xFFFFFFFF
    return 's' + format(h, 'x')


def scope_css(css, scope_class):
    """Ghép `.scope_class` vào từng selector của CSS.

    `.a .b { }`      → `.a .b.scope { }`      (chỉ compound CUỐI, như Vue)
    `.a, .b { }`     → `.a.scope, .b.scope { }`
    `.a:hover { }`   → `.a.scope:hover { }`   (chèn TRƯỚC pseudo)
    `@media ... { }` → đi vào trong scope tiếp
    `@keyframes`     → giữ nguyên
    """
    if not scope_class or not css:
        return css
    return _scope_block(css, '.' + scope_class)


def _scope_block(css, suffix):
    out = []
    i = 0
    n = len(css)
    while i < n:
        brace = css.find('{', i)
        if brace == -1:
            out.append(css[i:])
            break

        prelude = css[i:brace]
        body_start = brace + 1
        body_end = _match_brace(css, brace)
        if body_end == -1:
            out.append(css[i:])
            break
        body = css[body_start:body_end]

        stripped = prelude.strip()
        at_name = ''
        if stripped.startswith('@'):
            at_name = re.match(r'@([a-zA-Z-]+)', stripped).group(1).lower()
            at_name = at_name.replace('-webkit-', '').replace('-moz-', '')

        if at_name in _SKIP_AT_RULES or any(at_name.endswith(s) for s in _SKIP_AT_RULES):
            out.append(prelude + '{' + body + '}')
        elif at_name in _NESTED_AT_RULES:
            out.append(prelude + '{' + _scope_block(body, suffix) + '}')
        elif at_name:
            out.append(prelude + '{' + body + '}')
        else:
            out.append(_scope_selector_list(prelude, suffix) + '{' + body + '}')

        i = body_end + 1
    return ''.join(out)


def _match_brace(css, open_idx):
    depth = 0
    for i in range(open_idx, len(css)):
        if css[i] == '{':
            depth += 1
        elif css[i] == '}':
            depth -= 1
            if depth == 0:
                return i
    return -1


def _scope_selector_list(prelude, suffix):
    lead = prelude[:len(prelude) - len(prelude.lstrip())]
    body = prelude.strip()
    if not body:
        return prelude
    parts = [_scope_one_selector(s, suffix) for s in _split_selectors(body)]
    return lead + ', '.join(parts) + ' '


def _split_selectors(sel):
    parts, buf, depth = [], [], 0
    for ch in sel:
        if ch in '([':
            depth += 1
        elif ch in ')]':
            depth -= 1
        elif ch == ',' and depth == 0:
            parts.append(''.join(buf))
            buf = []
            continue
        buf.append(ch)
    parts.append(''.join(buf))
    return [p.strip() for p in parts if p.strip()]


def _scope_one_selector(sel, suffix):
    """Ghép suffix vào compound CUỐI, chèn trước pseudo đầu tiên của compound đó."""
    sel = sel.strip()
    if not sel:
        return sel

    # Tìm ranh giới compound cuối: khoảng trắng hoặc tổ hợp > + ~ ở mức ngoài.
    depth, cut = 0, 0
    for i, ch in enumerate(sel):
        if ch in '([':
            depth += 1
        elif ch in ')]':
            depth -= 1
        elif depth == 0 and (ch.isspace() or ch in '>+~'):
            cut = i + 1
    head, last = sel[:cut], sel[cut:]
    if not last:
        return sel + suffix

    # Chèn trước pseudo đầu tiên (':hover', '::before') để pseudo vẫn ở cuối.
    m = re.search(r'(?<!\\):', last)
    if m:
        return head + last[:m.start()] + suffix + last[m.start():]
    return head + last + suffix
