"""
Các hàm tiện ích chung cho compiler
"""

import re
import json
import string
import random

_uid_used = set()

def generate_uid(length=8):
    """Generate unique 8-character ID for compile-time reactive identification"""
    chars = string.ascii_letters + string.digits
    while True:
        uid = ''.join(random.choices(chars, k=length))
        if uid not in _uid_used:
            _uid_used.add(uid)
            return uid

def reset_uid():
    """Reset UID set (call between compilations)"""
    _uid_used.clear()

def extract_balanced_parentheses(text, start_pos):
    """Extract content inside balanced parentheses starting from start_pos"""
    if start_pos >= len(text) or text[start_pos] != '(':
        return None, start_pos
    
    paren_count = 0
    content_start = start_pos + 1
    i = start_pos
    
    while i < len(text):
        if text[i] == '(':
            paren_count += 1
        elif text[i] == ')':
            paren_count -= 1
            if paren_count == 0:
                content = text[content_start:i]
                return content, i + 1
        i += 1
    
    # Unbalanced parentheses
    return text[content_start:], len(text)

def format_attrs(attrs_dict):
    """Format attributes dictionary to JavaScript object string"""
    return json.dumps(attrs_dict, separators=(',', ':'))

def split_top_level_commas(text):
    """Split *text* on commas that are at depth-0 (not inside (), [], {}, or quotes).
    Respects single-quoted and double-quoted strings.
    Returns a list of stripped parts."""
    parts = []
    depth = 0
    in_single = False
    in_double = False
    escape = False
    start = 0
    i = 0
    while i < len(text):
        ch = text[i]
        if escape:
            escape = False
            i += 1
            continue
        if ch == '\\' and (in_single or in_double):
            escape = True
            i += 1
            continue
        if in_single:
            if ch == "'":
                in_single = False
            i += 1
            continue
        if in_double:
            if ch == '"':
                in_double = False
            i += 1
            continue
        if ch == "'":
            in_single = True
        elif ch == '"':
            in_double = True
        elif ch in '([{':
            depth += 1
        elif ch in ')]}':
            depth -= 1
        elif ch == ',' and depth == 0:
            parts.append(text[start:i].strip())
            start = i + 1
        i += 1
    tail = text[start:].strip()
    if tail:
        parts.append(tail)
    return parts


def split_top_level_semicolons(text):
    """Split *text* on semicolons at depth-0 (outside (), [], {}, or quotes)."""
    parts = []
    depth = 0
    in_single = False
    in_double = False
    escape = False
    start = 0
    i = 0
    while i < len(text):
        ch = text[i]
        if escape:
            escape = False
            i += 1
            continue
        if ch == '\\' and (in_single or in_double):
            escape = True
            i += 1
            continue
        if in_single:
            if ch == "'":
                in_single = False
            i += 1
            continue
        if in_double:
            if ch == '"':
                in_double = False
            i += 1
            continue
        if ch == "'":
            in_single = True
        elif ch == '"':
            in_double = True
        elif ch in '([{':
            depth += 1
        elif ch in ')]}':
            depth -= 1
        elif ch == ';' and depth == 0:
            parts.append(text[start:i].strip())
            start = i + 1
        i += 1
    tail = text[start:].strip()
    if tail:
        parts.append(tail)
    return parts

def normalize_quotes(expr):
    """Normalize single quotes to double quotes in JSON"""
    if not expr:
        return expr
    
    # Replace single quotes with double quotes, but be careful with nested quotes
    expr = re.sub(r"'([^']*)'", r'"\1"', expr)
    return expr

def format_js_output(content, indent_level=0):
    """Format JavaScript output with proper indentation"""
    lines = content.split('\n')
    formatted_lines = []
    
    for line in lines:
        if line.strip():
            formatted_lines.append('    ' * indent_level + line.strip())
        else:
            formatted_lines.append('')
    
    return '\n'.join(formatted_lines)