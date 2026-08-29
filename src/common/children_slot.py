"""Shared children-slot syntax contract for Blade and JavaScript compilers.

Children placeholders are insertion points, not AST containers.  The actual
children stay owned by the parent component and are materialized only when a
renderer reaches one of these placeholders in the child component:

    @children
    {{ $children }}
    {!! $children !!}
"""

import re


CHILDREN_DATA_NAME = '__ONE_CHILDREN_CONTENT__'

CHILDREN_DIRECTIVE_RE = re.compile(
    r'@children\b[^\S\r\n]*(?:\([^\S\r\n]*\))?', re.IGNORECASE
)

CHILDREN_ECHO_RE = re.compile(
    r'(?:\{\{\s*\$children\s*\}\}|\{!!\s*\$children\s*!!\})'
)

# `@children` bên trong @verbatim là CODE MINH HOẠ, không phải slot: trang docs
# in ra cú pháp .sao cho người đọc. Đếm hay thay ở đó thì trang docs vừa hiện sai
# (`{!! $__ONE_CHILDREN_CONTENT__ !!}` thay vì `@children`) vừa có thể bị báo lỗi
# "chỉ được một children placeholder" chỉ vì nêu ví dụ hai lần.
VERBATIM_RE = re.compile(r'@verbatim.*?@endverbatim', re.DOTALL | re.IGNORECASE)


def _split_verbatim(source):
    """Cắt source thành [(đoạn, có_phải_verbatim)] giữ nguyên thứ tự."""
    segments = []
    pos = 0
    for m in VERBATIM_RE.finditer(source):
        if m.start() > pos:
            segments.append((source[pos:m.start()], False))
        segments.append((m.group(0), True))
        pos = m.end()
    segments.append((source[pos:], False))
    return segments


def _apply_outside_verbatim(source, fn):
    """Chạy *fn* trên phần ngoài @verbatim, ghép lại nguyên trạng phần trong."""
    return ''.join(seg if raw else fn(seg) for seg, raw in _split_verbatim(source))


class ChildrenSlotError(ValueError):
    """Raised when a component violates the children-slot contract."""


def is_children_expression(expression):
    """Return True only for the reserved ``$children`` expression."""
    return expression.strip() == '$children'


def count_children_placeholders(source):
    """Count supported children placeholders in template source (bỏ qua @verbatim)."""
    return sum(
        len(CHILDREN_DIRECTIVE_RE.findall(seg)) + len(CHILDREN_ECHO_RE.findall(seg))
        for seg, raw in _split_verbatim(source) if not raw
    )


def validate_children_placeholders(source):
    """Enforce one insertion point per component template."""
    count = count_children_placeholders(source)
    if count > 1:
        raise ChildrenSlotError(
            'A component template may contain only one children placeholder '
            '(@children or {{ $children }}).'
        )
    return count


def has_children_placeholder(source):
    """Return whether template source declares a children insertion point."""
    return count_children_placeholders(source) > 0


def _replace_with(replacement):
    def _sub(segment):
        segment = CHILDREN_DIRECTIVE_RE.sub(replacement, segment)
        return CHILDREN_ECHO_RE.sub(replacement, segment)
    return _sub


def replace_children_for_blade(source):
    """Render every supported placeholder as the canonical raw Blade slot."""
    return _apply_outside_verbatim(
        source, _replace_with('{!! $' + CHILDREN_DATA_NAME + ' !!}')
    )


def replace_children_for_legacy_js(source):
    """Normalize placeholders for the legacy string-based JS renderer."""
    return _apply_outside_verbatim(
        source, _replace_with('${' + CHILDREN_DATA_NAME + "??''}")
    )
