"""
Template AST - Parse blade template into abstract syntax tree for structured JS code generation.

Parses preprocessed blade template content (after declaration removal, verbatim extraction, etc.)
into a tree of nodes that can be walked by RenderGenerator to produce structured this.html(),
this.reactive(), this.output() calls matching the hydrate IDs from sao2blade.
"""

import re
import sys
import os

_parent_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _parent_dir not in sys.path:
    sys.path.insert(0, _parent_dir)

from common.php_converter import php_to_js, php_to_js_advanced
from common.utils import extract_balanced_parentheses
from event_directive_processor import EventDirectiveProcessor


# ── AST Node Classes ──────────────────────────────────────────────────

class Node:
    """Base AST node."""
    pass


class RootNode(Node):
    def __init__(self):
        self.children = []


class HtmlElement(Node):
    def __init__(self, tag, is_void=False):
        self.tag = tag
        self.is_void = is_void
        self.children = []
        self.static_classes = []       # ['demo', 'container']
        self.binding_classes = {}      # {'active': {'php': '$status', 'js': 'status', 'state_vars': {'status'}}}
        self.static_attrs = {}         # {'id': 'counter-value'}
        self.binding_attrs = {}        # {'data-count': {'php': 'count($demoList)', 'js': '...', 'state_vars': {...}}}
        self.events = {}               # {'click': ['setStatus(!status)']}
        self.raw_attrs_remaining = ''  # Any unprocessed attribute fragments


class TextNode(Node):
    def __init__(self, text):
        self.text = text


class EchoNode(Node):
    """{{ expr }} or {!! expr !!}"""
    def __init__(self, php_expr, js_expr, escaped=True, state_vars=None):
        self.php_expr = php_expr
        self.js_expr = js_expr
        self.escaped = escaped
        self.state_vars = state_vars or set()


class IfBlock(Node):
    def __init__(self):
        self.branches = []  # [(condition_php, condition_js, [children])]
        self.state_vars = set()


class ForeachBlock(Node):
    def __init__(self, array_php, array_js, value_var, key_var=None):
        self.array_php = array_php
        self.array_js = array_js
        self.value_var = value_var
        self.key_var = key_var
        self.custom_key = None     # Expression from @key(...)
        self.custom_key_js = None  # Transpiled JS for @key
        self.children = []
        self.state_vars = set()


class WhileBlock(Node):
    def __init__(self, condition_php, condition_js, loop_var=None, end_val=None):
        self.condition_php = condition_php
        self.condition_js = condition_js
        self.loop_var = loop_var   # e.g. 'i'
        self.end_val = end_val     # e.g. '5'
        self.custom_key = None
        self.custom_key_js = None
        self.children = []


class ForBlock(Node):
    def __init__(self, var_name, start_js, end_js, operator):
        self.var_name = var_name
        self.start_js = start_js
        self.end_js = end_js
        self.operator = operator
        self.custom_key = None
        self.custom_key_js = None
        self.children = []
        self.state_vars = set()


class SwitchBlock(Node):
    def __init__(self, expr_php, expr_js):
        self.expr_php = expr_php
        self.expr_js = expr_js
        self.cases = []    # [(value_js or None, [children])]
        self.state_vars = set()


class SectionNode(Node):
    """@section('name', value) — short section for SEO/meta text."""
    def __init__(self, name, value_php, value_js, content_type='text', state_vars=None):
        self.name = name
        self.value_php = value_php
        self.value_js = value_js
        self.content_type = content_type  # 'text' or 'html'
        self.state_vars = state_vars or set()


class LongSectionNode(Node):
    """@section('name') ... @endsection — long section with HTML content."""
    def __init__(self, name):
        self.name = name
        self.children = []
        self.state_vars = set()


class BlockSection(Node):
    def __init__(self, name):
        self.name = name
        self.children = []


class BlockOutlet(Node):
    def __init__(self, name):
        self.name = name


class YieldNode(Node):
    """@yield('name') or @yield('name', 'default') — yield section content."""
    def __init__(self, name, default_php=None, default_js=None):
        self.name = name
        self.default_php = default_php
        self.default_js = default_js


class IncludeNode(Node):
    def __init__(self, path_php, path_js, data_php=None, data_js=None):
        self.path_php = path_php
        self.path_js = path_js
        self.data_php = data_php
        self.data_js = data_js


class ImportIncludeNode(Node):
    """@importInclude with children content — component with slot children."""
    def __init__(self, path_php, path_js, data_pairs=None):
        self.path_php = path_php
        self.path_js = path_js
        self.data_pairs = data_pairs or []  # list of (key, value_js) tuples
        self.children = []  # AST nodes for __ONE_CHILDREN_CONTENT__


class ExecNode(Node):
    def __init__(self, js_expr):
        self.js_expr = js_expr


# ── Constants ─────────────────────────────────────────────────────────

VOID_ELEMENTS = frozenset({
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
    'link', 'meta', 'param', 'source', 'track', 'wbr'
})

