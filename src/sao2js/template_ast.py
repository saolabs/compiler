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
from common.children_slot import (
    CHILDREN_DIRECTIVE_RE,
    ChildrenSlotError,
    is_children_expression,
)
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
        self.dynamic_classes = []      # class="language-{{ lang }}" — tên class do biểu thức sinh, không phải bật/tắt tên cố định
        self.static_attrs = {}         # {'id': 'counter-value'}
        self.binding_attrs = {}        # {'data-count': {'php': 'count($demoList)', 'js': '...', 'state_vars': {...}}}
        self.styles = {}               # @style — {'color': {'js': ..., 'state_vars': {...}}} hoặc {'static': '...'}
        self.binding_props = {}        # {'checked': {'php': '$todo->completed', 'js': 'todo.completed', 'state_vars': {...}}} — DOM property, không phải attribute
        self.events = {}               # {'click': ['setStatus(!status)']}
        self.event_modifiers = {}      # {'click': ['prevent', 'stop']} — từ @click.prevent.stop(...)
        self.transition_name = None    # @transition('fade') — tiền tố class enter/leave, bucket riêng
        self.bind_key = None           # @bind(username)/@val(username) — two-way binding state key, own config bucket (sibling of attrs/props/events), not smuggled through attrs
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
    def __init__(self, path_php, path_js, data_php=None, data_js=None, state_vars=None):
        self.path_php = path_php
        self.path_js = path_js
        self.data_php = data_php
        self.data_js = data_js
        self.state_vars = state_vars or set()


class ImportIncludeNode(Node):
    """@importInclude with children content — component with slot children."""
    def __init__(self, path_php, path_js, data_pairs=None, state_vars=None):
        self.path_php = path_php
        self.path_js = path_js
        self.data_pairs = data_pairs or []  # list of (key, value_js) tuples
        self.children = []  # AST nodes for __ONE_CHILDREN_CONTENT__
        self.state_vars = state_vars or set()


class ChildrenNode(Node):
    """@children / {{ $children }} lazy slot insertion point.

    This node never owns children. The parent component owns the slot AST and
    passes a factory that is materialized only when this node is rendered.
    """
    def __init__(self):
        pass


class ExecNode(Node):
    def __init__(self, js_expr):
        self.js_expr = js_expr


# ── Constants ─────────────────────────────────────────────────────────

VOID_ELEMENTS = frozenset({
    'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
    'link', 'meta', 'param', 'source', 'track', 'wbr'
})

# Thẻ "rawtext": nội dung là TEXT thuần, KHÔNG parse tag con (giống HTML spec).
# - RCDATA: vẫn nội suy {{ }} / {!! !!} (textarea/title là giá trị text có thể bind).
# - RAWTEXT: text nguyên bản, không nội suy (script/style).
RCDATA_ELEMENTS = frozenset({'textarea', 'title'})
RAWTEXT_ELEMENTS = frozenset({'script', 'style'})
RAW_CONTENT_ELEMENTS = RCDATA_ELEMENTS | RAWTEXT_ELEMENTS

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

# @click.prevent.stop(...) — modifier áp ở runtime (ViewController.addEventListener).
#   prevent → event.preventDefault()
#   stop    → event.stopPropagation()
#   self    → chỉ chạy khi event.target === event.currentTarget (kiểm TRƯỚC prevent/stop)
#   once    → addEventListener({ once: true })
EVENT_MODIFIERS = frozenset({'prevent', 'stop', 'self', 'once'})

# Tách class="..." thành token: `{{ ... }}`/`{!! ... !!}` là NGUYÊN KHỐI nên khoảng
# trắng bên trong biểu thức không cắt token — 'language-{{ lang }}' là một token,
# 'todo-item {{ done ? "x" : "" }}' là hai.
CLASS_TOKEN_RE = re.compile(r'(?:\{\{.*?\}\}|\{!!.*?!!\}|\S)+', re.DOTALL)

# @checked(expr)... → DOM property binding (el.checked = !!expr), map tên directive
# → tên property JS (readonly → readOnly).
BOOL_PROP_DIRECTIVES = {
    'checked': 'checked',
    'disabled': 'disabled',
    'selected': 'selected',
    'readonly': 'readOnly',
    'required': 'required',
}

