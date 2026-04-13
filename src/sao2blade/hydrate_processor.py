"""
Blade Hydrate Processor - Thêm @hydrate IDs và @startMarker/@endMarker vào blade template

Processor chạy trên template content (phần HTML + directives, sau khi tách declarations).
Duyệt từng dòng, theo dõi:
- HTML tag stack (mở/đóng) → scope cho element IDs
- Directive block stack (@if/@foreach/@while/@for/@switch) → reactive scopes  
- Loop contexts → dynamic ID parts (PHP interpolation)

Đảm bảo IDs khớp với JS output (sao2js) để hydration hoạt động đúng.
"""

import re
import sys
import os

_parent_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _parent_dir not in sys.path:
    sys.path.insert(0, _parent_dir)

from common.hydrate_id import HydrateIdGenerator


class BladeHydrateProcessor:
    """Add @hydrate IDs and @startMarker/@endMarker to blade template content."""
    
    VOID_ELEMENTS = {
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr'
    }
    
    def __init__(self, state_variables=None):
        self.state_variables = state_variables or set()
        self.id_gen = HydrateIdGenerator()
    
    def process(self, template_content, has_extends=False):
        """
        Add hydrate IDs and markers to template content.
        
        Args:
            template_content: Template content (HTML + directives, without declarations)
            has_extends: Whether the view uses @extends
            
        Returns:
            Processed template string
        """
        self.id_gen.reset()
        
        lines = template_content.split('\n')
        output = []
        
        tag_stack = []       # HTML tag nesting: list of tag names
        reactive_stack = []  # [(type, rc_id, state_keys, extra)]
        case_counters = {}   # case counter per rc_id
        # Track loop scopes that are "inside" foreach/while/for
        # so we know when to use dynamic IDs
        loop_scopes = []     # [(rc_id, loop_var_blade, loop_var_js)]
        in_ssr = False       # Track @ssr scope — skip hydrate IDs for HTML inside
        
        for raw_line in lines:
            stripped = raw_line.strip()
            indent = re.match(r'^(\s*)', raw_line).group(1)
            
            if not stripped:
                output.append(raw_line)
                continue
            
            # ─── @ssr / @endssr ───────────────────────────────────
            ssr_start = re.match(r'^\s*@(?:serverside|serverSide|ssr|SSR|useSSR|useSsr)\b', stripped)
            if ssr_start:
                in_ssr = True
                # Don't output @ssr directive — will be stripped
                continue
            
            ssr_end = re.match(r'^\s*@end(?:serverside|serverSide|ServerSide|SSR|Ssr|ssr|useSSR|useSsr)\b', stripped)
            if ssr_end:
                in_ssr = False
                # Don't output @endssr directive — will be stripped
                continue
            
            # If inside @ssr block, output content as-is (no hydrate IDs)
            if in_ssr:
                output.append(raw_line)
                continue
            
            # ─── @block ───────────────────────────────────────────
            block_m = re.match(r"^\s*@block\s*\(\s*['\"](\w+)['\"]\s*\)", raw_line)
            if block_m:
                bname = block_m.group(1)
                self.id_gen.push_block(bname)
                reactive_stack.append(('block', bname, [], {}))
                output.append(raw_line)
                continue
            
            if re.match(r'^\s*@endblock\b', stripped):
                if reactive_stack and reactive_stack[-1][0] == 'block':
                    self.id_gen.pop_scope()
                    reactive_stack.pop()
                output.append(raw_line)
                continue
            
            # ─── @if ──────────────────────────────────────────────
            if_m = re.match(r'^(\s*)@if\s*\(', raw_line)
            if if_m:
                expr = self._extract_parens_from_directive(raw_line, '@if')
                state_keys = self._get_state_keys(expr)
                
                rc_id = self.id_gen.push_reactive('if')
                case_counters[rc_id] = 0
                reactive_stack.append(('if', rc_id, state_keys, {}))
                
                keys_php = self._php_array(state_keys)
                output.append(f"{indent}@startMarker('reactive', '{rc_id}', ['stateKey' => {keys_php}, 'type' => 'if'])")
                
                # Enter case_1 branch
                case_counters[rc_id] += 1
                self.id_gen.push_case(case_counters[rc_id])
                
                output.append(raw_line)
                continue
            
            # ─── @elseif ──────────────────────────────────────────
            if re.match(r'^\s*@elseif\s*\(', stripped):
                if reactive_stack and reactive_stack[-1][0] == 'if':
                    rc_id = reactive_stack[-1][1]
                    self.id_gen.pop_scope()  # pop current case
                    case_counters[rc_id] += 1
                    self.id_gen.push_case(case_counters[rc_id])
                    
                    new_expr = self._extract_parens_from_directive(raw_line, '@elseif')
                    if new_expr:
                        new_keys = self._get_state_keys(new_expr)
                        merged = sorted(set(reactive_stack[-1][2] + new_keys))
                        reactive_stack[-1] = ('if', rc_id, merged, {})
                
                output.append(raw_line)
                continue
            
            # ─── @else ────────────────────────────────────────────
            if re.match(r'^\s*@else\s*$', stripped):
                if reactive_stack and reactive_stack[-1][0] == 'if':
                    rc_id = reactive_stack[-1][1]
                    self.id_gen.pop_scope()  # pop current case
                    case_counters[rc_id] += 1
                    self.id_gen.push_case(case_counters[rc_id])
                output.append(raw_line)
                continue
            
            # ─── @endif ───────────────────────────────────────────
            if re.match(r'^\s*@endif\b', stripped):
                if reactive_stack and reactive_stack[-1][0] == 'if':
                    rc_id = reactive_stack[-1][1]
                    state_keys = reactive_stack[-1][2]
                    self.id_gen.pop_scope()  # pop case
                    self.id_gen.pop_scope()  # pop reactive
                    reactive_stack.pop()
                    del case_counters[rc_id]
                    
                    output.append(f"{indent}@endif")
                    output.append(f"{indent}@endMarker('reactive', '{rc_id}')")
                    continue
                output.append(raw_line)
                continue
            
            # ─── @foreach ─────────────────────────────────────────
            if re.match(r'^\s*@foreach\s*\(', stripped):
                expr = self._extract_parens_from_directive(raw_line, '@foreach')
                state_keys = self._get_state_keys(expr)
                
                rc_id = self.id_gen.push_reactive('foreach')
                reactive_stack.append(('foreach', rc_id, state_keys, {}))
                
                if state_keys:
                    keys_php = self._php_array(state_keys)
                    output.append(f"{indent}@startMarker('reactive', '{rc_id}', ['stateKey' => {keys_php}, 'type' => 'foreach'])")
                
                # Push loop iteration scope 
                loop_scopes.append((rc_id, '$loop->index', '__loopIndex'))
                
                output.append(raw_line)
                continue
            
            if re.match(r'^\s*@endforeach\b', stripped):
                if reactive_stack and reactive_stack[-1][0] == 'foreach':
                    rc_id = reactive_stack[-1][1]
                    state_keys = reactive_stack[-1][2]
                    self.id_gen.pop_scope()
                    reactive_stack.pop()
                    if loop_scopes and loop_scopes[-1][0] == rc_id:
                        loop_scopes.pop()
                    
                    output.append(f"{indent}@endforeach")
                    if state_keys:
                        output.append(f"{indent}@endMarker('reactive', '{rc_id}')")
                    continue
                output.append(raw_line)
                continue
            
            # ─── @while ───────────────────────────────────────────
            if re.match(r'^\s*@while\s*\(', stripped):
                expr = self._extract_parens_from_directive(raw_line, '@while')
                
                loop_var = self._extract_while_var(expr)
                end_val = self._extract_while_end(expr)
                
                rc_id = self.id_gen.push_reactive('while')
                reactive_stack.append(('while', rc_id, [], {'loop_var': loop_var, 'end_val': end_val}))
                
                opts = []
                if loop_var:
                    opts.append(f"'start' => {loop_var}")
                if end_val:
                    opts.append(f"'end' => {end_val}")
                opts_str = ', [' + ', '.join(opts) + ']' if opts else ''
                output.append(f"{indent}@startMarker('while', '{rc_id}'{opts_str})")
                
                # Push loop scope
                loop_var_clean = loop_var if loop_var else '$i'
                loop_scopes.append((rc_id, loop_var_clean, loop_var_clean.lstrip('$') if loop_var_clean else 'i'))
                
                output.append(raw_line)
                continue
            
            if re.match(r'^\s*@endwhile\b', stripped):
                if reactive_stack and reactive_stack[-1][0] == 'while':
                    rc_id = reactive_stack[-1][1]
                    self.id_gen.pop_scope()
                    reactive_stack.pop()
                    if loop_scopes and loop_scopes[-1][0] == rc_id:
                        loop_scopes.pop()
                    
                    output.append(f"{indent}@endwhile")
                    output.append(f"{indent}@endMarker('while', '{rc_id}')")
                    continue
                output.append(raw_line)
                continue
            
            # ─── @for ─────────────────────────────────────────────
            if re.match(r'^\s*@for\s*\(', stripped):
                expr = self._extract_parens_from_directive(raw_line, '@for')
                state_keys = self._get_state_keys(expr)
                
                loop_var = self._extract_for_var(expr)
                
                rc_id = self.id_gen.push_reactive('for')
                reactive_stack.append(('for', rc_id, state_keys, {'loop_var': loop_var}))
                
                if state_keys:
                    keys_php = self._php_array(state_keys)
                    output.append(f"{indent}@startMarker('reactive', '{rc_id}', ['stateKey' => {keys_php}, 'type' => 'for'])")
                
                loop_var_clean = loop_var if loop_var else '$i'
                loop_scopes.append((rc_id, loop_var_clean, loop_var_clean.lstrip('$') if loop_var_clean else 'i'))
                
                output.append(raw_line)
                continue
            
            if re.match(r'^\s*@endfor\b', stripped):
                if reactive_stack and reactive_stack[-1][0] == 'for':
                    rc_id = reactive_stack[-1][1]
                    state_keys = reactive_stack[-1][2]
                    self.id_gen.pop_scope()
                    reactive_stack.pop()
                    if loop_scopes and loop_scopes[-1][0] == rc_id:
                        loop_scopes.pop()
                    
                    output.append(f"{indent}@endfor")
                    if state_keys:
                        output.append(f"{indent}@endMarker('reactive', '{rc_id}')")
                    continue
                output.append(raw_line)
                continue
            
            # ─── @switch ──────────────────────────────────────────
            if re.match(r'^\s*@switch\s*\(', stripped):
                expr = self._extract_parens_from_directive(raw_line, '@switch')
                state_keys = self._get_state_keys(expr)
                
                rc_id = self.id_gen.push_reactive('switch')
                case_counters[rc_id] = 0
                reactive_stack.append(('switch', rc_id, state_keys, {}))
                
                keys_php = self._php_array(state_keys)
                output.append(f"{indent}@startMarker('reactive', '{rc_id}', ['stateKey' => {keys_php}, 'type' => 'switch'])")
                
                output.append(raw_line)
                continue
            
            if re.match(r'^\s*@case\s*\(', stripped) or re.match(r'^\s*@default\s*$', stripped):
                if reactive_stack and reactive_stack[-1][0] == 'switch':
                    rc_id = reactive_stack[-1][1]
                    if case_counters.get(rc_id, 0) > 0:
                        self.id_gen.pop_scope()
                    case_counters[rc_id] += 1
                    self.id_gen.push_case(case_counters[rc_id])
                output.append(raw_line)
                continue
            
            if re.match(r'^\s*@endswitch\b', stripped):
                if reactive_stack and reactive_stack[-1][0] == 'switch':
                    rc_id = reactive_stack[-1][1]
                    state_keys = reactive_stack[-1][2]
                    if case_counters.get(rc_id, 0) > 0:
                        self.id_gen.pop_scope()
                    self.id_gen.pop_scope()
                    reactive_stack.pop()
                    del case_counters[rc_id]
                    
                    output.append(f"{indent}@endswitch")
                    output.append(f"{indent}@endMarker('reactive', '{rc_id}')")
                    continue
                output.append(raw_line)
                continue
            
            # ─── @include → wrap with @startMarker/@endMarker ─────
            include_m = re.match(r'^(\s*)@include\s*\(', raw_line)
            if include_m:
                comp_id = self.id_gen.next_component()
                id_val = self._blade_id_value(comp_id, loop_scopes)
                output.append(f"{indent}@startMarker('component', {id_val})")
                output.append(raw_line)
                output.append(f"{indent}@endMarker('component', {id_val})")
                continue
            
            # ─── @yield → wrap with @startMarker/@endMarker ──────
            yield_m = re.match(r'^(\s*)@yield\s*\(', raw_line)
            if yield_m:
                yield_id = self.id_gen.next_yield()
                id_val = self._blade_id_value(yield_id, loop_scopes)
                output.append(f"{indent}@startMarker('yield', {id_val})")
                output.append(raw_line)
                output.append(f"{indent}@endMarker('yield', {id_val})")
                continue
            
            # ─── Process HTML and outputs ─────────────────────────
            processed = self._process_html_and_outputs(raw_line, tag_stack, loop_scopes)
            output.append(processed)
        
        return '\n'.join(output)
    
    # ──────────────────────────────────────────────────────────────────
    # HTML tag and output processing
    # ──────────────────────────────────────────────────────────────────
    
    def _process_html_and_outputs(self, line, tag_stack, loop_scopes):
        """Process HTML tags and echo expressions in a line."""
        parts = []
        pos = 0
        length = len(line)
        
        while pos < length:
            # Closing tag </tag>
            close_m = re.match(r'</\s*([a-zA-Z][\w-]*)\s*>', line[pos:])
            if close_m:
                tag_name = close_m.group(1).lower()
                parts.append(close_m.group(0))
                pos += close_m.end()
                if tag_stack and tag_stack[-1] == tag_name:
                    tag_stack.pop()
                    self.id_gen.pop_scope()
                continue
            
            # Opening tag <tag ...> or <tag .../>
            open_m = re.match(
                r'<([a-zA-Z][\w-]*)((?:\s+(?:[^>\'"]|\'[^\']*\'|"[^"]*")*?)?)\s*(/?)>',
                line[pos:], re.DOTALL
            )
            if open_m:
                tag_name = open_m.group(1).lower()
                attrs_str = open_m.group(2) or ''
                slash = open_m.group(3)
                is_void = tag_name in self.VOID_ELEMENTS or bool(slash)
                
                if is_void:
                    eid = self.id_gen.next_element(tag_name)
                else:
                    eid = self.id_gen.push_element(tag_name)
                    tag_stack.append(tag_name)
                
                hydrate = self._blade_hydrate_attr(eid, loop_scopes)
                
                # Parse attrs into @class, @attr, and directive parts
                classes, regular_attrs, directive_parts = self._parse_html_attrs(attrs_str)
                new_attrs = self._build_blade_attrs(hydrate, classes, regular_attrs, directive_parts)
                new_tag = f"<{tag_name} {new_attrs}{' /' if slash else ''}>"
                
                parts.append(new_tag)
                pos += open_m.end()
                continue
            
            # Raw echo {!! ... !!}
            raw_m = re.match(r'\{!!\s*(.*?)\s*!!\}', line[pos:], re.DOTALL)
            if raw_m:
                expr = raw_m.group(1)
                full = raw_m.group(0)
                skeys = self._get_state_keys(expr)
                if skeys:
                    oid = self.id_gen.next_output()
                    id_val = self._blade_id_value(oid, loop_scopes)
                    parts.append(f"@startMarker('output', {id_val}){full}@endMarker('output', {id_val})")
                else:
                    parts.append(full)
                pos += raw_m.end()
                continue
            
            # Echo {{ ... }} (skip comments {{-- --}})
            if line[pos:pos+3] == '{{-':
                comment_m = re.match(r'\{\{--.*?--\}\}', line[pos:], re.DOTALL)
                if comment_m:
                    parts.append(comment_m.group(0))
                    pos += comment_m.end()
                    continue
            
            echo_m = re.match(r'\{\{(?!--)\s*(.*?)\s*\}\}', line[pos:], re.DOTALL)
            if echo_m:
                expr = echo_m.group(1)
                full = echo_m.group(0)
                skeys = self._get_state_keys(expr)
                if skeys:
                    oid = self.id_gen.next_output()
                    id_val = self._blade_id_value(oid, loop_scopes)
                    parts.append(f"@startMarker('output', {id_val}){full}@endMarker('output', {id_val})")
                else:
                    parts.append(full)
                pos += echo_m.end()
                continue
            
            # Regular char
            parts.append(line[pos])
            pos += 1
        
        return ''.join(parts)
    
    # ──────────────────────────────────────────────────────────────────
    # Attribute parsing / transformation helpers
    # ──────────────────────────────────────────────────────────────────
    
    def _parse_html_attrs(self, attrs_str):
        """Parse HTML attribute string into (classes, regular_attrs, directive_parts).
        
        - classes: list of static class names
        - regular_attrs: list of (name, raw_value, is_binding) – raw_value is None for booleans
        - directive_parts: list of raw directive strings (e.g. '@click(decrement())')
        """
        classes = []
        regular_attrs = []   # [(name, raw_value|None, is_binding)]
        directive_parts = []
        
        text = attrs_str
        pos = 0
        length = len(text)
        
        while pos < length:
            # Skip whitespace
            while pos < length and text[pos].isspace():
                pos += 1
            if pos >= length:
                break
            
            remaining = text[pos:]
            
            # Blade directive: @something(...)
            dir_m = re.match(r'@(\w+)\s*\(', remaining)
            if dir_m:
                paren_start = pos + dir_m.end() - 1
                content = self._extract_parens(text, paren_start)
                if content is not None:
                    full_len = paren_start - pos + len(content) + 2  # +2 for ()
                    directive_parts.append(text[pos:pos + full_len])
                    pos += full_len
                else:
                    pos += dir_m.end()
                continue
            
            # Attribute: name="value" or name='value'
            attr_m = re.match(r'([a-zA-Z_:][\w:.-]*)\s*=\s*"([^"]*)"', remaining)
            if not attr_m:
                attr_m = re.match(r"([a-zA-Z_:][\w:.-]*)\s*=\s*'([^']*)'", remaining)
            if attr_m:
                name = attr_m.group(1)
                value = attr_m.group(2)
                if name == 'class':
                    classes.extend(value.split())
                else:
                    is_binding = '{{' in value or '{!!' in value
                    regular_attrs.append((name, value, is_binding))
                pos += attr_m.end()
                continue
            
            # Boolean attribute (no value)
            bool_m = re.match(r'([a-zA-Z_:][\w:.-]*)', remaining)
            if bool_m:
                name = bool_m.group(1)
                if not name.startswith('@'):
                    regular_attrs.append((name, None, False))
                pos += bool_m.end()
                continue
            
            pos += 1
        
        return classes, regular_attrs, directive_parts
    
    def _build_blade_attrs(self, hydrate_attr, classes, regular_attrs, directive_parts):
        """Build final blade attribute string with @hydrate, @class, @attr, and directives."""
        parts = [hydrate_attr]
        
        # @class directive
        if classes:
            items = ', '.join(f"'{c}'" for c in classes)
            parts.append(f"@class([{items}])")
        
        # @attr directive
        if regular_attrs:
            attr_items = []
            for name, value, is_binding in regular_attrs:
                if value is None:
                    # Boolean attribute
                    attr_items.append(f"'{name}' => true")
                elif is_binding:
                    # Extract PHP expression from {{ $var }}
                    php_val = re.sub(r'\{\{\s*(.*?)\s*\}\}', r'\1', value)
                    php_val = re.sub(r'\{!!\s*(.*?)\s*!!\}', r'\1', php_val)
                    attr_items.append(f"'{name}' => {php_val}")
                else:
                    attr_items.append(f"'{name}' => '{value}'")
            parts.append(f"@attr([{', '.join(attr_items)}])")
        
        # Event / other directives
        parts.extend(directive_parts)
        
        return ' '.join(parts)
    
    # ──────────────────────────────────────────────────────────────────
    # ID formatting helpers
    # ──────────────────────────────────────────────────────────────────
    
    def _blade_hydrate_attr(self, element_id, loop_scopes):
        """Format @hydrate('id') or @hydrate("id-{$var}") attribute."""
        if loop_scopes:
            dynamic_id = self._inject_loop_vars(element_id, loop_scopes)
            return f'@hydrate("{dynamic_id}")'
        return f"@hydrate('{element_id}')"
    
    def _blade_id_value(self, id_str, loop_scopes):
        """Format ID value for @startMarker: 'id' or "id-{$var}"."""
        if loop_scopes:
            dynamic_id = self._inject_loop_vars(id_str, loop_scopes)
            return f'"{dynamic_id}"'
        return f"'{id_str}'"
    
    def _inject_loop_vars(self, id_str, loop_scopes):
        """Inject PHP variable interpolation into ID string for loop contexts.
        
        For each active loop scope, we inject {$loop->index} or {$i} after 
        the reactive prefix that corresponds to that loop.
        
        Example:
            id_str = "block-content-div-1-ul-5-rc-foreach-1-li-1"
            loop_scopes = [('rc-foreach-1_id', '$loop->index', '__loopIndex')]
            → "block-content-div-1-ul-5-rc-foreach-1-{$loop->index}-li-1"
        """
        result = id_str
        
        for rc_id, blade_var, js_var in loop_scopes:
            # Find the reactive prefix in the ID and inject loop var after it
            # The rc_id from push_reactive has format like "block-content-div-1-ul-5-rc-foreach-1"
            # We need to find this prefix in the element ID
            # The element IDs within the loop will start with this prefix
            
            # The prefix is stored in id_gen scopes, let's find it by looking at
            # where rc_id's prefix appears in the result
            if rc_id in result and result != rc_id:
                idx = result.index(rc_id) + len(rc_id)
                if idx < len(result) and result[idx] == '-':
                    # Insert loop var interpolation
                    result = result[:idx] + f"-{{{blade_var}}}" + result[idx:]
        
        return result
    
    # ──────────────────────────────────────────────────────────────────
    # Utility
    # ──────────────────────────────────────────────────────────────────
    
    def _get_state_keys(self, expr):
        """Get state variable names from expression."""
        if not expr:
            return []
        found = re.findall(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', expr)
        return sorted(set(v for v in found if v in self.state_variables))
    
    def _php_array(self, items):
        """Format as PHP array: ['a', 'b']"""
        return '[' + ', '.join(f"'{x}'" for x in items) + ']'
    
    def _extract_parens_from_directive(self, line, directive):
        """Extract content from directive's parentheses."""
        pattern = re.escape(directive) + r'\s*\('
        match = re.search(pattern, line)
        if not match:
            return None
        paren_start = match.end() - 1
        return self._extract_parens(line, paren_start)
    
    def _extract_parens(self, text, start):
        """Extract balanced parentheses content."""
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
    
    def _extract_while_var(self, expr):
        m = re.match(r'\s*\$(\w+)\s*[<>=!]', expr or '')
        return f"${m.group(1)}" if m else None
    
    def _extract_while_end(self, expr):
        m = re.search(r'[<>]=?\s*(\d+)', expr or '')
        return m.group(1) if m else None
    
    def _extract_for_var(self, expr):
        m = re.match(r'\s*\$(\w+)\s*=', expr or '')
        return f"${m.group(1)}" if m else None
