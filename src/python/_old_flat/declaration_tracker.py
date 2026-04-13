"""
Declaration Tracker - Track thứ tự khai báo của @vars, @let, @const, @useState
"""

import re
from utils import extract_balanced_parentheses
from php_converter import php_to_js, convert_php_array_to_json

class DeclarationTracker:
    """Track all variable declarations in order"""
    
    def __init__(self):
        self.reset()
    
    def reset(self):
        """Reset tracker state"""
        self.declarations = []  # List of {type, position, content, variables}
        
    def parse_all_declarations(self, blade_code):
        """Parse all declarations and track their order"""
        # Reset to avoid contamination from previous parses
        self.reset()
        
        # Remove script tags to avoid parsing JS code
        blade_code_filtered = self._remove_script_tags(blade_code)
        
        # Remove @verbatim blocks to avoid parsing declarations inside them
        blade_code_filtered = self._remove_verbatim_blocks(blade_code_filtered)
        
        # Find all @vars, @let, @const, @useState with their positions
        self._find_vars_declarations(blade_code_filtered)
        self._find_let_declarations(blade_code_filtered)
        self._find_const_declarations(blade_code_filtered)
        self._find_usestate_declarations(blade_code_filtered)
        
        # Sort by position
        self.declarations.sort(key=lambda x: x['position'])
        
        return self.declarations
    
    def _remove_script_tags(self, blade_code):
        """Remove JavaScript code in <script> tags"""
        return re.sub(r'<script[^>]*>.*?</script>', '', blade_code, flags=re.DOTALL | re.IGNORECASE)
    
    def _remove_verbatim_blocks(self, blade_code):
        """Remove @verbatim...@endverbatim blocks to avoid parsing declarations inside them"""
        return re.sub(r'@verbatim\s*.*?\s*@endverbatim', '', blade_code, flags=re.DOTALL | re.IGNORECASE)
    
    def _find_vars_declarations(self, blade_code):
        """Find all @vars declarations"""
        pattern = r'@vars\s*\('
        for match in re.finditer(pattern, blade_code):
            start_pos = match.end() - 1
            content, end_pos = extract_balanced_parentheses(blade_code, start_pos)
            if content is not None and content.strip():
                variables = self._parse_vars_content(content.strip())
                self.declarations.append({
                    'type': 'vars',
                    'position': match.start(),
                    'content': content.strip(),
                    'variables': variables
                })
    
    def _find_let_declarations(self, blade_code):
        """Find all @let declarations"""
        pattern = r'@let\s*\('
        for match in re.finditer(pattern, blade_code):
            start_pos = match.end() - 1
            content, end_pos = extract_balanced_parentheses(blade_code, start_pos)
            if content is not None and content.strip():
                variables = self._parse_let_content(content.strip())
                self.declarations.append({
                    'type': 'let',
                    'position': match.start(),
                    'content': content.strip(),
                    'variables': variables
                })
    
    def _find_const_declarations(self, blade_code):
        """Find all @const declarations"""
        pattern = r'@const\s*\('
        for match in re.finditer(pattern, blade_code):
            start_pos = match.end() - 1
            content, end_pos = extract_balanced_parentheses(blade_code, start_pos)
            if content is not None and content.strip():
                variables = self._parse_const_content(content.strip())
                self.declarations.append({
                    'type': 'const',
                    'position': match.start(),
                    'content': content.strip(),
                    'variables': variables
                })
    
    def _find_usestate_declarations(self, blade_code):
        """Find all @useState declarations with format @useState($value, $varName, $setVarName)"""
        pattern = r'@useState\s*\('
        for match in re.finditer(pattern, blade_code):
            start_pos = match.end() - 1
            content, end_pos = extract_balanced_parentheses(blade_code, start_pos)
            if content is not None and content.strip():
                variables = self._parse_usestate_content(content.strip())
                if variables:  # Only add if we found valid variables
                    self.declarations.append({
                        'type': 'useState',
                        'position': match.start(),
                        'content': content.strip(),
                        'variables': variables
                    })
    
    def _parse_vars_content(self, content):
        """Parse @vars content and extract variables"""
        variables = []
        
        # Handle object destructuring {var1, var2}
        if content.startswith('{') and content.endswith('}'):
            inner = content[1:-1]
            parts = self._split_by_comma(inner)
        else:
            parts = self._split_by_comma(content)
        
        for part in parts:
            part = part.strip()
            if '=' in part:
                # Has default value: $user = ...
                equals_pos = self._find_first_equals(part)
                if equals_pos != -1:
                    var_name = part[:equals_pos].strip().lstrip('$')
                    var_value = part[equals_pos + 1:].strip()
                    # Convert PHP to JS
                    var_value_js = self._convert_php_to_js(var_value)
                    variables.append({
                        'name': var_name,
                        'value': var_value_js,
                        'hasDefault': True
                    })
            else:
                # No default value: $test
                var_name = part.strip().lstrip('$')
                variables.append({
                    'name': var_name,
                    'value': None,
                    'hasDefault': False
                })
        
        return variables
    
    def _parse_let_content(self, content):
        """Parse @let content and extract variables"""
        variables = []
        
        # Split by comma
        parts = self._split_by_comma(content)
        
        for part in parts:
            part = part.strip().lstrip('$')
            
            # Check for destructuring: [$a, $b] = ... or {a, b} = ...
            if self._is_destructuring(part):
                var_info = self._parse_destructuring(part)
                if var_info:
                    variables.append(var_info)
                continue
            
            # Check for assignment: $d = $a
            if '=' in part:
                equals_pos = self._find_first_equals(part)
                if equals_pos != -1:
                    var_name = part[:equals_pos].strip().lstrip('$')
                    var_value = part[equals_pos + 1:].strip()
                    var_value_js = self._convert_php_to_js(var_value)
                    variables.append({
                        'name': var_name,
                        'value': var_value_js,
                        'hasDefault': True,
                        'isDestructuring': False
                    })
            else:
                # No assignment
                var_name = part.strip().lstrip('$')
                variables.append({
                    'name': var_name,
                    'value': None,
                    'hasDefault': False,
                    'isDestructuring': False
                })
        
        return variables
    
    def _parse_const_content(self, content):
        """Parse @const content and extract variables"""
        variables = []
        
        # Split by comma
        parts = self._split_by_comma(content)
        
        for part in parts:
            part = part.strip().lstrip('$')
            
            # Check for destructuring with useState: [$userState, $setUserState] = useState($user)
            if self._is_destructuring(part):
                var_info = self._parse_destructuring(part)
                if var_info:
                    # Check if it's useState
                    if var_info.get('value') and 'useState(' in var_info['value']:
                        var_info['isUseState'] = True
                    variables.append(var_info)
                continue
            
            # Regular const assignment
            if '=' in part:
                equals_pos = self._find_first_equals(part)
                if equals_pos != -1:
                    var_name = part[:equals_pos].strip().lstrip('$')
                    var_value = part[equals_pos + 1:].strip()
                    var_value_js = self._convert_php_to_js(var_value)
                    variables.append({
                        'name': var_name,
                        'value': var_value_js,
                        'hasDefault': True,
                        'isDestructuring': False,
                        'isUseState': False
                    })
        
        return variables
    
    def _is_destructuring(self, part):
        """Check if part is destructuring pattern"""
        return ('[' in part and ']' in part and '=' in part) or ('{' in part and '}' in part and '=' in part)
    
    def _parse_destructuring(self, part):
        """Parse destructuring pattern: [$a, $b] = ... or {a, b} = ..."""
        equals_pos = self._find_first_equals(part)
        if equals_pos == -1:
            return None
        
        left = part[:equals_pos].strip()
        right = part[equals_pos + 1:].strip()
        
        # Extract variable names from left side
        if left.startswith('[') and ']' in left:
            # Array destructuring
            inner = left[1:left.index(']')]
            var_names = [v.strip().lstrip('$') for v in inner.split(',')]
        elif left.startswith('{') and '}' in left:
            # Object destructuring
            inner = left[1:left.index('}')]
            var_names = [v.strip().lstrip('$') for v in inner.split(',')]
        else:
            return None
        
        # Convert right side to JS
        right_js = self._convert_php_to_js(right)
        
        return {
            'names': var_names,
            'value': right_js,
            'isDestructuring': True,
            'destructuringType': 'array' if '[' in left else 'object',
            'isUseState': 'useState(' in right_js
        }
    
    def _split_by_comma(self, text):
        """Split by comma, respecting brackets and parentheses"""
        parts = []
        current = ''
        depth = 0
        in_string = False
        string_char = ''
        
        for char in text:
            if char in ['"', "'"]:
                if in_string and char == string_char:
                    in_string = False
                elif not in_string:
                    in_string = True
                    string_char = char
            elif not in_string:
                if char in ['(', '[', '{']:
                    depth += 1
                elif char in [')', ']', '}']:
                    depth -= 1
                elif char == ',' and depth == 0:
                    parts.append(current.strip())
                    current = ''
                    continue
            
            current += char
        
        if current.strip():
            parts.append(current.strip())
        
        return parts
    
    def _find_first_equals(self, text):
        """Find first = sign outside of brackets/parentheses"""
        depth = 0
        in_string = False
        string_char = ''
        
        for i, char in enumerate(text):
            if char in ['"', "'"]:
                if in_string and char == string_char:
                    in_string = False
                elif not in_string:
                    in_string = True
                    string_char = char
            elif not in_string:
                if char in ['(', '[', '{']:
                    depth += 1
                elif char in [')', ']', '}']:
                    depth -= 1
                elif char == '=' and depth == 0:
                    return i
        
        return -1
    
    def _convert_php_to_js(self, expr):
        """Convert PHP expression to JavaScript"""
        # Use existing converter
        expr = php_to_js(expr)
        # Remove $ from variables
        expr = re.sub(r'\$(\w+)', r'\1', expr)
        return expr
    
    def _parse_usestate_content(self, content):
        """Parse @useState content with three formats:
        1. @useState($value, $varName, $setVarName) - Single state with 3 params
        2. @useState($varName, value) - Single state with 2 params (auto-generate setter)
        3. @useState(['key1' => value1, 'key2' => value2]) - Multiple states in array
        """
        content = content.strip()
        
        # Check if it's array format ['key' => value, ...]
        if content.startswith('[') and content.endswith(']'):
            return self._parse_usestate_array_format(content)
        
        # Parse parameters
        parts = self._split_by_comma(content)
        
        # New 2-parameter format: @useState($varName, value)
        if len(parts) == 2:
            var_name = parts[0].strip()
            value = parts[1].strip()
            
            # Check if first param is a variable (starts with $)
            if var_name.startswith('$'):
                var_name = var_name.lstrip('$')
                # Auto-generate setter name: user -> setUser
                setter_name = f'set{var_name[0].upper()}{var_name[1:]}' if var_name else 'setValue'
                
                # Convert value to JS
                value_js = self._convert_php_to_js(value)
                
                # Create a destructuring variable like @const([$varName, $setVarName] = useState(value))
                return [{
                    'names': [var_name, setter_name],
                    'value': f'useState({value_js})',
                    'isDestructuring': True,
                    'destructuringType': 'array',
                    'isUseState': True
                }]
        
        # Original 3-parameter format
        if len(parts) == 3:
            value = parts[0].strip()
            var_name = parts[1].strip()
            setter_name = parts[2].strip()
            
            # Remove $ prefix
            value = value.lstrip('$')
            var_name = var_name.lstrip('$')
            setter_name = setter_name.lstrip('$')
            
            # Create a destructuring variable like @let([$varName, $setVarName] = useState($value))
            return [{
                'names': [var_name, setter_name],
                'value': f'useState({self._convert_php_to_js(value)})',
                'isDestructuring': True,
                'destructuringType': 'array',
                'isUseState': True
            }]
        
        return []
    
    def _parse_usestate_array_format(self, content):
        """Parse array format: @useState(['key1' => value1, 'key2' => value2])
        Each key becomes a state variable with its setter
        """
        variables = []
        
        # Remove [ and ]
        inner = content[1:-1].strip()
        
        # Split by comma (handle nested structures)
        parts = self._split_by_comma(inner)
        
        for part in parts:
            part = part.strip()
            if '=>' in part:
                # Split by =>
                arrow_pos = part.find('=>')
                key = part[:arrow_pos].strip().strip("'\"").lstrip('$')
                value = part[arrow_pos + 2:].strip()
                
                # Convert value to JS
                value_js = self._convert_php_to_js(value)
                
                # Create setter name: key -> setKey or set_key
                setter_name = f'set{key[0].upper()}{key[1:]}' if key else 'setValue'
                
                # Create destructuring for each state
                variables.append({
                    'names': [key, setter_name],
                    'value': f'useState({value_js})',
                    'isDestructuring': True,
                    'destructuringType': 'array',
                    'isUseState': True
                })
        
        return variables