# Directives to skip (handled in preprocessing or not relevant for AST)
SKIP_DIRECTIVES = frozenset({
    'extends', 'vars', 'useState', 'props', 'states',
    'fetch', 'await', 'oninit', 'endoninit', 'register', 'endregister',
    'setup', 'endsetup', 'script', 'endscript', 'import',
    'pageStart', 'pageEnd', 'pageOpen', 'pageClose',
    'docStart', 'docEnd', 'wrapper', 'endWrapper',
    'startMarker', 'endMarker', 'hydrate',
    'serverside', 'endserverside', 'ServerSide', 'endServerSide',
    'ssr', 'endssr', 'SSR', 'endSSR',
    'clientside', 'endclientside', 'ClientSide', 'endClientSide',
    'csr', 'endcsr', 'CSR', 'endCSR',
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
        # Khi đang trong thẻ rawtext (textarea/script/style...) mở qua nhiều dòng:
        # giữ tên thẻ để mọi dòng tiếp theo coi là TEXT cho tới khi gặp </tag>.
        self._rawtext_tag = None
        self._children_placeholder_count = 0

    def parse(self, template_content):
        """Parse template string into AST."""
        root = RootNode()
        self._rawtext_tag = None
        self._children_placeholder_count = 0
        lines = template_content.split('\n')

        # Unified stack: [(parent_node, type_str, extra_data)]
        # type_str: 'root', 'html', 'block', 'if', 'foreach', 'while', 'for', 'switch', 'case'
        stack = [(root, 'root', None)]

        i = 0
        while i < len(lines):
            line = lines[i]

            # Đang trong rawtext (textarea/script/style mở dòng trước): cả dòng là
            # TEXT cho tới </tag>. KHÔNG strip (giữ nội dung), KHÔNG dò directive.
            if self._rawtext_tag is not None:
                self._process_content_line(line, stack)
                i += 1
                continue

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

        # @let(...)
        if re.match(r'@let\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@let')
            if expr is not None:
                js_expr = php_to_js(expr)
                js_expr = re.sub(r'\$(\w+)', r'\1', js_expr)
                node = ExecNode(js_expr)
                self._add_child(stack, node)
                return True

        # @const(...)
        if re.match(r'@const\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@const')
            if expr is not None:
                js_expr = php_to_js(expr)
                js_expr = re.sub(r'\$(\w+)', r'\1', js_expr)
                node = ExecNode(js_expr)
                self._add_child(stack, node)
                return True

        # @include(...)
        if re.match(r'@include\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@include')
            if expr is not None:
                path_php, data_php = self._parse_include_params(expr)
                if path_php:
                    path_js = self._convert_path_to_js(path_php)
                else:
                    path_js = "''"
                data_js = php_to_js(data_php) if data_php else None
                state_vars = self._get_state_vars(data_php) if data_php else set()
                node = IncludeNode(path_php, path_js, data_php, data_js, state_vars)
                self._add_child(stack, node)
                return True

        # @importInclude(tagName, path [, data])
        if re.match(r'@importInclude\s*\(', stripped):
            expr = self._extract_directive_parens(stripped, '@importInclude')
            if expr is not None:
                path_php, path_js, data_pairs, state_vars = self._parse_import_include_params(expr)
                node = ImportIncludeNode(path_php, path_js, data_pairs, state_vars)
                self._add_child(stack, node)
                stack.append((node, 'importInclude', None))
                return True

        # @endImportInclude
        if re.match(r'@endImportInclude\b', stripped):
            self._pop_to(stack, 'importInclude')
            return True

        # @children — slot placeholder (render children từ parent include)
        if CHILDREN_DIRECTIVE_RE.fullmatch(stripped):
            self._add_children_placeholder(stack)
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
            # ── Rawtext mode (textarea/script/style): nuốt text tới </tag> ──
            if self._rawtext_tag is not None:
                pos = self._consume_rawtext(line, pos, stack)
                continue

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
                    # Thẻ rawtext → bật mode: phần còn lại (kể cả các dòng sau)
                    # là TEXT cho tới </tag>, không parse tag con.
                    if tag_lower in RAW_CONTENT_ELEMENTS:
                        self._rawtext_tag = tag_lower
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

    def _consume_rawtext(self, line, pos, stack, length=None):
        """Nuốt nội dung rawtext của thẻ self._rawtext_tag trong `line` từ `pos`.

        Tìm </tag>: thấy → emit phần trước là text, pop thẻ, tắt mode, trả vị trí
        sau </tag> (parse tiếp bình thường). Không thấy → cả phần còn lại là text,
        giữ mode (dòng sau xử lý tiếp). KHÔNG parse tag con trong vùng này.
        """
        tag = self._rawtext_tag
        close_re = re.compile(r'</\s*' + re.escape(tag) + r'\s*>', re.IGNORECASE)
        m = close_re.search(line, pos)
        if m:
            self._emit_rawtext(line[pos:m.start()], stack, tag)
            self._pop_html_tag(stack, tag)
            self._rawtext_tag = None
            return m.end()
        # Chưa đóng trên dòng này → cả phần còn lại là text, giữ mode
        self._emit_rawtext(line[pos:], stack, tag)
        return len(line)

    def _emit_rawtext(self, raw, stack, tag):
        """Thêm nội dung rawtext vào con của thẻ hiện tại.
        RCDATA (textarea/title): vẫn nội suy {{ }}/{!! !!} (tag là text).
        RAWTEXT (script/style): text nguyên bản, không nội suy.
        """
        if not raw or not raw.strip():
            return
        if tag in RCDATA_ELEMENTS:
            self._parse_inline_content(raw, stack)
        else:
            self._add_child(stack, TextNode(raw))

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
                    if is_children_expression(expr):
                        self._add_children_placeholder(stack)
                    else:
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
                    if is_children_expression(expr):
                        self._add_children_placeholder(stack)
                    else:
                        js_expr = php_to_js_advanced(expr)
                        svars = self._get_state_vars(expr)
                        self._add_child(stack, EchoNode(expr, js_expr, escaped=True, state_vars=svars))
                    pos += m.end()
                    continue

            # Inline @children (for example: <div>@children</div>).
            directive_m = CHILDREN_DIRECTIVE_RE.match(content, pos)
            if directive_m:
                if text_buf:
                    self._add_child(stack, TextNode(text_buf))
                    text_buf = ''
                self._add_children_placeholder(stack)
                pos = directive_m.end()
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

    def _add_children_placeholder(self, stack):
        """Add the single lazy slot insertion point to the current AST parent."""
        self._children_placeholder_count += 1
        if self._children_placeholder_count > 1:
            raise ChildrenSlotError(
                'A component template may contain only one children placeholder '
                '(@children or {{ $children }}).'
            )
        self._add_child(stack, ChildrenNode())

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

            # @style({...}) — bucket riêng, runtime dùng style.setProperty nên chỉ
            # đụng đúng property được liệt kê (Html.initializeStyles).
            m = re.match(r'@style\s*\(', remaining)
            if m:
                paren_start = pos + m.end() - 1
                content = self._extract_balanced(attrs_str, paren_start)
                if content is not None:
                    self._parse_style_binding(content, element)
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

            # @bind(key) / @val(key) — two-way binding.
            # Contract runtime (Html.initializeBinding): config.bind = { key: "<key>" },
            # its own top-level config bucket — sibling of attrs/props/events, same
            # shape/spirit as events (not smuggled through attrs as boolean markers).
            m = re.match(r'@(?:bind|val)\s*\(', remaining)
            if m:
                paren_start = pos + m.end() - 1
                content = self._extract_balanced(attrs_str, paren_start)
                if content is not None:
                    element.bind_key = php_to_js_advanced(content.strip())
                    pos = self._find_close_paren(attrs_str, paren_start) + 1
                    continue

            # @transition('fade') — tiền tố class enter/leave. Bucket riêng
            # config.transition = { name: "fade" } (như @bind), runtime
            # Html.maybeRunEnter/destroy đọc trực tiếp.
            # Tên là HẰNG chuỗi, không phải biểu thức: nó sinh ra tên class CSS,
            # đổi theo state thì class chẳng khớp gì cả.
            m = re.match(r"@transition\s*\(", remaining)
            if m:
                paren_start = pos + m.end() - 1
                content = self._extract_balanced(attrs_str, paren_start)
                if content is not None:
                    name = content.strip().strip('\'"')
                    if re.fullmatch(r'[A-Za-z_][\w-]*', name):
                        element.transition_name = name
                    else:
                        print(f"Warning: @transition('{content.strip()}') — tên phải là "
                              f"hằng chuỗi hợp lệ cho class CSS. Bỏ qua.")
                    pos = self._find_close_paren(attrs_str, paren_start) + 1
                    continue

            # Boolean DOM-property directives: @checked(expr), @disabled(expr)...
            # Emit như PROP binding (el.checked = !!expr) — attribute 'checked' chỉ là
            # giá trị khởi tạo của input, set attr không đổi state sau user tương tác.
            m = re.match(r'@(checked|disabled|selected|readonly|required)\s*\(', remaining, re.IGNORECASE)
            if m:
                prop_name = BOOL_PROP_DIRECTIVES[m.group(1).lower()]
                paren_start = pos + m.end() - 1
                content = self._extract_balanced(attrs_str, paren_start)
                if content is not None:
                    expr = content.strip()
                    element.binding_props[prop_name] = {
                        'php': expr,
                        'js': php_to_js_advanced(expr),
                        'state_vars': self._get_state_vars(expr),
                    }
                    pos = self._find_close_paren(attrs_str, paren_start) + 1
                    continue

            # Event directives: @click(...), @change(...), @click.prevent.stop(...)
            m = re.match(r'@(\w+)((?:\.\w+)*)\s*\(', remaining)
            if m:
                directive_name = m.group(1).lower()
                # Modifier chỉ nhận tên hợp lệ; tên lạ bị bỏ qua + cảnh báo thay
                # vì im lặng — gõ sai `.prevet` mà không báo là bug khó thấy.
                raw_mods = [x for x in m.group(2).split('.') if x]
                modifiers = []
                for mod in raw_mods:
                    if mod.lower() in EVENT_MODIFIERS:
                        modifiers.append(mod.lower())
                    else:
                        print(f"Warning: modifier '@{directive_name}.{mod}' không hợp lệ — "
                              f"bỏ qua. Hợp lệ: {', '.join(sorted(EVENT_MODIFIERS))}")

                actual_event = None
                if directive_name in EVENT_NAMES:
                    actual_event = directive_name
                elif directive_name.startswith('on') and directive_name[2:].lower() in EVENT_NAMES:
                    actual_event = directive_name[2:].lower()

                if actual_event is not None:
                    paren_start = pos + m.end() - 1
                    content = self._extract_balanced(attrs_str, paren_start)
                    if content is not None:
                        handler_items = self.event_processor.process_event_items(content)
                        element.events.setdefault(actual_event, []).extend(handler_items)
                        if modifiers:
                            existing = element.event_modifiers.setdefault(actual_event, [])
                            # Cùng event khai báo nhiều lần → hợp modifier, không trùng.
                            existing.extend(m2 for m2 in modifiers if m2 not in existing)
                        pos = self._find_close_paren(attrs_str, paren_start) + 1
                        continue

            # class="..." or class='...'
            m = re.match(r'class\s*=\s*"([^"]*)"', remaining)
            if not m:
                m = re.match(r"class\s*=\s*'([^']*)'", remaining)
            if m:
                class_value = m.group(1)
                if '{{' in class_value or '{!!' in class_value:
                    # class="language-{{ lang }}" — KHÔNG cắt theo khoảng trắng trần,
                    # khoảng trắng bên trong {{ }} thuộc về biểu thức. Token nào có
                    # nội suy thành dynamic class (tên do runtime tính), còn lại static.
                    for token in CLASS_TOKEN_RE.findall(class_value):
                        if '{{' in token or '{!!' in token:
                            js_val, svars = self._convert_attr_echo_value(token)
                            element.dynamic_classes.append({
                                'php': token, 'js': js_val, 'state_vars': svars
                            })
                        else:
                            element.static_classes.append(token)
                else:
                    element.static_classes.extend(class_value.split())
                pos += m.end()
                continue

            # Regular attr="value" or attr='value'
            m = re.match(r'([a-zA-Z_:][\w:.-]*)\s*=\s*"([^"]*)"', remaining)
            if not m:
                m = re.match(r"([a-zA-Z_:][\w:.-]*)\s*=\s*'([^']*)'", remaining)
            if m:
                attr_name = m.group(1)
                attr_value = m.group(2)
                # Shorthand `:attr="expr"` — toàn bộ value là biểu thức JS reactive.
                #   :data-name="user.first + ' ' + user.last"
                #     ≡ data-name="{{ user.first + ' ' + user.last }}"
                # Bỏ qua `::attr` (escape thành attr thường tên `:attr`).
                if attr_name.startswith(':') and not attr_name.startswith('::'):
                    real_name = attr_name[1:]
                    js_expr = php_to_js_advanced(attr_value.strip())
                    svars = self._get_state_vars(attr_value)
                    element.binding_attrs[real_name] = {
                        'php': attr_value, 'js': js_expr, 'state_vars': svars
                    }
                    pos += m.end()
                    continue
                if attr_name.startswith('::'):
                    attr_name = attr_name[1:]  # ::foo → :foo (literal static)
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
                        'yield_name': yield_name,
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
        """Parse @class(...) → populate element static/binding classes.

        Hỗ trợ 3 dạng (.sao):
          @class(expr)                  → 1 class static ('foo' hoặc foo)
          @class(['c' => cond, 'c2'])   → PHP array: '=>' cho điều kiện, bare = static
          @class({"c", "c2": cond})     → JS object: ':' cho điều kiện, bare = static
        Điều kiện nhận cả '=>' lẫn ':' (chỉ ngay sau key → ternary 'a ? b : c' trong
        value KHÔNG bị nhầm). Runtime chỉ hỗ trợ static + conditional (Html.initializeClasses).
        """
        content = content.strip()
        # Bóc wrapper [...] hoặc {...}; không có wrapper → @class(expr) đơn (1 entry)
        if (content.startswith('[') and content.endswith(']')) or \
           (content.startswith('{') and content.endswith('}')):
            inner = content[1:-1].strip()
            entries = self._split_php_array(inner)
        else:
            entries = [content]

        for entry in entries:
            entry = entry.strip()
            if not entry:
                continue
            class_name, cond_php = self._split_class_entry(entry)
            if cond_php is not None:
                cond_js = php_to_js(cond_php)
                svars = self._get_state_vars(cond_php)
                element.binding_classes[class_name] = {
                    'php': cond_php, 'js': cond_js, 'state_vars': svars
                }
            else:
                class_name = entry.strip().strip("'\"")
                if class_name:
                    element.static_classes.append(class_name)

    def _split_class_entry(self, entry):
        """Tách một entry @class thành (class_name, condition).

        Trả (name, cond_php) nếu là điều kiện; (entry, None) nếu static.
        Separator chỉ tính khi nằm NGAY SAU key (chuỗi quote, hoặc identifier với '=>')
        → tránh nhầm ':' của ternary trong value. ':(?!:)' tránh '::'.
        """
        # key có quote: 'c' / "c"  rồi  =>  hoặc  :
        m = re.match(r"""^\s*(['"])(.*?)\1\s*(?:=>|:(?!:))\s*(.+)$""", entry, re.DOTALL)
        if m:
            return m.group(2).strip(), m.group(3).strip()
        # key bare identifier:  my-class  =>|:  cond  (separator NGAY SAU key →
        # ternary 'a ? b : c' không khớp vì sau 'a' là '?', không phải '=>'/':')
        m = re.match(r"""^\s*([A-Za-z_][\w-]*)\s*(?:=>|:(?!:))\s*(.+)$""", entry, re.DOTALL)
        if m:
            return m.group(1).strip(), m.group(2).strip()
        return entry, None

    def _parse_attr_binding(self, content, element):
        """Parse @attr(...) content and populate element attrs.

        Nhận cả `[...]` lẫn `{...}`, cả `=>` lẫn `:` — như _parse_class_binding.
        Không nhận `{}` thì `@attr({id: x})` ở file KHÔNG qua preprocessor (bọc
        <blade>) rơi mất TOÀN BỘ attribute, im lặng, vì không entry nào có '=>'.
        """
        content = content.strip()
        if (content.startswith('[') and content.endswith(']')) or \
           (content.startswith('{') and content.endswith('}')):
            content = content[1:-1]

        entries = self._split_php_array(content)
        for entry in entries:
            entry = entry.strip()
            if not entry:
                continue
            attr_name, val_php = self._split_class_entry(entry)
            if val_php is not None:
                attr_name = attr_name.strip("'\"")
                val_js = php_to_js(val_php)
                svars = self._get_state_vars(val_php)
                element.binding_attrs[attr_name] = {
                    'php': val_php, 'js': val_js, 'state_vars': svars
                }

    def _parse_style_binding(self, content, element):
        """Parse @style(...) → populate element.styles.

        Nhận cả `[...]` lẫn `{...}`, cả `=>` lẫn `:` — dùng chung
        _split_class_entry nên ternary trong value không bị cắt nhầm.

        Trước khi có hàm này, @style KHÔNG có trong dispatch: `@style({'color': c})`
        rơi xuống parser attribute thường và ra `attrs: {style: true, color: true}`
        — hai attribute boolean rác, không binding gì, trong khi Blade vẫn giữ
        @style đúng ⇒ SSR khác CSR mà không báo lỗi.
        """
        content = content.strip()
        if (content.startswith('[') and content.endswith(']')) or \
           (content.startswith('{') and content.endswith('}')):
            content = content[1:-1]

        for entry in self._split_php_array(content):
            entry = entry.strip()
            if not entry:
                continue
            prop, val_php = self._split_class_entry(entry)
            if val_php is None:
                continue
            prop = prop.strip().strip("'\"")
            element.styles[prop] = {
                'php': val_php,
                'js': php_to_js(val_php),
                'state_vars': self._get_state_vars(val_php),
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
        """Get state variable names referenced in an expression.

        Khớp identifier có HOẶC không có '$' prefix: input .blade là PHP ($count),
        input .sao là JS (count). Trước đây regex bắt buộc '$' nên expr .sao (không
        có $) không khớp → mọi output mất stateKeys (không reactive). Giao với
        self.state_variables nên identifier thừa (hàm, 'On'/'Off', keyword) bị lọc.
        """
        if not expr:
            return set()
        found = re.findall(r'\$?([a-zA-Z_]\w*)', expr)
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
        # Giữ nguyên nháy của path. Bóc ra rồi để _convert_path_to_js đoán lại
        # bằng regex là mất thông tin: 'web.components.code-block' không khớp
        # regex định danh nên không được bọc lại, sinh ra phép trừ code - block.
        # _parse_import_include_params cũng không bóc — giữ hai đường giống nhau.
        parts = self._split_php_array(expr)
        if len(parts) >= 2:
            return parts[0].strip(), parts[1].strip()
        return expr.strip(), None

    def _convert_path_to_js(self, path_expr):
        """Convert a path expression to JS, detecting whether it's already JS syntax.
        
        Saola (.sao) files produce paths in JS syntax: __template__ + 'sessions.tasks'
        Legacy PHP files produce paths in PHP syntax: $__template__ . 'sessions.tasks'
        
        If path is already JS (contains + but no $ or PHP . concat), skip php_to_js.
        """
        path_expr = path_expr.strip()
        
        # Already a quoted simple string — keep as-is
        if (path_expr.startswith("'") and path_expr.endswith("'") and path_expr.count("'") == 2) or \
           (path_expr.startswith('"') and path_expr.endswith('"') and path_expr.count('"') == 2):
            return path_expr
        
        # Check if path is already in JS syntax:
        # - Contains + operator (JS concat)
        # - No $ prefix (not PHP variable)
        # - No PHP -> accessor
        is_already_js = ('+' in path_expr and '$' not in path_expr and '->' not in path_expr)
        
        if is_already_js:
            # Path is already JS — use as-is
            path_js = path_expr
        else:
            # Path is PHP syntax — convert via php_to_js
            path_js = php_to_js(path_expr)
        
        # If result is a simple dotted identifier (like sessions.tasks.count), wrap in quotes
        # But NOT if it contains operators (+), quotes, or looks like a system variable (__xxx__)
        if re.match(r'^[a-zA-Z_][\w.]*$', path_js) and '.' in path_js:
            path_js = f"'{path_js}'"
        elif re.match(r'^[a-zA-Z_]\w*$', path_js) and not path_js.startswith('__'):
            # Simple identifier that's not a known system variable — treat as string path
            path_js = f"'{path_js}'"
        
        return path_js

    def _parse_import_include_params(self, expr):
        """Parse @importInclude parameters: tagName, path [, data].
        Returns (path_php, path_js, data_pairs, state_vars) where data_pairs is
        list of (key, value_js)."""
        parts = self._split_php_array(expr)
        
        if len(parts) == 0:
            return expr.strip(), "''", [], set()
        
        # First part is tagName (ignored for JS output)
        if len(parts) == 1:
            path_php = parts[0].strip()
        else:
            path_php = parts[1].strip()
        
        # Convert path
        path_js = self._convert_path_to_js(path_php)
        
        # Parse data pairs if present
        data_pairs = []
        state_vars = set()
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
                        state_vars |= self._get_state_vars(value_php)
        
        return path_php, path_js, data_pairs, state_vars

    def _extract_while_var(self, expr):
        """Extract loop variable from while condition like '$i < 5'."""
        m = re.match(r'\s*\$(\w+)\s*[<>=!]', expr or '')
        return m.group(1) if m else None

    def _extract_while_end(self, expr):
        """Extract end value from while condition like '$i < 5'."""
        m = re.search(r'[<>]=?\s*(\d+)', expr or '')
        return m.group(1) if m else None
