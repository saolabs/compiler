"""
Echo Processor - Xử lý thông minh {{ }} và {!! !!}
Phân biệt context: content, attribute value, tag body
"""

import re
from common.php_js_converter import php_to_js_advanced
from common.config import APP_VIEW_NAMESPACE, APP_HELPER_NAMESPACE

class EchoProcessor:
    def __init__(self, state_variables=None, is_typescript=False, processor=None):
        """
        Initialize echo processor with state variables
        
        Args:
            state_variables (set): Set of variable names from useState, let, const
            is_typescript (bool): Whether generating TypeScript code
            processor: TemplateProcessor instance for shared reactive counter
        """
        self.state_variables = state_variables or set()
        self.reactive_counter = 0
        self._is_typescript = is_typescript
        self.processor = processor
    
    def process_echo_expressions(self, template_content):
        """
        Main entry point - process all {{ }} and {!! !!} expressions
        
        Returns:
            str: Processed template content
        """
        # First, protect expressions inside @verbatim placeholders
        # They should not be processed
        
        # Process in order:
        # 1. Attributes with {{ }} or {!! !!}
        template_content = self._process_echo_in_attributes(template_content)
        
        # 2. Content {{ }} and {!! !!}
        template_content = self._process_echo_in_content(template_content)
        
        return template_content
    
    def _process_echo_in_attributes(self, content):
        """
        Process {{ }} and {!! !!} inside HTML attribute values
        Also process @checked(...) and @selected(...) directives
        Merge with @attr if needed
        """
        # Instead of simple regex, use manual parsing to handle complex attributes
        # Pattern to find opening tags
        result = []
        pos = 0
        
        while pos < len(content):
            # Find next <
            lt_pos = content.find('<', pos)
            if lt_pos == -1:
                result.append(content[pos:])
                break
            
            # Add content before <
            result.append(content[pos:lt_pos])
            
            # Check if this is a tag
            if lt_pos + 1 >= len(content):
                result.append(content[lt_pos:])
                break
            
            # Get tag name
            tag_start = lt_pos + 1
            tag_name_match = re.match(r'([a-zA-Z][a-zA-Z0-9]*)', content[tag_start:])
            
            if not tag_name_match:
                # Not a valid tag, skip
                result.append('<')
                pos = lt_pos + 1
                continue
            
            tag_name = tag_name_match.group(1)
            attr_start = tag_start + len(tag_name)
            
            # Find the end of tag, handling nested brackets and quotes
            gt_pos = self._find_tag_end(content, attr_start)
            
            if gt_pos == -1:
                # No closing >, treat as text
                result.append(content[lt_pos:])
                break
            
            # Check if self-closing
            self_closing = ''
            if gt_pos > 0 and content[gt_pos - 1] == '/':
                self_closing = '/'
                actual_end = gt_pos - 1
            else:
                actual_end = gt_pos
            
            # Extract attributes
            attributes_str = content[attr_start:actual_end]
            
            # Process this tag
            processed_tag = self._process_single_tag(tag_name, attributes_str, self_closing)
            result.append(processed_tag)
            
            pos = gt_pos + 1
        
        return ''.join(result)
    
    def _find_tag_end(self, content, start_pos):
        """
        Find the closing > of a tag, handling nested brackets, quotes, and arrays
        """
        pos = start_pos
        in_quote = False
        quote_char = None
        paren_depth = 0
        bracket_depth = 0
        
        while pos < len(content):
            ch = content[pos]
            
            # Handle quotes
            if ch in ('"', "'") and (pos == 0 or content[pos - 1] != '\\'):
                if not in_quote:
                    in_quote = True
                    quote_char = ch
                elif ch == quote_char:
                    in_quote = False
                    quote_char = None
            
            # Outside quotes
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
                    return pos
            
            pos += 1
        
        return -1
    
    def _process_single_tag(self, tag_name, attributes_str, self_closing):
        """
        Process a single tag's attributes
        """
        # Process @checked(...) and @selected(...) first
        checked_selected_attrs = {}
        
        # Process @checked(...)
        def extract_checked(m):
            expr = m.group(1).strip()
            js_expr = php_to_js_advanced(expr)
            variables = self._extract_variables(expr)
            state_vars_used = variables & self.state_variables
            
            if state_vars_used:
                checked_selected_attrs['checked'] = {
                    'expressions': [{'type': 'checked', 'php': expr, 'js': js_expr, 'vars': variables}],
                    'state_vars': list(state_vars_used),
                    'original_value': expr
                }
                return ''  # Remove from attributes string
            else:
                # Static evaluation
                return f'${{({js_expr}) ? " checked" : ""}}'
        
        attributes_str = re.sub(r'@checked\s*\(\s*(.*?)\s*\)', extract_checked, attributes_str, flags=re.DOTALL)
        
        # Process @selected(...)
        def extract_selected(m):
            expr = m.group(1).strip()
            js_expr = php_to_js_advanced(expr)
            variables = self._extract_variables(expr)
            state_vars_used = variables & self.state_variables
            
            if state_vars_used:
                checked_selected_attrs['selected'] = {
                    'expressions': [{'type': 'selected', 'php': expr, 'js': js_expr, 'vars': variables}],
                    'state_vars': list(state_vars_used),
                    'original_value': expr
                }
                return ''  # Remove from attributes string
            else:
                # Static evaluation
                return f'${{({js_expr}) ? " selected" : ""}}'
        
        attributes_str = re.sub(r'@selected\s*\(\s*(.*?)\s*\)', extract_selected, attributes_str, flags=re.DOTALL)
        
        # Find all attributes with {{ }} or {!! !!}
        echo_attrs = {}
        has_echo = False or bool(checked_selected_attrs)
        
        # Pattern for attr="{{...}}" or attr="{!!...!!}"
        attr_pattern = r'([a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*["\']([^"\']*(?:\{\{[^}]*\}\}|{!![^!]*!!})[^"\']*)["\']'
        
        def extract_echo_attr(attr_match):
            nonlocal has_echo
            attr_name = attr_match.group(1)
            attr_value = attr_match.group(2)
            
            # Check if has {{ }} or {!! !!}
            if '{{' in attr_value or '{!!' in attr_value:
                has_echo = True
                
                # Extract all expressions in this attribute value
                expressions = []
                used_vars = set()
                
                # Find {{ }} expressions
                for echo_match in re.finditer(r'\{\{([^}]+)\}\}', attr_value):
                    expr = echo_match.group(1).strip()
                    js_expr = php_to_js_advanced(expr)
                    variables = self._extract_variables(expr)
                    used_vars.update(variables)
                    expressions.append({
                        'type': 'escaped',
                        'php': expr,
                        'js': js_expr,
                        'vars': variables
                    })
                
                # Find {!! !!} expressions  
                for raw_match in re.finditer(r'{!!([^!]+)!!}', attr_value):
                    expr = raw_match.group(1).strip()
                    js_expr = php_to_js_advanced(expr)
                    variables = self._extract_variables(expr)
                    used_vars.update(variables)
                    expressions.append({
                        'type': 'unescaped',
                        'php': expr,
                        'js': js_expr,
                        'vars': variables
                    })
                
                # Check if uses state variables
                state_vars_used = used_vars & self.state_variables
                
                if state_vars_used:
                    # Need reactive handling
                    echo_attrs[attr_name] = {
                        'expressions': expressions,
                        'state_vars': list(state_vars_used),
                        'original_value': attr_value
                    }
                    # Remove this attribute from string
                    return ''
                else:
                    # Static - process inline
                    processed_value = attr_value
                    for expr_info in expressions:
                        if expr_info['type'] == 'escaped':
                            replacement = f"${{{APP_HELPER_NAMESPACE}.escString({expr_info['js']})}}"
                        else:
                            replacement = f"${{{expr_info['js']}}}"
                        
                        # Replace in original value
                        if expr_info['type'] == 'escaped':
                            processed_value = processed_value.replace(f"{{{{{expr_info['php']}}}}}", replacement)
                        else:
                            processed_value = processed_value.replace(f"{{!!{expr_info['php']}!!}}", replacement)
                    
                    return f'{attr_name}="{processed_value}"'
            
            return attr_match.group(0)
        
        # Process attributes
        new_attributes_str = re.sub(attr_pattern, extract_echo_attr, attributes_str)
        
        # Merge checked/selected attrs with echo_attrs
        if checked_selected_attrs:
            echo_attrs.update(checked_selected_attrs)
        
        if has_echo and echo_attrs:
            # Check if already has @attr directive
            existing_attr_match = re.search(r'@attr\s*\(', new_attributes_str)
            
            if existing_attr_match:
                # Need to merge with existing @attr
                # Extract the existing @attr parameters
                from common.utils import extract_balanced_parentheses
                start_pos = existing_attr_match.end() - 1
                existing_params, end_pos = extract_balanced_parentheses(new_attributes_str, start_pos)
                
                if existing_params is not None:
                    # Parse existing @attr parameters and merge
                    merged_attrs = self._merge_attr_directives(existing_params, echo_attrs)
                    
                    # Replace the old @attr with merged one
                    new_attr_directive = f"${{this.__attr({merged_attrs})}}"
                    new_attributes_str = (
                        new_attributes_str[:existing_attr_match.start()] + 
                        new_attr_directive + 
                        new_attributes_str[end_pos:]
                    )
            else:
                # No existing @attr, just add new one
                attr_directive = self._generate_attr_directive(echo_attrs)
                new_attributes_str = new_attributes_str + ' ' + attr_directive
        
        # Clean up multiple spaces
        new_attributes_str = re.sub(r'\s+', ' ', new_attributes_str)
        new_attributes_str = new_attributes_str.strip()
        
        # Add space before attributes if not empty
        if new_attributes_str:
            new_attributes_str = ' ' + new_attributes_str
        
        return f'<{tag_name}{new_attributes_str}{self_closing}>'
    
    def _process_echo_in_content(self, content):
        """
        Process {{ }} and {!! !!} in content (not in attributes)
        """
        # Skip if inside attribute values (already processed)
        # This is for content like: <div>{{ $name }}</div>
        
        def replace_escaped_echo(match):
            expr = match.group(1).strip()
            js_expr = php_to_js_advanced(expr)
            variables = self._extract_variables(expr)
            
            # Check if uses state variables
            state_vars_used = variables & self.state_variables
            
            # Check if we're inside an HTML tag (as a standalone attribute, not attribute value)
            # Example: <input type="checkbox" {{ $checked ? 'checked' : '' }}>
            pos = match.start()
            tag_start = content.rfind('<', 0, pos)
            
            if tag_start != -1:
                tag_end = content.find('>', pos)
                if tag_end != -1:
                    # Check if there's a > between tag_start and pos
                    intermediate_close = content.rfind('>', tag_start, pos)
                    if intermediate_close == -1:
                        # We're inside a tag - check if we're inside quotes
                        tag_content = content[tag_start:pos]
                        in_double = tag_content.count('"') % 2 == 1
                        in_single = tag_content.count("'") % 2 == 1
                        
                        if not in_double and not in_single:
                            # Inside tag, outside quotes - this is a standalone attribute
                            # Use simple interpolation without __outputEscaped wrapper
                            return f"${{{APP_HELPER_NAMESPACE}.escString({js_expr})}}"
            
            if state_vars_used:
                # Reactive output with escaped HTML
                state_vars_list = list(state_vars_used)
                # Use new __reactive method with full signature
                rc_param = "(__rc__: any)" if self._is_typescript else "(__rc__)"
                rc_id = f"__RC_OUTPUT_PH_{self._next_reactive_id()}__"
                return f"${{this.__reactive('output', __rc__, {rc_id}, {state_vars_list}, {rc_param} => {js_expr}, {{type: 'output', escapeHTML: true}})}}"
            else:
                # Static output
                return f"${{{APP_HELPER_NAMESPACE}.escString({js_expr})}}"
        
        def replace_unescaped_echo(match):
            expr = match.group(1).strip()
            js_expr = php_to_js_advanced(expr)
            variables = self._extract_variables(expr)
            
            # Check if uses state variables
            state_vars_used = variables & self.state_variables
            
            # Check if we're inside an HTML tag
            pos = match.start()
            tag_start = content.rfind('<', 0, pos)
            
            if tag_start != -1:
                tag_end = content.find('>', pos)
                if tag_end != -1:
                    intermediate_close = content.rfind('>', tag_start, pos)
                    if intermediate_close == -1:
                        tag_content = content[tag_start:pos]
                        in_double = tag_content.count('"') % 2 == 1
                        in_single = tag_content.count("'") % 2 == 1
                        
                        if not in_double and not in_single:
                            # Inside tag, outside quotes - use simple interpolation
                            return f"${{{js_expr}}}"
            
            if state_vars_used:
                # Reactive output (unescaped) using new __reactive method
                state_vars_list = list(state_vars_used)
                rc_param = "(__rc__: any)" if self._is_typescript else "(__rc__)"
                rc_id = f"__RC_OUTPUT_PH_{self._next_reactive_id()}__"
                return f"${{this.__reactive('output', __rc__, {rc_id}, {state_vars_list}, {rc_param} => {js_expr}, {{type: 'output', escapeHTML: false}})}}"
            else:
                # Static output
                return f"${{{js_expr}}}"
        
        # Process {!! !!} first (to avoid confusion with {{ }})
        content = re.sub(r'{!!([^!]+)!!}', replace_unescaped_echo, content)
        
        # Process {{ }}
        content = re.sub(r'\{\{([^}]+)\}\}', replace_escaped_echo, content)
        
        return content
    
    def _extract_variables(self, php_expr):
        """
        Extract variable names from PHP expression
        Returns set of variable names (without $ prefix)
        """
        variables = set()
        
        # Find all $variableName patterns
        var_pattern = r'\$([a-zA-Z_][a-zA-Z0-9_]*)'
        matches = re.findall(var_pattern, php_expr)
        
        for var_name in matches:
            variables.add(var_name)
        
        return variables
    
    def _generate_attr_directive(self, echo_attrs):
        """
        Generate @attr directive from echo_attrs
        
        Args:
            echo_attrs: dict of {attr_name: {expressions, state_vars, original_value}}
        
        Returns:
            str: @attr directive string
        """
        # Build attribute object
        attrs_obj = {}
        
        for attr_name, attr_info in echo_attrs.items():
            # Check if this is @checked or @selected
            is_boolean_attr = (
                len(attr_info['expressions']) == 1 and 
                attr_info['expressions'][0]['type'] in ['checked', 'selected']
            )
            
            if is_boolean_attr:
                # For @checked/@selected, generate: () => expr ? true : false
                expr_info = attr_info['expressions'][0]
                render_func = f"() => ({expr_info['js']}) ? true : false"
                
                attrs_obj[attr_name] = {
                    'states': attr_info['state_vars'],
                    'render': render_func
                }
            else:
                # Combine all expressions in this attribute
                combined_expr = attr_info['original_value']
                
                # Convert to JS
                js_expr = combined_expr
                for expr_info in attr_info['expressions']:
                    if expr_info['type'] == 'escaped':
                        # For attributes, don't use __outputEscaped, just use variable directly
                        replacement = expr_info['js']
                        # Don't use exact match with expr_info['php'] because it's stripped
                        # Use regex to match {{ spaces $var spaces }}
                        js_expr = re.sub(r'\{\{\s*' + re.escape(expr_info['php']) + r'\s*\}\}', f"${{({replacement})}}", js_expr)
                    else:
                        replacement = expr_info['js']
                        # Same for unescaped
                        js_expr = re.sub(r'\{!!\s*' + re.escape(expr_info['php']) + r'\s*!!\}', f"${{({replacement})}}", js_expr)
                
                # Simplify if expression is just a single variable
                # Check if js_expr is exactly "${(varname)}" → simplify to just "varname"
                single_expr_match = re.match(r'^\$\{\(([^)]+)\)\}$', js_expr)
                
                if single_expr_match:
                    # Single expression, no template literal needed
                    render_func = f"() => {single_expr_match.group(1)}"
                else:
                    # Complex expression or multiple expressions, use template literal
                    render_func = f"() => `{js_expr}`"
                
                attrs_obj[attr_name] = {
                    'states': attr_info['state_vars'],
                    'render': render_func
                }
        
        # Convert to @attr format
        # ${this.__attr({"attr1": {states:["a"], render: () => a}, ...})}
        attr_parts = []
        for attr_name, attr_config in attrs_obj.items():
            states_str = str(attr_config['states']).replace("'", '"')
            render_str = attr_config['render']
            attr_parts.append(f'"{attr_name}": {{states: {states_str}, render: {render_str}}}')
        
        attr_obj_str = '{' + ', '.join(attr_parts) + '}'
        
        return f"${{this.__attr({attr_obj_str})}}"
    
    def _merge_attr_directives(self, existing_params, echo_attrs):
        """
        Merge existing @attr parameters with echo_attrs
        
        Args:
            existing_params (str): Existing @attr parameters, e.g., "'data-count', $count" or "['attr' => $val]"
            echo_attrs (dict): New attributes from {{ }} expressions
            
        Returns:
            str: Merged attribute object string
        """
        merged_attrs = {}
        
        # Parse existing @attr parameters
        # Handle two formats:
        # 1. Simple format: @attr('attr-name', $value)
        # 2. Array format: @attr(['attr-name' => $value, ...])
        
        existing_params = existing_params.strip()
        
        if existing_params.startswith('[') and existing_params.endswith(']'):
            # Array format: ['attr' => value, ...]
            # Parse array elements
            array_content = existing_params[1:-1].strip()
            # Simple split by comma (should handle quotes properly)
            pairs = self._split_attr_pairs(array_content)
            
            for pair in pairs:
                if '=>' in pair:
                    parts = pair.split('=>', 1)
                    attr_name = parts[0].strip().strip('"').strip("'")
                    attr_value = parts[1].strip()
                    
                    # Extract variables and convert to JS
                    variables = self._extract_variables(attr_value)
                    state_vars_used = variables & self.state_variables
                    
                    if state_vars_used:
                        from common.php_js_converter import php_to_js_advanced
                        js_expr = php_to_js_advanced(attr_value)
                        merged_attrs[attr_name] = {
                            'states': list(state_vars_used),
                            'render': f"() => {js_expr}"
                        }
        else:
            # Simple format: 'attr-name', $value
            parts = self._split_attr_pairs(existing_params)
            if len(parts) >= 2:
                attr_name = parts[0].strip().strip('"').strip("'")
                attr_value = ','.join(parts[1:]).strip()
                
                # Extract variables and convert to JS
                variables = self._extract_variables(attr_value)
                state_vars_used = variables & self.state_variables
                
                if state_vars_used:
                    from common.php_js_converter import php_to_js_advanced
                    js_expr = php_to_js_advanced(attr_value)
                    merged_attrs[attr_name] = {
                        'states': list(state_vars_used),
                        'render': f"() => {js_expr}"
                    }
        
        # Add echo_attrs (from {{ }} expressions or @checked/@selected)
        for attr_name, attr_info in echo_attrs.items():
            # Check if this is @checked or @selected
            is_boolean_attr = (
                len(attr_info['expressions']) == 1 and 
                attr_info['expressions'][0]['type'] in ['checked', 'selected']
            )
            
            if is_boolean_attr:
                # For @checked/@selected, generate: () => expr ? true : false
                expr_info = attr_info['expressions'][0]
                render_func = f"() => ({expr_info['js']}) ? true : false"
                
                merged_attrs[attr_name] = {
                    'states': attr_info['state_vars'],
                    'render': render_func
                }
            else:
                # Build JS expression for regular attributes
                combined_expr = attr_info['original_value']
                js_expr = combined_expr
                
                for expr_info in attr_info['expressions']:
                    if expr_info['type'] == 'escaped':
                        replacement = expr_info['js']
                        js_expr = re.sub(r'\{\{\s*' + re.escape(expr_info['php']) + r'\s*\}\}', f"${{({replacement})}}", js_expr)
                    else:
                        replacement = expr_info['js']
                        js_expr = re.sub(r'\{!!\s*' + re.escape(expr_info['php']) + r'\s*!!\}', f"${{({replacement})}}", js_expr)
                
                # Simplify if expression is just a single variable
                single_expr_match = re.match(r'^\$\{\(([^)]+)\)\}$', js_expr)
                
                if single_expr_match:
                    # Single expression, no template literal needed
                    render_func = f"() => {single_expr_match.group(1)}"
                else:
                    # Complex expression or multiple expressions, use template literal
                    render_func = f"() => `{js_expr}`"
                
                merged_attrs[attr_name] = {
                    'states': attr_info['state_vars'],
                    'render': render_func
                }
        
        # Convert to JSON-like string
        attr_parts = []
        for attr_name, attr_config in merged_attrs.items():
            states_str = str(attr_config['states']).replace("'", '"')
            render_str = attr_config['render']
            attr_parts.append(f'"{attr_name}": {{states: {states_str}, render: {render_str}}}')
        
        return '{' + ', '.join(attr_parts) + '}'
    
    def _split_attr_pairs(self, content):
        """
        Split attribute pairs by comma, respecting quotes and parentheses
        """
        pairs = []
        current = ''
        paren_depth = 0
        bracket_depth = 0
        in_quotes = False
        quote_char = None
        
        for i, char in enumerate(content):
            if not in_quotes:
                if char in ['"', "'"]:
                    in_quotes = True
                    quote_char = char
                    current += char
                elif char == '(':
                    paren_depth += 1
                    current += char
                elif char == ')':
                    paren_depth -= 1
                    current += char
                elif char == '[':
                    bracket_depth += 1
                    current += char
                elif char == ']':
                    bracket_depth -= 1
                    current += char
                elif char == ',' and paren_depth == 0 and bracket_depth == 0:
                    if current.strip():
                        pairs.append(current.strip())
                    current = ''
                else:
                    current += char
            else:
                current += char
                if char == quote_char:
                    # Check if escaped
                    if i > 0 and content[i-1] != '\\':
                        in_quotes = False
        
        if current.strip():
            pairs.append(current.strip())
        
        return pairs
    
    def _is_complex_structure(self, js_expr):
        """
        Check if expression is complex structure (array/object)
        If yes, don't escape
        """
        # Simple heuristic - check for brackets
        return '{' in js_expr or '[' in js_expr
    
    def _next_reactive_id(self):
        """
        Get next reactive counter (uses shared processor counter if available, else local)
        """
        if self.processor:
            self.processor.reactive_counter += 1
            return self.processor.reactive_counter
        self.reactive_counter += 1
        return self.reactive_counter

    def _generate_reactive_id(self):
        """
        Generate unique reactive component ID (legacy, kept for compatibility)
        """
        return f"rc-${{__VIEW_ID__}}-output-{self._next_reactive_id()}"
