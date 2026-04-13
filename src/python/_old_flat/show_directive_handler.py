"""
Show Directive Handler - Xử lý @show directive
Similar to Vue's v-show - toggles element visibility with display property
"""

import re
from php_js_converter import php_to_js_advanced

class ShowDirectiveHandler:
    def __init__(self, state_variables=None):
        self.state_variables = state_variables or set()
    
    def process_show_directive(self, content):
        """
        Process @show directive
        @show($isVisible) -> style="${this.__showBinding(['isVisible'], isVisible)}"
        
        Similar to v-show in Vue - element always exists in DOM, just toggles display
        
        Note: Supports multi-line directives within HTML tag attributes
        """
        result = content
        
        # Process all @show directives globally (supports multi-line)
        while True:
            match = re.search(r'@show\s*\(', result)
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
                        replacement = self._generate_show_output(expression)
                        result = result[:match.start()] + replacement + result[i + 1:]
                        break
                i += 1
            else:
                # No matching closing paren found - skip this one
                break
        
        return result
    
    def _generate_show_output(self, expression):
        """
        Generate reactive show binding output
        
        Input: $isVisible
        Output: style="${this.__showBinding(['isVisible'], isVisible)}"
        """
        # Convert PHP expression to JS
        js_expression = php_to_js_advanced(expression)
        
        # Extract state variables
        state_vars = self._extract_state_variables(expression)
        
        if state_vars:
            watch_keys = list(state_vars)
            return f'style="${{this.__showBinding({watch_keys}, {js_expression})}}"'
        else:
            # No state vars, but still use binding for consistency
            return f'style="${{this.__showBinding([], {js_expression})}}"'
    
    def _extract_state_variables(self, expression):
        """
        Extract state variable names from expression
        """
        state_vars = set()
        
        # Find all PHP variables ($varName)
        var_matches = re.findall(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', expression)
        for var_name in var_matches:
            if var_name in self.state_variables:
                state_vars.add(var_name)
        
        return state_vars
