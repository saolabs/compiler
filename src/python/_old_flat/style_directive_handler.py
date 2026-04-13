"""
Style Directive Handler - Xử lý @style directive
Supports: dynamic inline styles với reactive binding
Uses __styleBinding() method for reactive style management
"""

import re
from php_js_converter import php_to_js_advanced

class StyleDirectiveHandler:
    def __init__(self, state_variables=None):
        self.state_variables = state_variables or set()
    
    def process_style_directive(self, content):
        """
        Process @style directive với format:
        @style(['color' => $textColor, 'font-size' => $fontSize])
        @style(['background-color' => $isActive ? '#green' : '#red'])
        
        Output format: ${this.__styleBinding([...])}
        
        Note: Supports multi-line directives within HTML tag attributes
        """
        result = content
        
        # Process all @style directives globally (supports multi-line)
        while True:
            match = re.search(r'@style\s*\(', result)
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
                        replacement = self._generate_style_output(expression)
                        result = result[:match.start()] + replacement + result[i + 1:]
                        break
                i += 1
            else:
                # No matching closing paren found - skip this one
                break
        
        return result
    
    def _generate_style_output(self, expression):
        """
        Generate reactive style binding output
        
        Input: ['color' => $textColor, 'font-size' => $fontSize]
        Output: ${this.__styleBinding([['color', textColor], ['font-size', fontSize]])}
        """
        # Parse PHP array syntax
        styles = self._parse_style_expression(expression)
        
        if not styles:
            return ''
        
        # Extract state variables used
        state_vars = self._extract_state_variables(styles)
        
        # Build JavaScript array of [key, value] pairs
        js_bindings = []
        for prop, value in styles:
            # Convert PHP expression to JS
            js_value = php_to_js_advanced(value)
            js_bindings.append(f"['{prop}', {js_value}]")
        
        js_array = '[' + ', '.join(js_bindings) + ']'
        
        # Generate reactive binding with state tracking
        if state_vars:
            watch_keys = list(state_vars)
            return f"${{this.__styleBinding({watch_keys}, {js_array})}}"
        else:
            # No state vars, but still use binding for consistency
            return f"${{this.__styleBinding([], {js_array})}}"
    
    def _parse_style_expression(self, expression):
        """
        Parse PHP array expression into list of (property, value) tuples
        
        Input: 'color' => $textColor, 'font-size' => $fontSize
        Output: [('color', '$textColor'), ('font-size', '$fontSize')]
        """
        styles = []
        
        # Remove outer brackets if present
        expression = expression.strip()
        if expression.startswith('[') and expression.endswith(']'):
            expression = expression[1:-1].strip()
        
        # Split by commas (but not inside quotes or parentheses)
        parts = self._smart_split(expression, ',')
        
        for part in parts:
            part = part.strip()
            if not part:
                continue
            
            # Split by =>
            if '=>' in part:
                key_value = part.split('=>', 1)
                if len(key_value) == 2:
                    key = key_value[0].strip().strip('\'"')
                    value = key_value[1].strip()
                    styles.append((key, value))
        
        return styles
    
    def _smart_split(self, text, delimiter):
        """
        Split text by delimiter, but ignore delimiters inside quotes or parentheses
        """
        parts = []
        current = []
        depth = 0
        in_quote = False
        quote_char = None
        
        for i, char in enumerate(text):
            if char in ('"', "'") and (i == 0 or text[i-1] != '\\'):
                if not in_quote:
                    in_quote = True
                    quote_char = char
                elif char == quote_char:
                    in_quote = False
                    quote_char = None
            
            if not in_quote:
                if char in '([{':
                    depth += 1
                elif char in ')]}':
                    depth -= 1
                elif char == delimiter and depth == 0:
                    parts.append(''.join(current))
                    current = []
                    continue
            
            current.append(char)
        
        if current:
            parts.append(''.join(current))
        
        return parts
    
    def _extract_state_variables(self, styles):
        """
        Extract state variable names from style values
        """
        state_vars = set()
        
        for _, value in styles:
            # Find all PHP variables ($varName)
            var_matches = re.findall(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', value)
            for var_name in var_matches:
                if var_name in self.state_variables:
                    state_vars.add(var_name)
        
        return state_vars
