"""Shared structural validation for imported component tags.

Both Blade and JavaScript targets resolve imported tags independently.  A
malformed source must therefore fail before either target rewrites the tags;
otherwise Blade can preserve a dangling close tag while the JS AST silently
ignores it, producing different DOM trees.
"""

from __future__ import annotations

import re


class TemplateStructureError(ValueError):
    """Raised when imported component tags are not structurally balanced."""


_TAG_NAME_RE = re.compile(r"[A-Za-z][\w-]*")
_RAWTEXT_TAGS = {"script", "style", "textarea", "title"}


def _location(source: str, index: int) -> tuple[int, int]:
    line = source.count("\n", 0, index) + 1
    line_start = source.rfind("\n", 0, index) + 1
    return line, index - line_start + 1


def _scan_tag_end(source: str, start: int) -> int:
    """Return the index after ``>`` while respecting quoted attributes."""
    quote = None
    escaped = False
    pos = start
    while pos < len(source):
        char = source[pos]
        if quote is not None:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
        elif char in ("'", '"'):
            quote = char
        elif char == ">":
            return pos + 1
        pos += 1
    return len(source)


def _error(source: str, index: int, message: str) -> TemplateStructureError:
    line, column = _location(source, index)
    return TemplateStructureError(f"{message} at line {line}, column {column}.")


def validate_imported_tag_structure(source: str, imports: dict | None) -> None:
    """Validate paired/self-closing imported component tags with one stack.

    Native HTML is deliberately left to the HTML/AST pipeline.  Imported tags
    are strict because they become component lifecycle boundaries and must be
    rewritten identically for Blade and JavaScript.
    """
    imported_tags = set((imports or {}).keys())
    if not imported_tags:
        return

    stack: list[tuple[str, int]] = []
    pos = 0
    length = len(source)

    while pos < length:
        if source.startswith("{{--", pos):
            end = source.find("--}}", pos + 4)
            pos = length if end == -1 else end + 4
            continue
        if source.startswith("<!--", pos):
            end = source.find("-->", pos + 4)
            pos = length if end == -1 else end + 3
            continue
        if source[pos] != "<":
            pos += 1
            continue

        cursor = pos + 1
        is_closing = False
        if cursor < length and source[cursor] == "/":
            is_closing = True
            cursor += 1
        while cursor < length and source[cursor].isspace():
            cursor += 1

        name_match = _TAG_NAME_RE.match(source, cursor)
        if not name_match:
            pos += 1
            continue

        tag_name = name_match.group(0)
        tag_end = _scan_tag_end(source, name_match.end())
        tag_source = source[pos:tag_end]

        # Do not inspect template-like text inside native raw/RCDATA elements.
        if not is_closing and tag_name.lower() in _RAWTEXT_TAGS:
            close_match = re.search(
                rf"</\s*{re.escape(tag_name)}\s*>",
                source[tag_end:],
                flags=re.IGNORECASE,
            )
            pos = length if close_match is None else tag_end + close_match.end()
            continue

        if tag_name not in imported_tags:
            pos = tag_end
            continue

        if is_closing:
            if not stack:
                raise _error(
                    source,
                    pos,
                    f"Unexpected closing component tag </{tag_name}>; "
                    f"<{tag_name} /> is already self-closing",
                )
            open_name, open_pos = stack[-1]
            if open_name != tag_name:
                open_line, open_column = _location(source, open_pos)
                raise _error(
                    source,
                    pos,
                    f"Mismatched closing component tag </{tag_name}>; expected "
                    f"</{open_name}> for <{open_name}> opened at line "
                    f"{open_line}, column {open_column}",
                )
            stack.pop()
        elif not re.search(r"/\s*>$", tag_source):
            stack.append((tag_name, pos))

        pos = tag_end

    if stack:
        tag_name, open_pos = stack[-1]
        raise _error(
            source,
            open_pos,
            f"Unclosed component tag <{tag_name}>; expected </{tag_name}>",
        )