EVENT_NAMES = frozenset({
    'click', 'dblclick', 'mousedown', 'mouseup', 'mouseover', 'mouseout',
    'mousemove', 'mouseenter', 'mouseleave', 'wheel',
    'keydown', 'keyup', 'keypress',
    'input', 'change', 'submit', 'reset', 'invalid',
    'focus', 'blur', 'focusin', 'focusout',
    'touchstart', 'touchmove', 'touchend', 'touchcancel',
    'dragstart', 'drag', 'dragend', 'dragenter', 'dragleave', 'dragover', 'drop',
    'scroll', 'resize', 'contextmenu',
    'copy', 'cut', 'paste', 'select',
    'load', 'error', 'abort',
    'animationstart', 'animationend', 'animationiteration',
    'transitionstart', 'transitionend', 'transitionrun', 'transitioncancel',
    'pointerdown', 'pointerup', 'pointermove', 'pointerover', 'pointerout',
    'pointerenter', 'pointerleave', 'pointercancel',
})

# Directives to skip (handled in preprocessing or not relevant for AST)
SKIP_DIRECTIVES = frozenset({
    'extends', 'vars', 'useState', 'const', 'let', 'props', 'states',
    'fetch', 'await', 'oninit', 'endoninit', 'register', 'endregister',
    'setup', 'endsetup', 'script', 'endscript', 'import',
    'pageStart', 'pageEnd', 'pageOpen', 'pageClose',
    'docStart', 'docEnd', 'wrapper', 'endWrapper',
    'startMarker', 'endMarker', 'hydrate',
    'serverside', 'endserverside', 'ServerSide', 'endServerSide',
    'ssr', 'endssr', 'SSR', 'endSSR',
    'clientside', 'endclientside', 'ClientSide', 'endClientSide',
    'csr', 'endcsr', 'CSR', 'endCSR',
    'children',
})


# ── AST Parser ────────────────────────────────────────────────────────

