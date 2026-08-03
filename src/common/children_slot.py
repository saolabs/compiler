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


class ChildrenSlotError(ValueError):
    """Raised when a component violates the children-slot contract."""


def is_children_expression(expression):
    """Return True only for the reserved ``$children`` expression."""
    return expression.strip() == '$children'


def count_children_placeholders(source):
    """Count supported children placeholders in template source."""
    return len(CHILDREN_DIRECTIVE_RE.findall(source)) + len(CHILDREN_ECHO_RE.findall(source))


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


def replace_children_for_blade(source):
    """Render every supported placeholder as the canonical raw Blade slot."""
    replacement = '{!! $' + CHILDREN_DATA_NAME + ' !!}'
    source = CHILDREN_DIRECTIVE_RE.sub(replacement, source)
    return CHILDREN_ECHO_RE.sub(replacement, source)


def replace_children_for_legacy_js(source):
    """Normalize placeholders for the legacy string-based JS renderer."""
    replacement = '${' + CHILDREN_DATA_NAME + "??''}"
    source = CHILDREN_DIRECTIVE_RE.sub(replacement, source)
    return CHILDREN_ECHO_RE.sub(replacement, source)
