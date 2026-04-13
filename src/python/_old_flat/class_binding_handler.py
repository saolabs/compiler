"""
Class Binding Handler - Xử lý @class directive
Supports: static classes, conditional classes, và mixed binding
Uses separate __classBinding() method for reactive class management
"""

import re
from php_js_converter import php_to_js_advanced

class ClassBindingHandler:
    def __init__(self, state_variables=None):
        self.state_variables = state_variables or set()
    
    def process_class_directive(self, content):
        """
        Process @class directive with multiple formats:
        1. @class('static-class') - static class
        2. @class('class', $condition) - conditional class  
        3. @class(['class1' => $cond1, 'class2' => $cond2]) - array binding
        4. @class(['static', 'dynamic' => $cond]) - mixed
        
        Multiple @class on same line will be merged into one
        
        Output format:
        - Static only: class="static-class"
        - With dynamic: ${this.__classBinding([...])}
        
        Note: Supports multi-line directives within HTML tag attributes
        """
        result = content
        
        # Process all @class directives globally (supports multi-line)
        while True:
            match = re.search(r'@class\s*\(', result)
            if not match:
                break
            
            # Find matching closing parenthesis (can span multiple lines)
            start_pos = match.end() - 1
            paren_count = 0
            i = start_pos
            
            while i < len(result):
                if result[i] == '(':
                    paren_count += 1
                elif result[i] == ')':
                    paren_count -= 1
                    if paren_count == 0:
                        # Found complete directive
                        expression = result[start_pos + 1:i].strip()
                        replacement = self._generate_class_output(expression)
                        result = result[:match.start()] + replacement + result[i + 1:]
                        break
                i += 1
            else:
                # No matching closing paren found - skip this one
                break
        
        return result
    
    def _merge_multiple_class_directives(self, line):
        """
        Merge multiple @class directives on the same line into one
        """
        all_bindings = []
        
        # Extract all @class expressions
        while True:
            match = re.search(r'@class\s*\(', line)
            if not match:
                break
            
            start_pos = match.end() - 1
            paren_count = 0
            i = start_pos
            
            # Find matching closing parenthesis
            while i < len(line):
                if line[i] == '(':
                    paren_count += 1
                elif line[i] == ')':
                    paren_count -= 1
                    if paren_count == 0:
                        expression = line[start_pos + 1:i].strip()
                        bindings = self._parse_class_expression(expression)
                        all_bindings.extend(bindings)
                        # Remove this @class directive
                        line = line[:match.start()] + line[i + 1:]
                        break
                i += 1
            else:
                break
        
        # Generate single output for all merged bindings
        if all_bindings:
            replacement = self._generate_output_from_bindings(all_bindings)
            # Insert at the first @class position (which is now gone)
            # Find a good insertion point - before the first > or at the start
            insertion_point = line.find('>')
            if insertion_point > 0:
                line = line[:insertion_point] + ' ' + replacement + line[insertion_point:]
            else:
                line = line + ' ' + replacement
        
        return line
    
    def _process_single_class_directive(self, line):
        """
        Process a single @class directive on a line
        """
        match = re.search(r'@class\s*\(', line)
        if not match:
            return line
        
        start_pos = match.end() - 1
        paren_count = 0
        i = start_pos
        
        while i < len(line):
            if line[i] == '(':
                paren_count += 1
            elif line[i] == ')':
                paren_count -= 1
                if paren_count == 0:
                    expression = line[start_pos + 1:i].strip()
                    replacement = self._generate_class_output(expression)
                    line = line[:match.start()] + replacement + line[i + 1:]
                    break
            i += 1
        
        return line
    
    def _generate_class_output(self, expression):
        """
        Generate output for class directive
        Returns either static class="..." or ${this.__attr({"class": {...}})}
        """
        bindings = self._parse_class_expression(expression)
        return self._generate_output_from_bindings(bindings)
    
    def _generate_output_from_bindings(self, bindings):
        """
        Generate output from a list of bindings
        Returns either static class="..." or ${this.__classBinding([...])}
        """
        # Check if all bindings are static
        has_dynamic = any(b['type'] == 'binding' for b in bindings)
        
        if not has_dynamic:
            # All static - output direct class attribute
            static_classes = [b['value'] for b in bindings]
            return f'class="{" ".join(static_classes)}"'
        else:
            # Has dynamic - use __classBinding
            return self._generate_class_binding_config(bindings)
    
    def _generate_class_binding_config(self, bindings):
        """
        Generate __classBinding() config
        Format: ${this.__classBinding([{type: "static", value: "...", states: [...], checker: () => ...}])}
        """
        binding_configs = []
        
        for binding in bindings:
            if binding['type'] == 'static':
                binding_configs.append(f'{{type: "static", value: "{binding["value"]}"}}')
            elif binding['type'] == 'binding':
                states = binding.get('states', [])
                states_str = ', '.join([f'"{s}"' for s in states])
                checker = binding['checker']
                class_name = binding['value']
                binding_configs.append(f'{{type: "binding", value: "{class_name}", states: [{states_str}], checker: () => {checker}}}')
        
        configs_joined = ', '.join(binding_configs)
        return f'${{this.__classBinding([{configs_joined}])}}'
    
    def _parse_class_expression(self, expression):
        """
        Parse class expression and return list of binding dicts
        Returns: [{"type": "static"|"binding", "value": "class-name", ...}, ...]
        """
        expression = expression.strip()
        
        # Case 1: Simple string - @class('class')
        if self._is_simple_string(expression):
            class_name = self._extract_string_value(expression)
            return [{"type": "static", "value": class_name}]
        
        # Case 2: Two arguments - @class('class', $condition)
        if ',' in expression and not expression.startswith('['):
            parts = self._split_arguments(expression)
            if len(parts) == 2:
                class_name = self._extract_string_value(parts[0].strip())
                condition = parts[1].strip()
                states = self._extract_state_variables(condition)
                js_condition = self._convert_php_to_js(condition)
                return [{
                    "type": "binding",
                    "value": class_name,
                    "states": states,
                    "checker": js_condition
                }]
        
        # Case 3: Array format - @class(['class1' => $cond, 'class2'])
        if expression.startswith('[') and expression.endswith(']'):
            return self._parse_array_expression(expression)
        
        # Fallback: treat as static class
        return [{"type": "static", "value": expression}]
    
    def _parse_array_expression(self, expression):
        """
        Parse array expression: ['class1' => $cond, 'static', 'class2' => $cond2]
        Returns list of binding dicts
        """
        # Remove outer brackets
        inner = expression[1:-1].strip()
        
        # Split by comma, but respect nested structures
        items = self._split_array_items(inner)
        
        bindings = []
        for item in items:
            item = item.strip()
            if not item:
                continue
            
            # Check if it's a key => value pair
            if '=>' in item:
                parts = item.split('=>', 1)
                class_name = self._extract_string_value(parts[0].strip())
                condition = parts[1].strip()
                
                # Check if condition is a static string
                if self._is_simple_string(condition):
                    # Static value like 'demo' => 'dump'
                    static_value = self._extract_string_value(condition)
                    bindings.append({"type": "static", "value": static_value})
                else:
                    # Dynamic condition
                    states = self._extract_state_variables(condition)
                    js_condition = self._convert_php_to_js(condition)
                    bindings.append({
                        "type": "binding",
                        "value": class_name,
                        "states": states,
                        "checker": js_condition
                    })
            else:
                # Static class without condition
                class_name = self._extract_string_value(item)
                bindings.append({"type": "static", "value": class_name})
        
        return bindings
    
    def _split_array_items(self, content):
        """
        Split array items by comma, respecting nested structures
        """
        items = []
        current = []
        depth = 0
        in_string = False
        string_char = None
        i = 0
        
        while i < len(content):
            char = content[i]
            
            # Handle string boundaries
            if char in ('"', "'") and (i == 0 or content[i-1] != '\\'):
                if not in_string:
                    in_string = True
                    string_char = char
                elif char == string_char:
                    in_string = False
                    string_char = None
            
            # Handle nested structures
            if not in_string:
                if char in '([{':
                    depth += 1
                elif char in ')]}':
                    depth -= 1
                elif char == ',' and depth == 0:
                    items.append(''.join(current))
                    current = []
                    i += 1
                    continue
            
            current.append(char)
            i += 1
        
        if current:
            items.append(''.join(current))
        
        return items
    
    def _split_arguments(self, expression):
        """
        Split function arguments by comma, respecting nested structures
        """
        return self._split_array_items(expression)
    
    def _is_simple_string(self, expression):
        """
        Check if expression is a simple quoted string
        """
        expression = expression.strip()
        return (expression.startswith("'") and expression.endswith("'")) or \
               (expression.startswith('"') and expression.endswith('"'))
    
    def _extract_string_value(self, expression):
        """
        Extract string value from quoted string
        'class' -> class
        "class" -> class
        """
        expression = expression.strip()
        if (expression.startswith("'") and expression.endswith("'")) or \
           (expression.startswith('"') and expression.endswith('"')):
            return expression[1:-1]
        return expression
    
    def _extract_state_variables(self, php_expression):
        """
        Extract state variable names from PHP expression
        $abc -> abc
        $abc->test($cde) -> abc, cde
        $demo && !$on || !$r->e -> demo, on, r
        """
        # Find all $variable patterns
        variables = set()
        matches = re.findall(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', php_expression)
        for var in matches:
            # Only include if it's a state variable
            if var in self.state_variables or True:  # Include all for now
                variables.add(var)
        
        return sorted(list(variables))
    
    def _convert_php_to_js(self, php_expression):
        """
        Convert PHP expression to JavaScript
        Uses the existing php_to_js_advanced function
        """
        return php_to_js_advanced(php_expression)