class TemplateASTParser:
    """Parse preprocessed blade template content into an AST.

    Input: Template content after declaration removal, verbatim extraction,
           @extends removal, etc. (as done by main_compiler.py preprocessing).
    Output: RootNode with tree of child nodes.
    """

    def __init__(self, state_variables=None):
        self.state_variables = state_variables or set()
        self.event_processor = EventDirectiveProcessor(self.state_variables)

    def parse(self, template_content):
        """Parse template string into AST."""
        root = RootNode()
        lines = template_content.split('\n')

        # Unified stack: [(parent_node, type_str, extra_data)]
        # type_str: 'root', 'html', 'block', 'if', 'foreach', 'while', 'for', 'switch', 'case'
        stack = [(root, 'root', None)]

        i = 0
        while i < len(lines):
            line = lines[i]
            stripped = line.strip()

            if not stripped:
                i += 1
                continue

            # Skip blade comments {{-- ... --}}
            if stripped.startswith('{{--') and stripped.endswith('--}}'):
                i += 1
                continue

            # ── Try directives first ──────────────────────────────
            handled = self._try_directive(stripped, stack)
            if handled:
                i += 1
                continue

            # ── Process HTML + text + echo content ────────────────
            self._process_content_line(stripped, stack)
            i += 1

        return root

    # ──────────────────────────────────────────────────────────────────
    # Directive handlers
    # ──────────────────────────────────────────────────────────────────

    def _try_directive(self, stripped, stack):
        """Try to handle the line as a blade directive. Return True if handled."""

        # @section('name', value) — short section
        # @section('name') — long section start
        if re.match(r'@section\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@section')
            if expr is not None:
                comma_pos = self._find_first_comma(expr)
                if comma_pos != -1:
                    # Short section: @section('name', value)
                    name_raw = expr[:comma_pos].strip()
                    value_raw = expr[comma_pos + 1:].strip()
                    name_match = re.match(r"['\"]([^'\"]*)['\"]$", name_raw)
                    if name_match:
                        section_name = name_match.group(1)
                        value_js = php_to_js(value_raw) if value_raw else "''"
                        svars = self._get_state_vars(value_raw)
                        node = SectionNode(section_name, value_raw, value_js, 'text', svars)
                        self._add_child(stack, node)
                        return True
                else:
                    # Long section: @section('name')
                    name_match = re.match(r"['\"]([^'\"]*)['\"]$", expr.strip())
                    if name_match:
                        section_name = name_match.group(1)
                        node = LongSectionNode(section_name)
                        self._add_child(stack, node)
                        stack.append((node, 'section', None))
                        return True

        # @endsection
        if re.match(r'@endsection\b', stripped):
            self._pop_to(stack, 'section')
            return True

        # @block('name')
        m = re.match(r"@block\s*\(\s*['\"](\w+)['\"]", stripped)
        if m:
            node = BlockSection(m.group(1))
            self._add_child(stack, node)
            stack.append((node, 'block', None))
            return True

        # @endblock / @endBlock
        if re.match(r'@end[Bb]lock\b', stripped):
            self._pop_to(stack, 'block')
            return True

        # @if(...)
        if re.match(r'@if\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@if')
            if expr is not None:
                node = IfBlock()
                svars = self._get_state_vars(expr)
                node.state_vars = svars
                cond_js = php_to_js(expr)
                node.branches.append((expr, cond_js, []))
                self._add_child(stack, node)
                stack.append((node, 'if', None))
                return True

        # @elseif(...)
        if re.match(r'@elseif\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@elseif')
            if expr is not None:
                if_node = self._find_on_stack(stack, 'if')
                if if_node:
                    svars = self._get_state_vars(expr)
                    if_node.state_vars |= svars
                    cond_js = php_to_js(expr)
                    if_node.branches.append((expr, cond_js, []))
                return True

        # @else
        if re.match(r'@else\s*$', stripped):
            if_node = self._find_on_stack(stack, 'if')
            if if_node:
                if_node.branches.append((None, None, []))
            return True

        # @endif
        if re.match(r'@endif\b', stripped):
            self._pop_to(stack, 'if')
            return True

        # @foreach(...)
        if re.match(r'@foreach\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@foreach')
            if expr is not None:
                as_m = re.match(r'\s*(.*?)\s+as\s+\$?(\w+)(\s*=>\s*\$?(\w+))?\s*$', expr)
                if as_m:
                    array_php = as_m.group(1)
                    array_js = php_to_js(array_php)
                    if as_m.group(3):
                        key_var = as_m.group(2)
                        value_var = as_m.group(4)
                    else:
                        key_var = None
                        value_var = as_m.group(2)
                    node = ForeachBlock(array_php, array_js, value_var, key_var)
                    node.state_vars = self._get_state_vars(array_php)
                    self._add_child(stack, node)
                    stack.append((node, 'foreach', None))
                    return True

        # @endforeach
        if re.match(r'@endforeach\b', stripped):
            self._pop_to(stack, 'foreach')
            return True

        # @key(...)
        if re.match(r'@key\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@key')
            if expr is not None:
                # Find nearest loop (foreach, while, or for)
                loop_node = None
                for node, ntype, _ in reversed(stack):
                    if ntype in ('foreach', 'while', 'for'):
                        loop_node = node
                        break
                if loop_node:
                    loop_node.custom_key = expr
                    loop_node.custom_key_js = php_to_js(expr)
            return True

        # @while(...)
        if re.match(r'@while\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@while')
            if expr is not None:
                cond_js = php_to_js(expr)
                loop_var = self._extract_while_var(expr)
                end_val = self._extract_while_end(expr)
                node = WhileBlock(expr, cond_js, loop_var, end_val)
                self._add_child(stack, node)
                stack.append((node, 'while', None))
                return True

        # @endwhile
        if re.match(r'@endwhile\b', stripped):
            self._pop_to(stack, 'while')
            return True

        # @for(...)
        if re.match(r'@for\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@for')
            if expr is not None:
                m = re.match(r'\s*\$?(\w+)\s*=\s*(.*?);\s*\$?\1\s*([<>=!]+)\s*(.*?);\s*\$?\1\s*\+\+\s*$', expr)
                if m:
                    var_name = m.group(1)
                    start_js = php_to_js(m.group(2).strip())
                    end_js = php_to_js(m.group(4).strip())
                    operator = m.group(3)
                    node = ForBlock(var_name, start_js, end_js, operator)
                    node.state_vars = self._get_state_vars(expr)
                    self._add_child(stack, node)
                    stack.append((node, 'for', None))
                    return True

        # @endfor
        if re.match(r'@endfor\b', stripped):
            self._pop_to(stack, 'for')
            return True

        # @switch(...)
        if re.match(r'@switch\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@switch')
            if expr is not None:
                expr_js = php_to_js(expr)
                node = SwitchBlock(expr, expr_js)
                node.state_vars = self._get_state_vars(expr)
                self._add_child(stack, node)
                stack.append((node, 'switch', None))
                return True

        # @case(...)
        if re.match(r'@case\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@case')
            if expr is not None:
                val_js = php_to_js(expr)
                sw = self._find_on_stack(stack, 'switch')
                if sw:
                    # Pop current case if one is open
                    if stack[-1][1] == 'case':
                        stack.pop()
                    children = []
                    sw.cases.append((val_js, children))
                    stack.append((sw, 'case', len(sw.cases) - 1))
                return True

        # @default
        if re.match(r'@default\s*$', stripped):
            sw = self._find_on_stack(stack, 'switch')
            if sw:
                if stack[-1][1] == 'case':
                    stack.pop()
                children = []
                sw.cases.append((None, children))
                stack.append((sw, 'case', len(sw.cases) - 1))
            return True

        # @break
        if re.match(r'@break\b', stripped):
            return True

        # @endswitch
        if re.match(r'@endswitch\b', stripped):
            if stack[-1][1] == 'case':
                stack.pop()
            self._pop_to(stack, 'switch')
            return True

        # @useBlock('name') / @blockOutlet('name')
        m = re.match(r"@(?:useBlock|blockOutlet|blockoutlet)\s*\(\s*['\"](\w+)['\"]", stripped)
        if m:
            node = BlockOutlet(m.group(1))
            self._add_child(stack, node)
            return True

        # @yield('name') or @yield('name', 'default')
        if re.match(r'@yield\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@yield')
            if expr is not None:
                parts = self._split_php_array(expr)
                name = parts[0].strip().strip("'\"")
                default_php = parts[1].strip() if len(parts) > 1 else None
                default_js = php_to_js(default_php) if default_php else None
                # If default is a simple string literal, wrap in quotes
                if default_php and not default_php.startswith('$') and default_js and not default_js.startswith("'") and not default_js.startswith('"'):
                    default_js = f"'{default_js}'"
                node = YieldNode(name, default_php, default_js)
                self._add_child(stack, node)
                return True

        # @exec(...)
        if re.match(r'@exec\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@exec')
            if expr is not None:
                from common.utils import split_top_level_commas
                stmts = split_top_level_commas(expr)
                js_expr = '; '.join(php_to_js(s) for s in stmts if s.strip())
                node = ExecNode(js_expr)
                self._add_child(stack, node)
                return True

        # @include(...)
        if re.match(r'@include\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@include')
            if expr is not None:
                path_php, data_php = self._parse_include_params(expr)
                if path_php:
                    path_js = php_to_js(path_php)
                    # If original PHP path is a simple string (no $), wrap JS in quotes
                    if '$' not in path_php and re.match(r'^[a-zA-Z_][\w.]*$', path_js):
                        path_js = f"'{path_js}'"
                else:
                    path_js = "''"
                data_js = php_to_js(data_php) if data_php else None
                node = IncludeNode(path_php, path_js, data_php, data_js)
                self._add_child(stack, node)
                return True

        # @importInclude(tagName, path [, data])
        if re.match(r'@importInclude\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@importInclude')
            if expr is not None:
                path_php, path_js, data_pairs = self._parse_import_include_params(expr)
                node = ImportIncludeNode(path_php, path_js, data_pairs)
                self._add_child(stack, node)
                stack.append((node, 'importInclude', None))
                return True

        # @endImportInclude
        if re.match(r'@endImportInclude\b', stripped):
            self._pop_to(stack, 'importInclude')
            return True

        # Skip known directives that don't produce AST nodes
        m = re.match(r'@(\w+)', stripped)
        if m and m.group(1) in SKIP_DIRECTIVES:
            return True

        return False

    # ──────────────────────────────────────────────────────────────────
    # HTML + content processing
    # ──────────────────────────────────────────────────────────────────

    def _process_content_line(self, line, stack):
        """Process a line containing HTML tags, text, and echo expressions.
        Modifies the stack as HTML tags open and close."""
        pos = 0
        length = len(line)

        while pos < length:
            # Skip whitespace at current position (but don't skip all leading)
            if pos == 0 and line[pos] in ' \t':
                while pos < length and line[pos] in ' \t':
                    pos += 1
                if pos >= length:
                    break

            # ── HTML comment <!-- ... --> ─────────────────────────
            if line[pos:pos+4] == '<!--':
                end_idx = line.find('-->', pos + 4)
                if end_idx != -1:
                    pos = end_idx + 3
                else:
                    # Unclosed comment — skip entire rest of line
                    break
                continue

            # ── <!DOCTYPE ...> and other <! declarations ──────────
            if line[pos:pos+2] == '<!':
                end_idx = line.find('>', pos + 2)
                if end_idx != -1:
                    pos = end_idx + 1
                else:
                    break
                continue

            # ── Closing tag ───────────────────────────────────────
            close_m = re.match(r'</\s*([a-zA-Z][\w-]*)\s*>', line[pos:])
            if close_m:
                tag = close_m.group(1).lower()
                self._pop_html_tag(stack, tag)
                pos += close_m.end()
                continue

            # ── Opening tag ───────────────────────────────────────
            open_m = re.match(r'<([a-zA-Z][\w-]*)', line[pos:])
            if open_m:
                tag = open_m.group(1)
                tag_lower = tag.lower()
                pos += open_m.end()

                # Parse attributes until > or />
                attrs_str, pos, is_self_closing = self._scan_tag_end(line, pos)
                is_void = tag_lower in VOID_ELEMENTS or is_self_closing

                element = HtmlElement(tag_lower, is_void)
                self._parse_element_attributes(attrs_str, element)
                self._add_child(stack, element)

                if not is_void:
                    stack.append((element, 'html', tag_lower))
                continue

            # ── Text / echo content ───────────────────────────────
            next_tag = line.find('<', pos)
            if next_tag == -1:
                next_tag = length
            if next_tag == pos:
                # Unrecognized '<' (e.g. stray < in text) — skip past it
                pos += 1
                continue
            text_segment = line[pos:next_tag]
            if text_segment.strip():
                self._parse_inline_content(text_segment, stack)
            pos = next_tag

    def _parse_inline_content(self, content, stack):
        """Parse text that may contain {{ expr }} and {!! expr !!} echo expressions.
        Results are added as children of the current stack parent."""
        pos = 0
        text_buf = ''

        while pos < len(content):
            # {{-- comment --}}
            if content[pos:pos+4] == '{{--':
                end = content.find('--}}', pos)
                if end != -1:
                    if text_buf:
                        self._add_child(stack, TextNode(text_buf))
                        text_buf = ''
                    pos = end + 4
                    continue

            # {!! raw echo !!}
            if content[pos:pos+3] == '{!!':
                m = re.match(r'\{!!\s*(.*?)\s*!!\}', content[pos:], re.DOTALL)
                if m:
                    if text_buf:
                        self._add_child(stack, TextNode(text_buf))
                        text_buf = ''
                    expr = m.group(1)
                    js_expr = php_to_js_advanced(expr)
                    svars = self._get_state_vars(expr)
                    self._add_child(stack, EchoNode(expr, js_expr, escaped=False, state_vars=svars))
                    pos += m.end()
                    continue

            # {{ escaped echo }}
            if content[pos:pos+2] == '{{':
                m = re.match(r'\{\{\s*(.*?)\s*\}\}', content[pos:], re.DOTALL)
                if m:
                    if text_buf:
                        self._add_child(stack, TextNode(text_buf))
                        text_buf = ''
                    expr = m.group(1)
                    js_expr = php_to_js_advanced(expr)
                    svars = self._get_state_vars(expr)
                    self._add_child(stack, EchoNode(expr, js_expr, escaped=True, state_vars=svars))
                    pos += m.end()
                    continue

            # Same-line @if ... @else ... @endif inside text content.
            # This is still parsed as a normal block IfBlock, not an attribute-inline directive.
            if re.match(r'@if\s*\(', content[pos:]):
                if text_buf:
                    self._add_child(stack, TextNode(text_buf))
                    text_buf = ''
                end_pos = self._parse_block_if_in_text_content(content, pos, stack)
                if end_pos is not None:
                    pos = end_pos
                    continue

            text_buf += content[pos]
            pos += 1

        if text_buf:
            self._add_child(stack, TextNode(text_buf))

    def _parse_block_if_in_text_content(self, content, start_pos, stack):
        """Parse same-line @if ... @else ... @endif in text content as an IfBlock.

        This is intentionally separate from attribute-inline directives handled by
        TemplateProcessor._process_inline_directive().
        Supports text and echo expressions inside branches.
        """
        if not re.match(r'@if\s*\(', content[start_pos:]):
            return None

        open_paren = content.find('(', start_pos)
        if open_paren == -1:
            return None
        close_paren = self._find_close_paren(content, open_paren)
        if close_paren is None or close_paren == -1:
            return None

        expr = content[open_paren + 1:close_paren]

        depth = 0
        cursor = close_paren + 1
        else_pos = None
        endif_pos = None

        while cursor < len(content):
            if re.match(r'@if\s*\(', content[cursor:]):
                nested_open = content.find('(', cursor)
                nested_close = self._find_close_paren(content, nested_open)
                if nested_close is None or nested_close == -1:
                    return None
                depth += 1
                cursor = nested_close + 1
                continue

            if re.match(r'@endif\b', content[cursor:]):
                if depth == 0:
                    endif_pos = cursor
                    break
                depth -= 1
                cursor += len('@endif')
                continue

            if depth == 0 and else_pos is None and re.match(r'@else\b', content[cursor:]):
                else_pos = cursor
                cursor += len('@else')
                continue

            cursor += 1

        if endif_pos is None:
            return None

        then_content = content[close_paren + 1:else_pos if else_pos is not None else endif_pos]
        else_content = content[else_pos + len('@else'):endif_pos] if else_pos is not None else None

        node = IfBlock()
        svars = self._get_state_vars(expr)
        node.state_vars = svars
        cond_js = php_to_js(expr)
        node.branches.append((expr, cond_js, []))

        branch_stack = [(node, 'if', None)]
        self._parse_inline_content(then_content, branch_stack)

        if else_content is not None:
            node.branches.append((None, None, []))
            self._parse_inline_content(else_content, branch_stack)

        self._add_child(stack, node)
        return endif_pos + len('@endif')

    # ──────────────────────────────────────────────────────────────────
    # HTML attribute parsing
    # ──────────────────────────────────────────────────────────────────

    def _scan_tag_end(self, line, pos):
        """From the position after the tag name, scan until > or />.
        Returns (attrs_string, new_pos_after_gt, is_self_closing)."""
        start = pos
        in_quote = False
        quote_char = None
        paren_depth = 0
        bracket_depth = 0

        while pos < len(line):
            ch = line[pos]
            if ch in ('"', "'") and (pos == 0 or line[pos - 1] != '\\'):
                if not in_quote:
                    in_quote = True
                    quote_char = ch
                elif ch == quote_char:
                    in_quote = False
            elif not in_quote:
                if ch == '(':
                    paren_depth += 1
                elif ch == ')':
                    paren_depth -= 1
                elif ch == '[':
                    bracket_depth += 1
                elif ch == ']':
                    bracket_depth -= 1
                elif ch == '>' and paren_depth == 0 and bracket_depth == 0:
                    attrs_str = line[start:pos]
                    is_self_closing = attrs_str.rstrip().endswith('/')
                    if is_self_closing:
                        attrs_str = attrs_str.rstrip()[:-1]
                    return attrs_str, pos + 1, is_self_closing
            pos += 1

        # No closing > found on this line - return what we have
        return line[start:], pos, False

    def _parse_element_attributes(self, attrs_str, element):
        """Parse the attribute string of an HTML element and populate its properties."""
        attrs_str = attrs_str.strip()
        if not attrs_str:
            return

        pos = 0
        length = len(attrs_str)

        while pos < length:
            # Skip whitespace
            while pos < length and attrs_str[pos] in ' \t\n\r':
                pos += 1
            if pos >= length:
                break

            remaining = attrs_str[pos:]

            # @class([...])
            m = re.match(r'@class\s*\(', remaining)
            if m:
                paren_start = pos + m.end() - 1
                content = self._extract_balanced(attrs_str, paren_start)
                if content is not None:
                    self._parse_class_binding(content, element)
                    pos = self._find_close_paren(attrs_str, paren_start) + 1
                    continue

            # @attr([...])
            m = re.match(r'@attr\s*\(', remaining)
            if m:
                paren_start = pos + m.end() - 1
                content = self._extract_balanced(attrs_str, paren_start)
                if content is not None:
                    self._parse_attr_binding(content, element)
                    pos = self._find_close_paren(attrs_str, paren_start) + 1
                    continue

            # @subscribe([...])
            m = re.match(r'@subscribe\s*\(', remaining)
            if m:
                paren_start = pos + m.end() - 1
                content = self._extract_balanced(attrs_str, paren_start)
                if content is not None:
                    # Subscribe config stored as raw for now
                    pos = self._find_close_paren(attrs_str, paren_start) + 1
                    continue

            # Event directives: @click(...), @change(...), etc.
            m = re.match(r'@(\w+)\s*\(', remaining)
            if m:
                directive_name = m.group(1).lower()
                if directive_name in EVENT_NAMES:
                    paren_start = pos + m.end() - 1
                    content = self._extract_balanced(attrs_str, paren_start)
                    if content is not None:
                        handler_items = self.event_processor.process_event_items(content)
                        if directive_name not in element.events:
                            element.events[directive_name] = []
                        element.events[directive_name].extend(handler_items)
                        pos = self._find_close_paren(attrs_str, paren_start) + 1
                        continue
                # Also handle @onEventName pattern
                elif directive_name.startswith('on') and directive_name[2:].lower() in EVENT_NAMES:
                    actual_event = directive_name[2:].lower()
                    paren_start = pos + m.end() - 1
                    content = self._extract_balanced(attrs_str, paren_start)
                    if content is not None:
                        handler_items = self.event_processor.process_event_items(content)
                        if actual_event not in element.events:
                            element.events[actual_event] = []
                        element.events[actual_event].extend(handler_items)
                        pos = self._find_close_paren(attrs_str, paren_start) + 1
                        continue

            # class="..." or class='...'
            m = re.match(r'class\s*=\s*"([^"]*)"', remaining)
            if not m:
                m = re.match(r"class\s*=\s*'([^']*)'", remaining)
            if m:
                classes = m.group(1).split()
                element.static_classes.extend(classes)
                pos += m.end()
                continue

            # Regular attr="value" or attr='value'
            m = re.match(r'([a-zA-Z_:][\w:.-]*)\s*=\s*"([^"]*)"', remaining)
            if not m:
                m = re.match(r"([a-zA-Z_:][\w:.-]*)\s*=\s*'([^']*)'", remaining)
            if m:
                attr_name = m.group(1)
                attr_value = m.group(2)
                # Check for @yield(...) in attribute value
                yield_m = re.match(r'^@yield\s*\(\s*(.*?)\s*\)$', attr_value)
                if yield_m:
                    yield_parts = self._split_php_array(yield_m.group(1))
                    yield_name = yield_parts[0].strip().strip("'\"")
                    yield_default_php = yield_parts[1].strip() if len(yield_parts) > 1 else None
                    yield_default_js = php_to_js(yield_default_php) if yield_default_php else 'null'
                    if yield_default_php and not yield_default_php.startswith('$') and yield_default_js and not yield_default_js.startswith("'") and not yield_default_js.startswith('"'):
                        yield_default_js = f"'{yield_default_js}'"
                    element.binding_attrs[attr_name] = {
                        'php': attr_value,
                        'js': f"this.yieldContent('{yield_name}', {yield_default_js})",
                        'state_vars': set(),
                        'is_yield': True,
                    }
                    pos += m.end()
                    continue
                # Check for {{ }} in attribute value
                if '{{' in attr_value or '{!!' in attr_value:
                    # Parse as binding attribute with echo expressions
                    js_val, svars = self._convert_attr_echo_value(attr_value)
                    element.binding_attrs[attr_name] = {
                        'php': attr_value, 'js': js_val, 'state_vars': svars
                    }
                else:
                    element.static_attrs[attr_name] = attr_value
                pos += m.end()
                continue

            # Boolean attribute (no value, e.g. "disabled", "checked")
            m = re.match(r'([a-zA-Z_:][\w:.-]*)\b', remaining)
            if m:
                attr_name = m.group(1)
                # Skip if starts with @ (directive we didn't recognize)
                if not attr_name.startswith('@'):
                    element.static_attrs[attr_name] = True
                pos += m.end()
                continue

            # Skip unrecognized character
            pos += 1

    def _parse_class_binding(self, content, element):
        """Parse @class([...]) content and populate element classes."""
        content = content.strip()
        if content.startswith('['):
            content = content[1:]
        if content.endswith(']'):
            content = content[:-1]

        entries = self._split_php_array(content)
        for entry in entries:
            entry = entry.strip()
            if not entry:
                continue
            if '=>' in entry:
                parts = entry.split('=>', 1)
                class_name = parts[0].strip().strip("'\"")
                cond_php = parts[1].strip()
                cond_js = php_to_js(cond_php)
                svars = self._get_state_vars(cond_php)
                element.binding_classes[class_name] = {
                    'php': cond_php, 'js': cond_js, 'state_vars': svars
                }
            else:
                # Static class in @class array
                class_name = entry.strip().strip("'\"")
                if class_name:
                    element.static_classes.append(class_name)

    def _parse_attr_binding(self, content, element):
        """Parse @attr([...]) content and populate element attrs."""
        content = content.strip()
        if content.startswith('['):
            content = content[1:]
        if content.endswith(']'):
            content = content[:-1]

        entries = self._split_php_array(content)
        for entry in entries:
            entry = entry.strip()
            if not entry:
                continue
            if '=>' in entry:
                parts = entry.split('=>', 1)
                attr_name = parts[0].strip().strip("'\"")
                val_php = parts[1].strip()
                val_js = php_to_js(val_php)
                svars = self._get_state_vars(val_php)
                element.binding_attrs[attr_name] = {
                    'php': val_php, 'js': val_js, 'state_vars': svars
                }

    def _convert_attr_echo_value(self, attr_value):
        """Convert an attribute value containing {{ }} to JS and extract state vars.
        Returns (js_expression, state_vars_set)."""
        all_svars = set()
        result = attr_value

        # Replace {!! !!} first
        def replace_raw(m):
            expr = m.group(1).strip()
            js = php_to_js_advanced(expr)
            all_svars.update(self._get_state_vars(expr))
            return f'${{{js}}}'
        result = re.sub(r'\{!!\s*(.*?)\s*!!\}', replace_raw, result, flags=re.DOTALL)

        # Replace {{ }}
        def replace_echo(m):
            expr = m.group(1).strip()
            js = php_to_js_advanced(expr)
            all_svars.update(self._get_state_vars(expr))
            return f'${{{js}}}'
        result = re.sub(r'\{\{\s*(.*?)\s*\}\}', replace_echo, result, flags=re.DOTALL)

        return result, all_svars

    # ──────────────────────────────────────────────────────────────────
    # Stack management helpers
    # ──────────────────────────────────────────────────────────────────

    def _add_child(self, stack, node):
        """Add a child node to the current parent on the stack."""
        parent_entry = stack[-1]
        parent_node = parent_entry[0]
        parent_type = parent_entry[1]

        if parent_type == 'case':
            # Switch case: add to the switch's current case children
            sw_node = parent_node  # parent_node is the SwitchBlock
            case_idx = parent_entry[2]
            sw_node.cases[case_idx][1].append(node)
        elif isinstance(parent_node, IfBlock):
            # IfBlock: add to current (last) branch children
            if parent_node.branches:
                parent_node.branches[-1][2].append(node)
        elif hasattr(parent_node, 'children'):
            parent_node.children.append(node)

    def _pop_html_tag(self, stack, tag_name):
        """Pop stack entries until we close the matching HTML tag."""
        # Walk backwards to find the matching html entry
        for idx in range(len(stack) - 1, 0, -1):
            if stack[idx][1] == 'html' and stack[idx][2] == tag_name:
                # Pop everything above it (handles malformed nesting gracefully)
                del stack[idx:]
                return
        # If not found, just ignore (malformed template)

    def _pop_to(self, stack, target_type):
        """Pop stack until an entry of target_type is found and removed."""
        while len(stack) > 1:
            if stack[-1][1] == target_type:
                stack.pop()
                return
            stack.pop()

    def _find_on_stack(self, stack, target_type):
        """Find the node of given type on the stack (search from top)."""
        for entry in reversed(stack):
            if entry[1] == target_type:
                return entry[0]
        return None

    # ──────────────────────────────────────────────────────────────────
    # Expression helpers
    # ──────────────────────────────────────────────────────────────────

    def _get_state_vars(self, expr):
        """Get state variable names referenced in a PHP expression."""
        if not expr:
            return set()
        found = re.findall(r'\$([a-zA-Z_]\w*)', expr)
        return set(v for v in found if v in self.state_variables)

    def _extract_directive_parens(self, line, directive):
        """Extract content from @directive(...) parentheses."""
        pattern = re.escape(directive) + r'\s*\('
        match = re.search(pattern, line)
        if not match:
            return None
        paren_start = match.end() - 1
        return self._extract_balanced(line, paren_start)

    def _find_first_comma(self, content):
        """Find the first comma not inside quotes or nested parentheses."""
        depth = 0
        in_single = False
        in_double = False
        for i, ch in enumerate(content):
            if ch == "'" and not in_double:
                in_single = not in_single
            elif ch == '"' and not in_single:
                in_double = not in_double
            elif ch == '(' and not in_single and not in_double:
                depth += 1
            elif ch == ')' and not in_single and not in_double:
                depth -= 1
            elif ch == ',' and depth == 0 and not in_single and not in_double:
                return i
        return -1

    def _extract_balanced(self, text, start):
        """Extract balanced parentheses content from start position.
        Returns inner content (without outer parens) or None."""
        if start >= len(text) or text[start] != '(':
            return None
        depth = 0
        in_str = False
        str_ch = None
        i = start
        while i < len(text):
            ch = text[i]
            if in_str:
                if ch == '\\' and i + 1 < len(text):
                    i += 2
                    continue
                if ch == str_ch:
                    in_str = False
            else:
                if ch in ('"', "'"):
                    in_str = True
                    str_ch = ch
                elif ch == '(':
                    depth += 1
                elif ch == ')':
                    depth -= 1
                    if depth == 0:
                        return text[start + 1:i]
            i += 1
        return None

    def _find_close_paren(self, text, start):
        """Find the matching close paren position for open paren at start."""
        depth = 0
        in_str = False
        str_ch = None
        i = start
        while i < len(text):
            ch = text[i]
            if in_str:
                if ch == '\\' and i + 1 < len(text):
                    i += 2
                    continue
                if ch == str_ch:
                    in_str = False
            else:
                if ch in ('"', "'"):
                    in_str = True
                    str_ch = ch
                elif ch == '(':
                    depth += 1
                elif ch == ')':
                    depth -= 1
                    if depth == 0:
                        return i
            i += 1
        return len(text) - 1

    def _split_php_array(self, content):
        """Split PHP array entries by comma, respecting nesting and quotes."""
        entries = []
        depth = 0
        paren_depth = 0
        current = ''
        in_quote = False
        quote_char = None

        for ch in content:
            if ch in ("'", '"') and not in_quote:
                in_quote = True
                quote_char = ch
            elif in_quote and ch == quote_char:
                in_quote = False

            if not in_quote:
                if ch in ('[', '{'):
                    depth += 1
                elif ch in (']', '}'):
                    depth -= 1
                elif ch == '(':
                    paren_depth += 1
                elif ch == ')':
                    paren_depth -= 1
                elif ch == ',' and depth == 0 and paren_depth == 0:
                    entries.append(current)
                    current = ''
                    continue

            current += ch

        if current.strip():
            entries.append(current)
        return entries

    def _parse_include_params(self, expr):
        """Parse @include parameters: path and optional data.
        Returns (path_php, data_php) or (path_php, None)."""
        # Find first comma not inside nesting
        parts = self._split_php_array(expr)
        if len(parts) >= 2:
            path_raw = parts[0].strip()
            # Only strip wrapping quotes for simple string paths (e.g. 'views.home')
            # Don't strip for dynamic expressions like $__template__.'sessions.tasks'
            if (path_raw.startswith("'") and path_raw.endswith("'") and path_raw.count("'") == 2) or \
               (path_raw.startswith('"') and path_raw.endswith('"') and path_raw.count('"') == 2):
                path_raw = path_raw[1:-1]
            return path_raw, parts[1].strip()
        path_raw = expr.strip()
        if (path_raw.startswith("'") and path_raw.endswith("'") and path_raw.count("'") == 2) or \
           (path_raw.startswith('"') and path_raw.endswith('"') and path_raw.count('"') == 2):
            path_raw = path_raw[1:-1]
        return path_raw, None

    def _parse_import_include_params(self, expr):
        """Parse @importInclude parameters: tagName, path [, data].
        Returns (path_php, path_js, data_pairs) where data_pairs is list of (key, value_js)."""
        parts = self._split_php_array(expr)
        
        if len(parts) == 0:
            return expr.strip(), "''", []
        
        # First part is tagName (ignored for JS output)
        if len(parts) == 1:
            path_php = parts[0].strip()
        else:
            path_php = parts[1].strip()
        
        # Convert path
        path_js = php_to_js(path_php)
        if '$' not in path_php and re.match(r'^[a-zA-Z_][\w.]*$', path_js):
            path_js = f"'{path_js}'"
        
        # Parse data pairs if present
        data_pairs = []
        if len(parts) >= 3:
            data_str = parts[2].strip()
            # Remove outer [ ]
            inner = data_str
            if inner.startswith('[') and inner.endswith(']'):
                inner = inner[1:-1].strip()
            if inner:
                entries = self._split_php_array(inner)
                for entry in entries:
                    entry = entry.strip()
                    if not entry:
                        continue
                    kv_match = re.match(r"""['"]([^'"]+)['"]\s*=>\s*(.+)""", entry, re.DOTALL)
                    if kv_match:
                        key = kv_match.group(1)
                        value_php = kv_match.group(2).strip()
                        value_js = php_to_js(value_php)
                        data_pairs.append((key, value_js))
        
        return path_php, path_js, data_pairs

    def _extract_while_var(self, expr):
        """Extract loop variable from while condition like '$i < 5'."""
        m = re.match(r'\s*\$(\w+)\s*[<>=!]', expr or '')
        return m.group(1) if m else None

    def _extract_while_end(self, expr):
        """Extract end value from while condition like '$i < 5'."""
        m = re.search(r'[<>]=?\s*(\d+)', expr or '')
        return m.group(1) if m else None
