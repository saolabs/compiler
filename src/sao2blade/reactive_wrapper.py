"""
Reactive Wrapper - Wraps reactive directives with @startReactive/@endReactive
Xử lý template blade để thêm reactive markers cho server-side rendering
"""

import re


class ReactiveWrapper:
    """
    Wraps blade directives that reference state variables with
    @startReactive/@endReactive directives for reactive SSR support.
    
    Input:
        @if($products)
            ...
        @else
            ...
        @endif
    
    Output (when $products is a state variable):
        @startReactive('if', 'rc-' . $__VIEW_ID__ . '-if-1', ['products']) @if($products)
            ...
        @else
            ...
        @endif @endReactive('if', 'rc-' . $__VIEW_ID__ . '-if-1')
    
    Echo output wrapping (per-expression inline):
        <h2>Products @startReactive('output', ..., ['products'], ["type" => 'output', "escapeHTML" => true]) {{ count($products) }} @endReactive('output', ...)</h2>
    """
    
    def __init__(self, state_variables=None):
        self.state_variables = state_variables or set()
        self._reactive_counter = 0
        self._scope_stack = []  # Stack for nested reactive scopes
        self._scope_counters = {}  # Counters per scope level
    
    def _generate_reactive_id(self, directive_type):
        """Generate unique reactive ID, supporting nested scopes.
        Includes $__VIEW_ID__ for view-scoped uniqueness (synced with JS output).
        Returns PHP expression string for use inside directive parameters.
        """
        self._reactive_counter += 1
        
        # Build hierarchical ID from scope stack
        parts = []
        for scope in self._scope_stack:
            parts.append(scope)
        parts.append(f"{directive_type}-{self._reactive_counter}")
        
        suffix = '-'.join(parts)
        # PHP string concatenation: 'rc-' . $__VIEW_ID__ . '-foreach-1'
        return f"'rc-' . $__VIEW_ID__ . '-{suffix}'"
    
    def _push_scope(self, scope_id):
        """Push a new reactive scope for nested directives"""
        self._scope_stack.append(scope_id)
    
    def _pop_scope(self):
        """Pop the current reactive scope"""
        if self._scope_stack:
            self._scope_stack.pop()
    
    def _extract_variables_from_expr(self, expr):
        """
        Extract PHP variable names from an expression.
        Returns variable names without the $ prefix.
        
        Example: '$products && count($items) > 0' → {'products', 'items'}
        """
        variables = set()
        # Match $varName patterns (PHP variables)
        php_vars = re.findall(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', expr)
        variables.update(php_vars)
        return variables
    
    def _get_state_keys_from_expr(self, expr):
        """
        Find which state variables are referenced in the expression.
        Returns list of state variable names used (without $ prefix).
        """
        expr_vars = self._extract_variables_from_expr(expr)
        # Intersect with known state variables
        state_keys = []
        for var in expr_vars:
            if var in self.state_variables:
                state_keys.append(var)
        return sorted(state_keys)  # Sort for deterministic output
    
    def _extract_balanced_parentheses(self, text, start_pos):
        """Extract content within balanced parentheses starting at start_pos"""
        if start_pos >= len(text) or text[start_pos] != '(':
            return None, start_pos
        
        depth = 0
        i = start_pos
        while i < len(text):
            if text[i] == '(':
                depth += 1
            elif text[i] == ')':
                depth -= 1
                if depth == 0:
                    return text[start_pos + 1:i], i + 1
            i += 1
        return None, start_pos
    
    def _format_state_keys_php(self, state_keys):
        """Format state keys as PHP array string: ['key1', 'key2']"""
        if not state_keys:
            return '[]'
        quoted = [f"'{k}'" for k in state_keys]
        return '[' + ', '.join(quoted) + ']'
    
    def _get_covered_state_keys(self, directive_stack):
        """Get all state keys currently covered by reactive blocks in the stack.
        Used to determine if echo expressions need their own reactive wrapper."""
        covered = set()
        for entry in directive_stack:
            if entry[3]:  # is_reactive
                covered.update(entry[2])
        return covered
    
    def _wrap_echo_inline(self, line, directive_stack):
        """Wrap individual {{ expr }} and {!! expr !!} expressions inline with
        @startReactive/@endReactive, only for expressions referencing state
        variables not already covered by a parent reactive block.
        Adds option ["type" => 'output', "escapeHTML" => true/false].
        Returns the modified line and whether any wrapping occurred."""
        covered = self._get_covered_state_keys(directive_stack)
        changed = False
        
        def replace_raw_echo(match):
            """Handle {!! expr !!} - unescaped output"""
            nonlocal changed
            full = match.group(0)
            expr = match.group(1)
            if not expr:
                return full
            echo_keys = set(self._get_state_keys_from_expr(expr))
            uncovered = echo_keys - covered
            if uncovered:
                changed = True
                rc_id = self._generate_reactive_id('output')
                keys_php = self._format_state_keys_php(sorted(uncovered))
                return (f"@startReactive('output', {rc_id}, {keys_php}, "
                        f"[\"type\" => 'output', \"escapeHTML\" => false]) "
                        f"{full} "
                        f"@endReactive('output', {rc_id})")
            return full
        
        def replace_escaped_echo(match):
            """Handle {{ expr }} - escaped output (skip comments {{-- --}})"""
            nonlocal changed
            full = match.group(0)
            expr = match.group(1)
            if not expr:
                return full
            echo_keys = set(self._get_state_keys_from_expr(expr))
            uncovered = echo_keys - covered
            if uncovered:
                changed = True
                rc_id = self._generate_reactive_id('output')
                keys_php = self._format_state_keys_php(sorted(uncovered))
                return (f"@startReactive('output', {rc_id}, {keys_php}, "
                        f"[\"type\" => 'output', \"escapeHTML\" => true]) "
                        f"{full} "
                        f"@endReactive('output', {rc_id})")
            return full
        
        # Replace {!! expr !!} first (before {{ }} to avoid conflicts)
        line = re.sub(r'\{!!\s*(.*?)\s*!!\}', replace_raw_echo, line)
        # Replace {{ expr }} but NOT {{-- comments --}}
        line = re.sub(r'\{\{(?!--)\s*(.*?)\s*\}\}', replace_escaped_echo, line)
        
        return line, changed
    
    def _is_inside_html_tag(self, line, pos=0):
        """Check if a directive at the given position is inside an HTML tag's attributes.
        Returns True if there's an unclosed '<' before pos with no matching '>' before it.
        E.g. <div @if($x) class="a" @endif> → True
        But:  @if($x) <div> @endif → False"""
        before = line[:pos]
        last_open = before.rfind('<')
        last_close = before.rfind('>')
        return last_open > last_close and last_open != -1
    
    def _convert_echo_attrs_to_attr(self, line):
        """Convert HTML attributes with {{ $stateVar }} values to @attr directive.
        Only converts attributes whose echo expressions reference state variables.
        
        Example:
          <tag name="{{ $stateKey1 }}" data-value="{{ $stateKey2 }}" class="fixed">
          → <tag @attr(['name' => $stateKey1, 'data-value' => $stateKey2]) class="fixed">
        
        Mixed content like name="prefix-{{ $var }}-suffix" becomes:
          'name' => 'prefix-' . $var . '-suffix'
        
        Returns (modified_line, changed).
        """
        # Pattern to match HTML attributes with {{ }} in their values
        # attr="...{{ expr }}..."  or  attr='...{{ expr }}...'
        attr_pattern = re.compile(
            r'''([\w\-:@]+)\s*=\s*("(?:[^"]*\{\{(?!--).*?\}\}[^"]*)"| '(?:[^']*\{\{(?!--).*?\}\}[^']*)')'''
        )
        
        matches = list(attr_pattern.finditer(line))
        if not matches:
            return line, False
        
        # Filter: only attributes whose {{ }} contain state variables
        attrs_to_convert = []
        for match in matches:
            attr_name = match.group(1)
            attr_value_quoted = match.group(2)  # includes quotes
            
            # Skip if this attribute is NOT inside an HTML tag
            if not self._is_inside_html_tag(line, match.start()):
                continue
            
            # Extract the quote char and inner value
            quote_char = attr_value_quoted[0]
            inner_value = attr_value_quoted[1:-1]
            
            # Find all {{ expr }} in this attribute value
            echo_matches = list(re.finditer(r'\{\{(?!--)\s*(.*?)\s*\}\}', inner_value))
            if not echo_matches:
                continue
            
            # Check if any echo expression references a state variable
            has_state_ref = False
            for em in echo_matches:
                keys = self._get_state_keys_from_expr(em.group(1))
                if keys:
                    has_state_ref = True
                    break
            
            if has_state_ref:
                # Build PHP expression for the attribute value
                php_expr = self._build_php_attr_expr(inner_value)
                attrs_to_convert.append((match, attr_name, php_expr))
        
        if not attrs_to_convert:
            return line, False
        
        # Build @attr entries
        attr_entries = []
        for _, attr_name, php_expr in attrs_to_convert:
            attr_entries.append(f"'{attr_name}' => {php_expr}")
        
        attr_directive = "@attr([" + ", ".join(attr_entries) + "])"
        
        # Remove converted attributes from line (process from right to left)
        new_line = line
        for match, _, _ in reversed(attrs_to_convert):
            start = match.start()
            end = match.end()
            # Remove trailing space if present
            if end < len(new_line) and new_line[end] == ' ':
                end += 1
            new_line = new_line[:start] + new_line[end:]
        
        # Insert @attr directive before the closing > of the tag
        # Find the position to insert: before > or at end of tag attributes
        # Look for the first > after the tag opening
        tag_start = new_line.rfind('<', 0, attrs_to_convert[0][0].start())
        if tag_start == -1:
            tag_start = 0
        
        # Find closing > after our edits
        close_pos = new_line.find('>', tag_start)
        if close_pos != -1:
            # Check for self-closing />
            if close_pos > 0 and new_line[close_pos - 1] == '/':
                new_line = new_line[:close_pos - 1] + attr_directive + ' ' + new_line[close_pos - 1:]
            else:
                new_line = new_line[:close_pos] + ' ' + attr_directive + new_line[close_pos:]
        else:
            # Multi-line tag, append at end of line
            new_line = new_line.rstrip() + ' ' + attr_directive
        
        return new_line, True
    
    def _build_php_attr_expr(self, inner_value):
        """Build a PHP expression from an attribute value containing {{ expr }}.
        
        Examples:
          '{{ $name }}'           → $name
          'prefix-{{ $id }}'      → 'prefix-' . $id
          '{{ $a }}-{{ $b }}'     → $a . '-' . $b
          'hello'                 → 'hello'  (no echo, shouldn't happen)
        """
        parts = []
        last_end = 0
        
        for m in re.finditer(r'\{\{(?!--)\s*(.*?)\s*\}\}', inner_value):
            # Static text before echo
            if m.start() > last_end:
                static_text = inner_value[last_end:m.start()]
                if static_text:
                    parts.append(f"'{static_text}'")
            # PHP expression from echo
            parts.append(m.group(1).strip())
            last_end = m.end()
        
        # Trailing static text
        if last_end < len(inner_value):
            static_text = inner_value[last_end:]
            if static_text:
                parts.append(f"'{static_text}'")
        
        if len(parts) == 1:
            return parts[0]
        return ' . '.join(parts)
    
    def wrap_template(self, template_content):
        """
        Process template content and wrap reactive directives with
        @startReactive/@endReactive markers.
        
        Returns the processed template string.
        """
        lines = template_content.split('\n')
        output_lines = []
        
        # Stack to track directive blocks: (type, reactive_id, state_keys, is_reactive)
        directive_stack = []
        
        # Track if we're inside an open HTML tag (multi-line tag attributes)
        inside_html_tag = False
        
        for line in lines:
            stripped = line.strip()
            
            # Check for various directives
            processed = False
            
            # Determine if directives on this line are inside HTML tag attributes.
            # Single-line: <tag @if(...) ... @endif> - detected by _is_inside_html_tag
            # Multi-line: previous line had unclosed <tag ...\n  @if(...)
            # For single-line check, find where @directive starts
            first_at = line.find('@') if '@' in stripped else -1
            in_tag = inside_html_tag
            if not in_tag and first_at >= 0:
                in_tag = self._is_inside_html_tag(line, first_at)
            
            # Update inside_html_tag state for NEXT line based on this line
            # We check if this line has an unclosed HTML tag
            # (last < is after last >) - but only count < that look like HTML tags
            tag_depth = 0
            for ch in stripped:
                if ch == '<':
                    tag_depth += 1
                elif ch == '>':
                    tag_depth -= 1
            inside_html_tag = tag_depth > 0
            
            # @if directive
            if_match = re.match(r'^(\s*)@if\s*\(', line)
            if if_match:
                indent = if_match.group(1)
                paren_start = line.index('(', line.index('@if'))
                expr, end_pos = self._extract_balanced_parentheses(line, paren_start)
                if expr is not None:
                    state_keys = self._get_state_keys_from_expr(expr)
                    if state_keys and not in_tag:
                        rc_id = self._generate_reactive_id('if')
                        keys_php = self._format_state_keys_php(state_keys)
                        output_lines.append(
                            f"{indent}@startReactive('if', {rc_id}, {keys_php}) {line.strip()}"
                        )
                        directive_stack.append(('if', rc_id, state_keys, True))
                        processed = True
                    else:
                        directive_stack.append(('if', None, [], False))
                else:
                    directive_stack.append(('if', None, [], False))
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @elseif directive - update state_keys of parent if block
            elseif_match = re.match(r'^(\s*)@elseif\s*\(', line)
            if elseif_match:
                paren_start = line.index('(', line.index('@elseif'))
                expr, end_pos = self._extract_balanced_parentheses(line, paren_start)
                if expr is not None and directive_stack and directive_stack[-1][0] == 'if':
                    new_keys = self._get_state_keys_from_expr(expr)
                    if new_keys:
                        existing = directive_stack[-1]
                        merged_keys = list(set(existing[2] + new_keys))
                        merged_keys.sort()
                        # If this if wasn't reactive before but now has state keys,
                        # we can't retroactively wrap it. Just mark for future reference.
                        if not existing[3] and merged_keys:
                            # Update the stack entry with new keys
                            directive_stack[-1] = ('if', existing[1], merged_keys, existing[3])
                        elif existing[3]:
                            directive_stack[-1] = ('if', existing[1], merged_keys, existing[3])
                output_lines.append(line)
                continue
            
            # @else directive - just pass through
            if re.match(r'^(\s*)@else\s*$', stripped):
                output_lines.append(line)
                continue
            
            # @endif directive
            if re.match(r'^(\s*)@endif\b', stripped):
                indent = re.match(r'^(\s*)', line).group(1)
                if directive_stack and directive_stack[-1][0] == 'if':
                    block_info = directive_stack.pop()
                    if block_info[3]:  # is_reactive
                        rc_id = block_info[1]
                        output_lines.append(
                            f"{indent}@endif @endReactive('if', {rc_id})"
                        )
                        processed = True
                
                if not processed:
                    output_lines.append(line)
                continue

            # @isset directive (block conditional, like @if)
            isset_match = re.match(r'^(\s*)@isset\s*\(', line)
            if isset_match:
                indent = isset_match.group(1)
                paren_start = line.index('(', line.index('@isset'))
                expr, end_pos = self._extract_balanced_parentheses(line, paren_start)
                if expr is not None:
                    state_keys = self._get_state_keys_from_expr(expr)
                    if state_keys and not in_tag:
                        rc_id = self._generate_reactive_id('isset')
                        keys_php = self._format_state_keys_php(state_keys)
                        output_lines.append(
                            f"{indent}@startReactive('isset', {rc_id}, {keys_php}) {line.strip()}"
                        )
                        directive_stack.append(('isset', rc_id, state_keys, True))
                        processed = True
                    else:
                        directive_stack.append(('isset', None, [], False))
                else:
                    directive_stack.append(('isset', None, [], False))
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @endisset directive
            if re.match(r'^(\s*)@endisset\b', stripped):
                indent = re.match(r'^(\s*)', line).group(1)
                if directive_stack and directive_stack[-1][0] == 'isset':
                    block_info = directive_stack.pop()
                    if block_info[3]:
                        rc_id = block_info[1]
                        output_lines.append(
                            f"{indent}@endisset @endReactive('isset', {rc_id})"
                        )
                        processed = True
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @empty directive (block conditional, like @if)
            empty_match = re.match(r'^(\s*)@empty\s*\(', line)
            if empty_match:
                indent = empty_match.group(1)
                paren_start = line.index('(', line.index('@empty'))
                expr, end_pos = self._extract_balanced_parentheses(line, paren_start)
                if expr is not None:
                    state_keys = self._get_state_keys_from_expr(expr)
                    if state_keys and not in_tag:
                        rc_id = self._generate_reactive_id('empty')
                        keys_php = self._format_state_keys_php(state_keys)
                        output_lines.append(
                            f"{indent}@startReactive('empty', {rc_id}, {keys_php}) {line.strip()}"
                        )
                        directive_stack.append(('empty', rc_id, state_keys, True))
                        processed = True
                    else:
                        directive_stack.append(('empty', None, [], False))
                else:
                    directive_stack.append(('empty', None, [], False))
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @endempty directive
            if re.match(r'^(\s*)@endempty\b', stripped):
                indent = re.match(r'^(\s*)', line).group(1)
                if directive_stack and directive_stack[-1][0] == 'empty':
                    block_info = directive_stack.pop()
                    if block_info[3]:
                        rc_id = block_info[1]
                        output_lines.append(
                            f"{indent}@endempty @endReactive('empty', {rc_id})"
                        )
                        processed = True
                
                if not processed:
                    output_lines.append(line)
                continue

            # @foreach directive
            foreach_match = re.match(r'^(\s*)@foreach\s*\(', line)
            if foreach_match:
                indent = foreach_match.group(1)
                paren_start = line.index('(', line.index('@foreach'))
                expr, end_pos = self._extract_balanced_parentheses(line, paren_start)
                if expr is not None:
                    state_keys = self._get_state_keys_from_expr(expr)
                    if state_keys and not in_tag:
                        rc_id = self._generate_reactive_id('foreach')
                        keys_php = self._format_state_keys_php(state_keys)
                        output_lines.append(
                            f"{indent}@startReactive('foreach', {rc_id}, {keys_php}) {line.strip()}"
                        )
                        directive_stack.append(('foreach', rc_id, state_keys, True))
                        processed = True
                    else:
                        directive_stack.append(('foreach', None, [], False))
                else:
                    directive_stack.append(('foreach', None, [], False))
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @endforeach directive
            if re.match(r'^(\s*)@endforeach\b', stripped):
                indent = re.match(r'^(\s*)', line).group(1)
                if directive_stack and directive_stack[-1][0] == 'foreach':
                    block_info = directive_stack.pop()
                    if block_info[3]:  # is_reactive
                        rc_id = block_info[1]
                        output_lines.append(
                            f"{indent}@endforeach @endReactive('foreach', {rc_id})"
                        )
                        processed = True
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @for directive
            for_match = re.match(r'^(\s*)@for\s*\(', line)
            if for_match:
                indent = for_match.group(1)
                paren_start = line.index('(', line.index('@for'))
                expr, end_pos = self._extract_balanced_parentheses(line, paren_start)
                if expr is not None:
                    state_keys = self._get_state_keys_from_expr(expr)
                    if state_keys and not in_tag:
                        rc_id = self._generate_reactive_id('for')
                        keys_php = self._format_state_keys_php(state_keys)
                        output_lines.append(
                            f"{indent}@startReactive('for', {rc_id}, {keys_php}) {line.strip()}"
                        )
                        directive_stack.append(('for', rc_id, state_keys, True))
                        processed = True
                    else:
                        directive_stack.append(('for', None, [], False))
                else:
                    directive_stack.append(('for', None, [], False))
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @endfor directive
            if re.match(r'^(\s*)@endfor\b', stripped):
                indent = re.match(r'^(\s*)', line).group(1)
                if directive_stack and directive_stack[-1][0] == 'for':
                    block_info = directive_stack.pop()
                    if block_info[3]:  # is_reactive
                        rc_id = block_info[1]
                        output_lines.append(
                            f"{indent}@endfor @endReactive('for', {rc_id})"
                        )
                        processed = True
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @while directive
            while_match = re.match(r'^(\s*)@while\s*\(', line)
            if while_match:
                indent = while_match.group(1)
                paren_start = line.index('(', line.index('@while'))
                expr, end_pos = self._extract_balanced_parentheses(line, paren_start)
                if expr is not None:
                    state_keys = self._get_state_keys_from_expr(expr)
                    if state_keys and not in_tag:
                        rc_id = self._generate_reactive_id('while')
                        keys_php = self._format_state_keys_php(state_keys)
                        output_lines.append(
                            f"{indent}@startReactive('while', {rc_id}, {keys_php}) {line.strip()}"
                        )
                        directive_stack.append(('while', rc_id, state_keys, True))
                        processed = True
                    else:
                        directive_stack.append(('while', None, [], False))
                else:
                    directive_stack.append(('while', None, [], False))
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @endwhile directive
            if re.match(r'^(\s*)@endwhile\b', stripped):
                indent = re.match(r'^(\s*)', line).group(1)
                if directive_stack and directive_stack[-1][0] == 'while':
                    block_info = directive_stack.pop()
                    if block_info[3]:  # is_reactive
                        rc_id = block_info[1]
                        output_lines.append(
                            f"{indent}@endwhile @endReactive('while', {rc_id})"
                        )
                        processed = True
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @switch directive
            switch_match = re.match(r'^(\s*)@switch\s*\(', line)
            if switch_match:
                indent = switch_match.group(1)
                paren_start = line.index('(', line.index('@switch'))
                expr, end_pos = self._extract_balanced_parentheses(line, paren_start)
                if expr is not None:
                    state_keys = self._get_state_keys_from_expr(expr)
                    if state_keys and not in_tag:
                        rc_id = self._generate_reactive_id('switch')
                        keys_php = self._format_state_keys_php(state_keys)
                        output_lines.append(
                            f"{indent}@startReactive('switch', {rc_id}, {keys_php}) {line.strip()}"
                        )
                        directive_stack.append(('switch', rc_id, state_keys, True))
                        processed = True
                    else:
                        directive_stack.append(('switch', None, [], False))
                else:
                    directive_stack.append(('switch', None, [], False))
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @endswitch directive
            if re.match(r'^(\s*)@endswitch\b', stripped):
                indent = re.match(r'^(\s*)', line).group(1)
                if directive_stack and directive_stack[-1][0] == 'switch':
                    block_info = directive_stack.pop()
                    if block_info[3]:  # is_reactive
                        rc_id = block_info[1]
                        output_lines.append(
                            f"{indent}@endswitch @endReactive('switch', {rc_id})"
                        )
                        processed = True
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # @include directive (inline, no @end)
            # @include('view', ['key' => $stateVar])
            include_match = re.match(r'^(\s*)@include\s*\(', line)
            if include_match and not processed:
                indent = include_match.group(1)
                paren_start = line.index('(', line.index('@include'))
                expr, end_pos = self._extract_balanced_parentheses(line, paren_start)
                if expr is not None:
                    state_keys = self._get_state_keys_from_expr(expr)
                    if state_keys:
                        covered = self._get_covered_state_keys(directive_stack)
                        uncovered = sorted(set(state_keys) - covered)
                        if uncovered:
                            rc_id = self._generate_reactive_id('include')
                            keys_php = self._format_state_keys_php(uncovered)
                            output_lines.append(
                                f"{indent}@startReactive('include', {rc_id}, {keys_php}) {line.strip()} @endReactive('include', {rc_id})"
                            )
                            processed = True
                
                if not processed:
                    output_lines.append(line)
                continue
            
            # Convert {{ $stateVar }} in HTML attributes to @attr directive
            # Must run BEFORE echo wrapping so attribute echos don't get output-wrapped
            if not processed:
                line, attr_converted = self._convert_echo_attrs_to_attr(line)
            
            # Echo expressions: {{ expr }} and {!! expr !!}
            # Wrap each individual expression inline with @startReactive/@endReactive
            # Only for expressions referencing state variables not covered by parent blocks
            if not processed:
                line, echo_changed = self._wrap_echo_inline(line, directive_stack)
                if echo_changed:
                    processed = True
            
            output_lines.append(line)
        
        return '\n'.join(output_lines)
