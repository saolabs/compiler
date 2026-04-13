"""
Parsers cho các directive (@extends, @vars, @fetch, @onInit)
"""

import re
import json
from utils import extract_balanced_parentheses
from php_converter import php_to_js, convert_php_array_to_json, convert_php_array_with_php_r

class DirectiveParsers:
    def __init__(self):
        pass
    
    def _remove_script_tags(self, blade_code):
        """Loại bỏ JavaScript code trong <script> tags để tránh xử lý useState trong JS"""
        # Loại bỏ tất cả content trong <script> tags
        filtered_code = re.sub(r'<script[^>]*>.*?</script>', '', blade_code, flags=re.DOTALL | re.IGNORECASE)
        return filtered_code
    
    def _remove_verbatim_blocks(self, blade_code):
        """Loại bỏ @verbatim...@endverbatim blocks để tránh xử lý directives bên trong"""
        # Loại bỏ tất cả content trong @verbatim blocks
        filtered_code = re.sub(r'@verbatim\s*.*?\s*@endverbatim', '', blade_code, flags=re.DOTALL | re.IGNORECASE)
        return filtered_code
    
    def parse_extends(self, blade_code):
        """Parse @extends directive"""
        extends_match = re.search(r'@extends\s*\(\s*([^)]+)\s*\)', blade_code, re.DOTALL)
        if not extends_match:
            return None, None, None
            
        extends_content = extends_match.group(1).strip()
        
        # Check if there's a comma (indicating data parameter)
        comma_pos = extends_content.find(',')
        if comma_pos != -1:
            view_expr = extends_content[:comma_pos].strip()
            data_expr = extends_content[comma_pos + 1:].strip()
            extends_data = self._convert_extends_data(data_expr)
        else:
            view_expr = extends_content
            extends_data = None
        
        # Check if it's a simple string literal (no variables or function calls)
        if self._is_simple_string_literal(view_expr):
            extended_view = view_expr[1:-1]  # Remove quotes
            extends_expression = None
        else:
            # Complex expression - convert to JavaScript
            extends_expression = self._convert_extends_expression(view_expr)
            extended_view = None
        
        return extended_view, extends_expression, extends_data
    
    def parse_vars(self, blade_code):
        """Parse @vars directive - improved to handle complex arrays like Event directive"""
        # Loại bỏ @verbatim blocks để tránh parse directives bên trong
        blade_code = self._remove_verbatim_blocks(blade_code)
        vars_match = re.search(r'@vars\s*\(\s*(.*?)\s*\)', blade_code, re.DOTALL)
        if not vars_match:
            return ''
            
        vars_content = vars_match.group(1)
        var_parts = []
        
        # Special handling for object destructuring syntax {var1, var2}
        if vars_content.strip().startswith('{') and vars_content.strip().endswith('}'):
            # Extract content inside braces
            inner_content = vars_content.strip()[1:-1]
            # Split by comma at level 0
            parts = self._split_vars_content_correct(inner_content)
        else:
            # Use improved splitting logic (same as Event directive)
            parts = self._split_vars_content_correct(vars_content)
        
        for var in parts:
            var = var.strip()
            if '=' in var:
                equals_pos = self._find_first_equals(var)
                if equals_pos != -1:
                    var_name = var[:equals_pos].strip().lstrip('$')
                    var_value = var[equals_pos + 1:].strip()
                    # Convert PHP array syntax to JavaScript
                    var_value = self._convert_php_to_js(var_value)
                    var_parts.append(f"{var_name} = {var_value}")
                else:
                    var_name = var.strip().lstrip('$')
                    var_parts.append(var_name)
            else:
                var_name = var.strip().lstrip('$')
                var_parts.append(var_name)
        
        return "let {" + ', '.join(var_parts) + "} = $$$DATA$$$ || {};"
    
    def parse_let_directives(self, blade_code):
        """Parse @let directives - chỉ xử lý Blade directives, không xử lý JavaScript code"""
        # Loại bỏ JavaScript code trong <script> tags trước khi parse
        blade_code_filtered = self._remove_script_tags(blade_code)
        # Loại bỏ @verbatim blocks để tránh parse directives bên trong
        blade_code_filtered = self._remove_verbatim_blocks(blade_code_filtered)
        
        # Sử dụng balanced parentheses để parse đúng
        let_matches = []
        pattern = r'@let\s*\('
        for match in re.finditer(pattern, blade_code_filtered):
            start_pos = match.end() - 1
            content, _ = extract_balanced_parentheses(blade_code_filtered, start_pos)
            if content is not None and content.strip():
                let_matches.append(content.strip())
        
        if not let_matches:
            return ''
        
        let_declarations = []
        for expression in let_matches:
            # Split expression by comma để xử lý mixed declarations
            parts = self._split_assignments(expression)
            
            for part in parts:
                part = part.strip()
                if not part:
                    continue
                
                # Loại bỏ dấu $ ở đầu part nếu có
                part = re.sub(r'^\s*\$\s*', '', part)
                    
                # Xử lý destructuring syntax đặc biệt
                if '[' in part and ']' in part and '=' in part:
                    # Đây là destructuring assignment
                    js_expression = self._convert_destructuring_assignment(part)
                    if js_expression:
                        # Thay const bằng let
                        js_expression = js_expression.replace('const ', 'let ')
                        let_declarations.append(js_expression)
                        continue
                elif '{' in part and '}' in part and '=' in part:
                    # Object destructuring
                    js_expression = self._convert_destructuring_assignment(part)
                    if js_expression:
                        js_expression = js_expression.replace('const ', 'let ')
                        let_declarations.append(js_expression)
                        continue
                
                # Convert PHP to JavaScript với xử lý mảng bằng php -r
                js_expression = self._convert_php_expression_with_arrays(part)
                
                # Loại bỏ dấu $ từ biến (nếu có) - cải thiện regex
                js_expression = re.sub(r'\$(\w+)', r'\1', js_expression)
                
                # Loại bỏ dấu $ ở đầu assignment nếu có
                if '=' in js_expression:
                    parts = js_expression.split('=', 1)
                    if len(parts) == 2:
                        left_part = parts[0].strip()
                        right_part = parts[1].strip()
                        # Loại bỏ $ ở đầu left part
                        left_part = re.sub(r'^\s*\$\s*', '', left_part)
                        js_expression = f'{left_part} = {right_part}'
                
                # Thêm prefix 'let ' và dấu ; nếu chưa có
                if not js_expression.startswith('let '):
                    js_expression = 'let ' + js_expression
                if not js_expression.endswith(';'):
                    js_expression += ';'
                let_declarations.append(js_expression)
        
        return '\n'.join(let_declarations)
    
    def _convert_php_expression_with_arrays(self, expression):
        """Convert PHP expression to JavaScript with array handling using php -r"""
        # Sử dụng regex đơn giản để tìm và replace arrays
        def replace_array(match):
            array_expr = match.group(0)
            # Sử dụng php -r để convert array
            json_result = convert_php_array_with_php_r(array_expr)
            if json_result is not None:
                return json_result
            # Fallback to old method
            return self._convert_php_array_legacy(array_expr)
        
        # Sử dụng php -r trực tiếp cho toàn bộ expression nếu có array
        import re
        if '[' in expression and ']' in expression:
            # Thử sử dụng php -r cho toàn bộ expression
            json_result = convert_php_array_with_php_r(expression)
            if json_result is not None:
                expression = json_result
            else:
                # Fallback to convert_php_array_to_json
                expression = convert_php_array_to_json(expression)
        
        # Convert remaining PHP to JavaScript
        result = php_to_js(expression)
        
        # Loại bỏ dấu $ từ biến (nếu có)
        result = re.sub(r'\$(\w+)', r'\1', result)
        
        return result
    
    def _split_assignments(self, expression):
        """Split expression by comma, respecting parentheses and brackets"""
        assignments = []
        current = ''
        balance = 0
        in_string = False
        string_char = ''
        
        for char in expression:
            if char in ['"', "'"]:
                if in_string and char == string_char:
                    in_string = False
                elif not in_string:
                    in_string = True
                    string_char = char
            elif not in_string:
                if char in ['(', '[', '{']:
                    balance += 1
                elif char in [')', ']', '}']:
                    balance -= 1
                elif char == ',' and balance == 0:
                    assignments.append(current.strip())
                    current = ''
                    continue
            
            current += char
        
        if current.strip():
            assignments.append(current.strip())
        
        return assignments
    
    def parse_const_directives(self, blade_code):
        """Parse @const directives - chỉ xử lý Blade directives, không xử lý JavaScript code"""
        # Loại bỏ JavaScript code trong <script> tags trước khi parse
        blade_code_filtered = self._remove_script_tags(blade_code)
        # Loại bỏ @verbatim blocks để tránh parse directives bên trong
        blade_code_filtered = self._remove_verbatim_blocks(blade_code_filtered)
        
        # Sử dụng balanced parentheses để parse đúng
        const_matches = []
        pattern = r'@const\s*\('
        for match in re.finditer(pattern, blade_code_filtered):
            start_pos = match.end() - 1
            content, _ = extract_balanced_parentheses(blade_code_filtered, start_pos)
            if content is not None and content.strip():
                const_matches.append(content.strip())
        
        if not const_matches:
            return ''
        
        const_declarations = []
        for expression in const_matches:
            # Xử lý destructuring syntax đặc biệt
            if '[' in expression and ']' in expression and '=' in expression:
                # Đây là destructuring assignment
                js_expression = self._convert_destructuring_assignment(expression)
                if js_expression:
                    const_declarations.append(js_expression)
                    continue
            
            # Convert PHP to JavaScript với xử lý mảng bằng php -r
            js_expression = self._convert_php_expression_with_arrays(expression)
            
            # Split by comma to handle multiple assignments
            assignments = self._split_assignments(js_expression)
            
            for assignment in assignments:
                assignment = assignment.strip()
                if assignment:
                    # Thêm prefix 'const ' và dấu ; nếu chưa có
                    if not assignment.startswith('const '):
                        assignment = 'const ' + assignment
                    if not assignment.endswith(';'):
                        assignment += ';'
                    const_declarations.append(assignment)
        
        return '\n'.join(const_declarations)
    
    def _convert_destructuring_assignment(self, expression):
        """Convert destructuring assignment like [a, b] = something() or {a, b} = something()"""
        # Tìm dấu = đầu tiên
        equals_pos = expression.find('=')
        if equals_pos == -1:
            return None
        
        # Lấy phần bên trái (destructuring pattern) và bên phải (value)
        left_part = expression[:equals_pos].strip()
        right_part = expression[equals_pos + 1:].strip()
        
        # Xử lý left part: [$userState, $setUserState] -> [userState, setUserState]
        if left_part.startswith('[') and left_part.endswith(']'):
            # Array destructuring
            inner_content = left_part[1:-1].strip()
            # Split by comma và loại bỏ $ từ mỗi biến
            variables = []
            for var in inner_content.split(','):
                var = var.strip().lstrip('$')
                variables.append(var)
            
            left_part_js = '[' + ', '.join(variables) + ']'
        elif left_part.startswith('{') and left_part.endswith('}'):
            # Object destructuring
            inner_content = left_part[1:-1].strip()
            # Split by comma và loại bỏ $ từ mỗi biến
            variables = []
            for var in inner_content.split(','):
                var = var.strip().lstrip('$')
                variables.append(var)
            
            left_part_js = '{' + ', '.join(variables) + '}'
        else:
            left_part_js = left_part
        
        # Convert right part với xử lý mảng
        right_part_js = self._convert_php_expression_with_arrays(right_part)
        
        return f'const {left_part_js} = {right_part_js};'
    
    def parse_usestate_directives(self, blade_code):
        """Parse @useState directives - chỉ xử lý Blade directives, không xử lý JavaScript code"""
        # Loại bỏ JavaScript code trong <script> tags trước khi parse
        blade_code_filtered = self._remove_script_tags(blade_code)
        # Loại bỏ @verbatim blocks để tránh parse directives bên trong
        blade_code_filtered = self._remove_verbatim_blocks(blade_code_filtered)
        
        usestate_matches = re.findall(r'@useState\s*\(\s*(.*?)\s*\)', blade_code_filtered, re.DOTALL)
        if not usestate_matches:
            return ''
        
        usestate_declarations = []
        for match in usestate_matches:
            expression = match.strip()
            # Parse 3 parameters: value, stateName, setStateName
            params = self._parse_usestate_params(expression)
            if len(params) >= 2:
                value = params[0]
                state_name = params[1] if len(params) > 1 else 'state'
                set_state_name = params[2] if len(params) > 2 else 'setState'
                # Convert PHP to JavaScript với xử lý mảng bằng php -r
                value_js = self._convert_php_expression_with_arrays(value)
                state_name_js = php_to_js(state_name)
                set_state_name_js = php_to_js(set_state_name)
                usestate_declarations.append(f'const [{state_name_js}, {set_state_name_js}] = useState({value_js});')
        
        return '\n'.join(usestate_declarations)
    
    def _parse_usestate_params(self, expression):
        """Parse @useState parameters with proper array handling"""
        params = []
        current = ''
        paren_count = 0
        bracket_count = 0
        in_quotes = False
        quote_char = ''
        i = 0
        
        while i < len(expression):
            char = expression[i]
            
            if not in_quotes:
                if char in ['"', "'"]:
                    in_quotes = True
                    quote_char = char
                    current += char
                elif char == '(':
                    paren_count += 1
                    current += char
                elif char == ')':
                    paren_count -= 1
                    current += char
                elif char == '[':
                    bracket_count += 1
                    current += char
                elif char == ']':
                    bracket_count -= 1
                    current += char
                elif char == ',' and paren_count == 0 and bracket_count == 0:
                    params.append(current.strip())
                    current = ''
                else:
                    current += char
            else:
                current += char
                if char == quote_char:
                    # Check if it's escaped
                    escape_count = 0
                    j = i - 1
                    while j >= 0 and expression[j] == '\\':
                        escape_count += 1
                        j -= 1
                    
                    # If even number of backslashes, quote is not escaped
                    if escape_count % 2 == 0:
                        in_quotes = False
            
            i += 1
        
        if current.strip():
            params.append(current.strip())
        
        return params
    
    def _split_fetch_parameters(self, content):
        """Split fetch parameters by comma, respecting parentheses, brackets and quotes"""
        params = []
        current = ''
        paren_count = 0
        bracket_count = 0
        in_quotes = False
        quote_char = ''
        
        i = 0
        while i < len(content):
            char = content[i]
            
            if not in_quotes:
                if char in ['"', "'"]:
                    in_quotes = True
                    quote_char = char
                    current += char
                elif char == '(':
                    paren_count += 1
                    current += char
                elif char == ')':
                    paren_count -= 1
                    current += char
                elif char == '[':
                    bracket_count += 1
                    current += char
                elif char == ']':
                    bracket_count -= 1
                    current += char
                elif char == ',' and paren_count == 0 and bracket_count == 0:
                    # Comma at level 0 - parameter separator
                    params.append(current.strip())
                    current = ''
                    i += 1
                    continue
                else:
                    current += char
            else:
                current += char
                if char == quote_char and (i == 0 or content[i-1] != '\\'):
                    in_quotes = False
                    quote_char = ''
            
            i += 1
        
        # Add last parameter
        if current.strip():
            params.append(current.strip())
        
        return params
    
    def _parse_php_array_to_js_object(self, php_array_str):
        """Parse PHP array string to JavaScript object"""
        try:
            # Convert PHP array syntax to JavaScript object syntax
            js_str = php_array_str
            # Remove $ prefix from variables
            js_str = re.sub(r'(?<!")\$(\w+)(?!")', r'\1', js_str)
            # Convert array syntax to object syntax
            js_str = re.sub(r'\[', '{', js_str)
            js_str = re.sub(r'\]', '}', js_str)
            js_str = re.sub(r'\s*=>\s*', ': ', js_str)
            js_str = re.sub(r'\s+\.\s+', ' + ', js_str)
            
            # Handle single quotes to double quotes for JSON
            js_str = re.sub(r"'([^']*)'", r'"\1"', js_str)
            
            # Parse the JavaScript object
            import json
            return json.loads(js_str)
        except Exception as e:
            print(f"Error parsing {php_array_str}: {e}")
            return {}
    
    def parse_fetch(self, blade_code):
        """Parse @fetch directive - supports @fetch(url, data, headers) and @fetch([config])"""
        fetch_match = re.search(r'@fetch\s*\(', blade_code)
        if not fetch_match:
            return None
            
        start_pos = fetch_match.end() - 1
        fetch_content, _ = extract_balanced_parentheses(blade_code, start_pos)
        if fetch_content:
            fetch_content = fetch_content.strip()
            
            # Check if it's multiple parameters: @fetch(url, data, headers)
            if ',' in fetch_content and not fetch_content.startswith('['):
                # Split by comma that's not inside parentheses or quotes
                parts = self._split_fetch_parameters(fetch_content)
                
                if len(parts) >= 1:
                    # Parse URL (first parameter)
                    url_part = parts[0].strip()
                    if url_part.startswith("'") and url_part.endswith("'"):
                        url = f"`{url_part[1:-1]}`"
                    elif url_part.startswith('"') and url_part.endswith('"'):
                        url = f"`{url_part[1:-1]}`"
                    elif '(' in url_part and ')' in url_part:
                        # Function call like route('api.posts')
                        js_url = php_to_js(url_part)
                        url = f"`${{{js_url}}}`"
                    else:
                        url = f"`${{{url_part}}}`"
                    
                    # Parse data (second parameter, optional)
                    data = {}
                    if len(parts) >= 2:
                        data_part = parts[1].strip()
                        if data_part.startswith('[') and data_part.endswith(']'):
                            data = self._parse_php_array_to_js_object(data_part)
                    
                    # Parse headers (third parameter, optional)
                    headers = {}
                    if len(parts) >= 3:
                        headers_part = parts[2].strip()
                        if headers_part.startswith('[') and headers_part.endswith(']'):
                            headers = self._parse_php_array_to_js_object(headers_part)
                    
                    return {
                        'url': url,
                        'method': 'GET',
                        'data': data,
                        'headers': headers
                    }
            
            # Simple URL fetch
            elif fetch_content.startswith("'") and fetch_content.endswith("'"):
                url_content = fetch_content[1:-1]
                return {'url': f"`{url_content}`", 'method': 'GET'}
            elif fetch_content.startswith('"') and fetch_content.endswith('"'):
                url_content = fetch_content[1:-1]
                return {'url': f"`{url_content}`", 'method': 'GET'}
            
            # Function call fetch (e.g., route('web.users'))
            elif '(' in fetch_content and ')' in fetch_content and not fetch_content.startswith('['):
                js_url = php_to_js(fetch_content)
                return {'url': f"`${{{js_url}}}`", 'method': 'GET'}
            
            # Array configuration fetch
            elif fetch_content.startswith('[') and fetch_content.endswith(']'):
                try:
                    # Handle multiple array parameters (e.g., [config], [data], [headers])
                    if ',' in fetch_content and fetch_content.count('[') > 1:
                        # Split by comma that's not inside brackets
                        parts = self._split_fetch_parameters(fetch_content)
                        
                        # Parse first part as main config
                        config = {}
                        if parts:
                            config = self._parse_php_array_to_js_object(parts[0])
                        
                        # Parse additional data parameter (second parameter)
                        if len(parts) >= 2:
                            additional_data = self._parse_php_array_to_js_object(parts[1])
                            if 'data' in config:
                                config['data'].update(additional_data)
                            else:
                                config['data'] = additional_data
                        
                        # Parse additional headers parameter (third parameter)
                        if len(parts) >= 3:
                            additional_headers = self._parse_php_array_to_js_object(parts[2])
                            if 'headers' in config:
                                config['headers'].update(additional_headers)
                            else:
                                config['headers'] = additional_headers
                        
                        # Ensure required fields
                        if 'url' not in config:
                            config['url'] = ''
                        if 'method' not in config:
                            config['method'] = 'GET'
                        if 'data' not in config:
                            config['data'] = {}
                        if 'headers' not in config:
                            config['headers'] = {}
                        
                        # Convert values to template strings if needed
                        if isinstance(config['url'], str) and not config['url'].startswith('`'):
                            config['url'] = f"`{config['url']}`"
                        
                        return config
                    else:
                        # Single array parameter
                        config = self._parse_php_array_to_js_object(fetch_content)
                        
                        # Ensure required fields
                        if 'url' not in config:
                            config['url'] = ''
                        if 'method' not in config:
                            config['method'] = 'GET'
                        if 'data' not in config:
                            config['data'] = {}
                        if 'headers' not in config:
                            config['headers'] = {}
                        
                        # Convert values to template strings if needed
                        if isinstance(config['url'], str) and not config['url'].startswith('`'):
                            config['url'] = f"`{config['url']}`"
                        
                        return config
                except Exception as e:
                    # Fallback to simple URL if parsing fails
                    print(f"Fetch parsing error: {e}")
                    return {'url': "`" + fetch_content + "`", 'method': 'GET'}
                
        return None
    
    def parse_init(self, blade_code):
        """Parse @onInit directives"""
        init_functions = []
        css_content = []
        
        # Find all @onInit blocks - support both @onInit(...) and @onInit...@endonInit formats
        # Find @onInit blocks by finding @onInit( and matching the closing )
        init_matches = []
        start_pattern = re.compile(r'@oninit\s*\(', re.IGNORECASE)
        
        for match in start_pattern.finditer(blade_code):
            start_pos = match.end()
            # Find the matching closing parenthesis
            paren_count = 1
            pos = start_pos
            
            while pos < len(blade_code) and paren_count > 0:
                if blade_code[pos] == '(':
                    paren_count += 1
                elif blade_code[pos] == ')':
                    paren_count -= 1
                pos += 1
            
            if paren_count == 0:
                # Found matching closing parenthesis
                content = blade_code[start_pos:pos-1]
                # Create a mock match object
                class MockMatch:
                    def __init__(self, content):
                        self._content = content
                    def group(self, n):
                        if n == 1:
                            return self._content
                        return None
                
                init_matches.append(MockMatch(content))
        
        for match in init_matches:
            init_content = match.group(1).strip()
            
            # Extract script content from <script> tags
            script_content = []
            in_script = False
            
            # Extract CSS content from <style> tags
            style_matches = re.finditer(r'<style[^>]*>(.*?)</style>', init_content, re.DOTALL | re.IGNORECASE)
            for style_match in style_matches:
                css_content.append(style_match.group(1).strip())
            
            # Check if content has <script> tags
            has_script_tags = '<script' in init_content.lower()
            
            if has_script_tags:
                # Process content with <script> tags
                for line in init_content.split('\n'):
                    if '<script' in line.lower():
                        in_script = True
                        script_start = line.lower().find('<script')
                        script_tag_end = line.find('>', script_start)
                        if script_tag_end != -1:
                            script_content.append(line[script_tag_end + 1:])
                    elif '</script>' in line.lower():
                        in_script = False
                        script_end = line.lower().find('</script>')
                        if script_end > 0:
                            script_content.append(line[:script_end])
                    elif in_script:
                        script_content.append(line)
            else:
                # Process content directly without <script> tags
                script_content.append(init_content)
            
            if script_content:
                init_code = '\n'.join(script_content).strip()
                if init_code:
                    init_functions.append(init_code)
        
        return init_functions, css_content
    
    def _convert_extends_data(self, data_expr):
        """Convert extends data expression"""
        extends_data = convert_php_array_to_json(data_expr)
        extends_data = re.sub(r'(?<!")\$(\w+)(?!")', r'\1', extends_data)
        extends_data = re.sub(r'\[', '{', extends_data)
        extends_data = re.sub(r'\]', '}', extends_data)
        extends_data = re.sub(r'\s*=>\s*', ': ', extends_data)
        extends_data = re.sub(r'\s+\.\s+', ' + ', extends_data)
        return extends_data
    
    def _split_vars_content(self, content):
        """Split vars content respecting parentheses and brackets"""
        parts = []
        current = ''
        paren_count = 0
        bracket_count = 0
        in_quotes = False
        quote_char = ''
        i = 0
        
        while i < len(content):
            char = content[i]
            
            if (char == '"' or char == "'") and not in_quotes:
                in_quotes = True
                quote_char = char
            elif char == quote_char and in_quotes:
                in_quotes = False
                quote_char = ''
            elif not in_quotes:
                if char == '(':
                    paren_count += 1
                elif char == ')':
                    paren_count -= 1
                elif char == '[':
                    bracket_count += 1
                elif char == ']':
                    bracket_count -= 1
                elif char == ',' and paren_count == 0 and bracket_count == 0:
                    parts.append(current.strip())
                    current = ''
                    i += 1
                    continue
            
            current += char
            i += 1
        
        if current.strip():
            parts.append(current.strip())
        
        return parts
    
    def _split_vars_content_improved(self, content):
        """
        Split vars content with improved logic (same as Event directive)
        Handles complex nested arrays and objects properly
        """
        parts = []
        current = ''
        paren_count = 0
        bracket_count = 0
        in_quotes = False
        quote_char = ''
        
        i = 0
        while i < len(content):
            char = content[i]
            
            if not in_quotes:
                if char in ['"', "'"]:
                    in_quotes = True
                    quote_char = char
                    current += char
                elif char == '(':
                    paren_count += 1
                    current += char
                elif char == ')':
                    paren_count -= 1
                    current += char
                elif char == '[':
                    bracket_count += 1
                    current += char
                elif char == ']':
                    bracket_count -= 1
                    current += char
                elif char == ',' and paren_count == 0 and bracket_count == 0:
                    # Comma at level 0 - variable separator
                    parts.append(current.strip())
                    current = ''
                    i += 1
                    continue
                else:
                    current += char
            else:
                current += char
                if char == quote_char and (i == 0 or content[i-1] != '\\'):
                    in_quotes = False
                    quote_char = ''
            
            i += 1
        
        # Add last variable
        if current.strip():
            parts.append(current.strip())
        
        return parts
    
    def _split_vars_content_fixed(self, content):
        """
        Split vars content with fixed logic - properly handles nested arrays
        """
        parts = []
        current = ''
        paren_count = 0
        bracket_count = 0
        in_quotes = False
        quote_char = ''
        
        i = 0
        while i < len(content):
            char = content[i]
            
            if not in_quotes:
                if char in ['"', "'"]:
                    in_quotes = True
                    quote_char = char
                    current += char
                elif char == '(':
                    paren_count += 1
                    current += char
                elif char == ')':
                    paren_count -= 1
                    current += char
                elif char == '[':
                    bracket_count += 1
                    current += char
                elif char == ']':
                    bracket_count -= 1
                    current += char
                elif char == ',' and paren_count == 0 and bracket_count == 0:
                    # Comma at level 0 - variable separator
                    parts.append(current.strip())
                    current = ''
                    i += 1
                    continue
                else:
                    current += char
            else:
                current += char
                if char == quote_char and (i == 0 or content[i-1] != '\\'):
                    in_quotes = False
                    quote_char = ''
            
            i += 1
        
        # Add last variable
        if current.strip():
            parts.append(current.strip())
        
        return parts
    
    def _split_vars_content_correct(self, content):
        """
        Split vars content with correct logic - handles nested arrays properly
        """
        parts = []
        current = ''
        paren_count = 0
        bracket_count = 0
        brace_count = 0
        in_quotes = False
        quote_char = ''
        
        i = 0
        while i < len(content):
            char = content[i]
            
            if not in_quotes:
                if char in ['"', "'"]:
                    in_quotes = True
                    quote_char = char
                    current += char
                elif char == '(':
                    paren_count += 1
                    current += char
                elif char == ')':
                    paren_count -= 1
                    current += char
                elif char == '[':
                    bracket_count += 1
                    current += char
                elif char == ']':
                    bracket_count -= 1
                    current += char
                elif char == '{':
                    brace_count += 1
                    current += char
                elif char == '}':
                    brace_count -= 1
                    current += char
                elif char == ',' and paren_count == 0 and bracket_count == 0 and brace_count == 0:
                    # Comma at level 0 - variable separator
                    parts.append(current.strip())
                    current = ''
                    i += 1
                    continue
                else:
                    current += char
            else:
                current += char
                if char == quote_char and (i == 0 or content[i-1] != '\\'):
                    in_quotes = False
                    quote_char = ''
            
            i += 1
        
        # Add last variable
        if current.strip():
            parts.append(current.strip())
        
        return parts
    
    def _convert_php_to_js(self, php_value):
        """Convert PHP array syntax to JavaScript object/array syntax using php -r"""
        php_value = php_value.strip()
        
        # Handle string literals
        if (php_value.startswith("'") and php_value.endswith("'")) or \
           (php_value.startswith('"') and php_value.endswith('"')):
            return php_value
        
        # Handle numbers
        if php_value.isdigit() or (php_value.startswith('-') and php_value[1:].isdigit()):
            return php_value
        
        # Handle booleans
        if php_value.lower() in ['true', 'false']:
            return php_value.lower()
        
        # Handle null
        if php_value.lower() == 'null':
            return 'null'
        
        # Handle arrays - sử dụng php -r để convert
        if php_value.startswith('[') and php_value.endswith(']'):
            # Sử dụng php -r để convert array
            json_result = convert_php_array_with_php_r(php_value)
            if json_result is not None:
                return json_result
            
            # Fallback to old method if php -r fails
            return self._convert_php_array_legacy(php_value)
        
        # Default: return as is
        return php_value
    
    def _convert_php_array_legacy(self, php_value):
        """Legacy method for converting PHP arrays (fallback)"""
        # Remove outer brackets
        inner_content = php_value[1:-1].strip()
        if not inner_content:
            return '[]'  # Empty array, not object
        
        # Check if this is an associative array (has =>) or indexed array
        has_arrow_operator = '=>' in inner_content
        
        if has_arrow_operator:
            # Associative array - convert to JavaScript object
            js_content = inner_content
            # Replace => with :
            js_content = re.sub(r'\s*=>\s*', ': ', js_content)
            # Replace single quotes with double quotes for keys
            js_content = re.sub(r"'([^']+)'\s*:", r'"\1":', js_content)
            # Replace single quotes with double quotes for string values
            js_content = re.sub(r":\s*'([^']+)'", r': "\1"', js_content)
            
            return '{' + js_content + '}'
        else:
            # Indexed array - keep as JavaScript array
            js_content = inner_content
            # Replace single quotes with double quotes for string values
            js_content = re.sub(r"'([^']+)'", r'"\1"', js_content)
            
            return '[' + js_content + ']'
    
    def _find_first_equals(self, var):
        """Find first = that's not inside quotes or brackets"""
        paren_count = 0
        bracket_count = 0
        in_quotes = False
        quote_char = ''
        
        for i, char in enumerate(var):
            if (char == '"' or char == "'") and not in_quotes:
                in_quotes = True
                quote_char = char
            elif char == quote_char and in_quotes:
                in_quotes = False
                quote_char = ''
            elif not in_quotes:
                if char == '(':
                    paren_count += 1
                elif char == ')':
                    paren_count -= 1
                elif char == '[':
                    bracket_count += 1
                elif char == ']':
                    bracket_count -= 1
                elif char == '=' and paren_count == 0 and bracket_count == 0:
                    return i
        
        return -1
    
    def parse_register(self, blade_code):
        """Parse @register directive hoặc aliases (@setup, @script)
        
        NOTE: This method should NOT find @register inside @verbatim blocks
        because @verbatim blocks are already replaced with placeholders in main_compiler.py
        before this method is called. This ensures @verbatim has absolute priority.
        """
        # Skip verbatim placeholders to ensure we don't process anything inside them
        # (Even though they should already be replaced, this is a safety check)
        blade_code_filtered = re.sub(r'__VERBATIM_BLOCK_\d+__', '', blade_code)
        
        # Tìm @register trước (có thể có hoặc không có parameters)
        register_match = re.search(r'@register(?:\s*\([^)]*\))?(.*?)@endregister', blade_code_filtered, re.DOTALL | re.IGNORECASE)
        if register_match:
            return register_match.group(1).strip()
            
        # Tìm @setup (alias của @register, có thể có hoặc không có parameters)
        setup_match = re.search(r'@setup(?:\s*\([^)]*\))?(.*?)@endsetup', blade_code_filtered, re.DOTALL | re.IGNORECASE)
        if setup_match:
            return setup_match.group(1).strip()
            
        # Tìm @script (xử lý như @register)
        script_match = re.search(r'@script\s*(?:\([^)]*\))?(.*?)@endscript', blade_code_filtered, re.DOTALL | re.IGNORECASE)
        if script_match:
            return script_match.group(1).strip()
            
        return None

    def parse_view_type(self, blade_code):
        """Parse @viewType/@viewtype directive"""
        # Match both @viewType and @viewtype (case insensitive)
        viewtype_match = re.search(r'@viewtype\s*\(', blade_code, re.IGNORECASE)
        if not viewtype_match:
            return None

        start_pos = viewtype_match.end() - 1
        viewtype_content, _ = extract_balanced_parentheses(blade_code, start_pos)
        if not viewtype_content:
            return None

        viewtype_content = viewtype_content.strip()
        
        # Extract the parameter value
        param_value = self._extract_viewtype_parameter(viewtype_content)
        if not param_value:
            return None

        # Normalize and determine view type
        view_type = self._normalize_view_type(param_value)
        
        return {
            'viewType': view_type,
            'originalValue': param_value
        }

    def _extract_viewtype_parameter(self, content):
        """Extract parameter value from @viewType directive"""
        content = content.strip()
        
        # Handle string literals (single or double quotes)
        if (content.startswith("'") and content.endswith("'")) or (content.startswith('"') and content.endswith('"')):
            return content[1:-1]  # Remove quotes
        
        # Handle variables or expressions - convert to string
        if content.startswith('$'):
            # PHP variable - convert to JS
            return php_to_js(content)
        
        # Handle function calls
        if '(' in content and ')' in content:
            return php_to_js(content)
        
        # Handle arrays or other complex expressions
        if content.startswith('[') or content.startswith('{'):
            try:
                # Try to parse as JSON-like structure
                parsed = json.loads(content)
                if isinstance(parsed, (list, dict)):
                    return str(parsed)
            except:
                pass
        
        # Handle numbers, booleans, etc.
        if content.lower() in ['true', 'false', 'null']:
            return content.lower()
        
        # Try to parse as number
        try:
            float(content)
            return content
        except:
            pass
        
        # Default: treat as string
        return content

    def _normalize_view_type(self, value):
        """Normalize view type value to standard types"""
        if not value:
            return 'view'
        
        # Convert to lowercase string for comparison
        value_str = str(value).lower().strip()
        
        # HTML Document types
        html_doc_types = [
            'html', 'document', 'html-document', 'htmldocument',
            'fullpage', 'finalhtml', 'webpage'
        ]
        if value_str in html_doc_types:
            return 'html-document'
        
        # Layout types
        layout_types = [
            'layout', 'view-layout', 'view/layout'
        ]
        if value_str in layout_types:
            return 'layout'
        
        # View types
        view_types = [
            'view', 'page', 'viewpage', 'view-page', 'view/page'
        ]
        if value_str in view_types:
            return 'view'
        
        # Template types
        template_types = [
            'template', 'view-template', 'temp', 'tpl', 'viewtpl', 'view/template'
        ]
        if value_str in template_types:
            return 'template'
        
        # Component types
        component_types = [
            'component', 'compunent'  # Handle typo
        ]
        if value_str in component_types:
            return 'component'
        
        # Default fallback
        return 'view'

    def _validate_block_directives(self, blade_code):
        """Validate that @block directives have matching @endblock/@endBlock directives"""
        # Remove @verbatim blocks to avoid checking directives inside them
        blade_code_filtered = self._remove_verbatim_blocks(blade_code)
        
        # Check for nested blocks and validate order
        # We'll use a simple stack-based approach to check nesting
        lines = blade_code_filtered.split('\n')
        stack = []
        
        for line_num, line in enumerate(lines, 1):
            # Check for @block
            block_match = re.search(r'@block\s*\(', line, re.IGNORECASE)
            if block_match:
                # Extract block name if possible
                block_name_match = re.search(r'@block\s*\(\s*[\'"]([^\'"]*)[\'"]', line, re.IGNORECASE)
                block_name = block_name_match.group(1) if block_name_match else f"block_{len(stack)}"
                stack.append(('block', block_name, line_num))
            
            # Check for @endblock/@endBlock
            endblock_match = re.search(r'@endblock\b|@endBlock\b', line, re.IGNORECASE)
            if endblock_match:
                if not stack:
                    error_msg = f"Lỗi tại dòng {line_num}: Tìm thấy @endblock/@endBlock nhưng không có @block tương ứng"
                    raise ValueError(error_msg)
                stack.pop()
        
        # Check if there are unclosed blocks
        if stack:
            unclosed_blocks = [f"'{name}' (dòng {line_num})" for _, name, line_num in stack]
            error_msg = f"Lỗi: Có {len(stack)} block chưa được đóng: {', '.join(unclosed_blocks)}"
            raise ValueError(error_msg)
        
        return True

    def parse_block_directives(self, blade_code):
        """Parse @block directive - now handled as section in template processor"""
        # Validate block directives before processing
        self._validate_block_directives(blade_code)
        # @block is now handled in template_processor.py as a section
        return blade_code

    def parse_endblock_directives(self, blade_code):
        """Parse @endblock/@endBlock directive - now handled as section in template processor"""
        # Validation is done in parse_block_directives
        # @endblock is now handled in template_processor.py as a section
        return blade_code

    def parse_useblock_directives(self, blade_code):
        """Parse @useBlock/@useblock directive with optional defaultValue"""
        # Pattern to match @useBlock/@useblock with optional defaultValue
        pattern = r'@(useBlock|useblock)\s*\(\s*([^,)]+)(?:\s*,\s*([^)]+))?\s*\)'
        
        def replace_useblock(match):
            name_expr = match.group(2).strip()
            default_expr = match.group(3).strip() if match.group(3) else None
            
            # Convert name to JavaScript
            js_name = self._convert_php_to_js(name_expr)
            # Remove $ prefix from variables
            js_name = re.sub(r'\$(\w+)', r'\1', js_name)
            
            # Convert defaultValue to JavaScript if provided
            if default_expr:
                js_default = self._convert_php_to_js(default_expr)
                # Remove $ prefix from variables
                js_default = re.sub(r'\$(\w+)', r'\1', js_default)
                return f"${{this.useBlock({js_name}, {js_default})}}"
            else:
                return f"${{this.useBlock({js_name})}}"
        
        return re.sub(pattern, replace_useblock, blade_code)

    def parse_onblock_directives(self, blade_code):
        """Parse @onBlock/@onblock/@onBlockChange directives with subscribeBlock"""
        # Pattern to match @onBlock/@onblock/@onBlockChange with parameters
        pattern = r'@(onBlock|onblock|onBlockChange)\s*\(\s*([^)]+)\s*\)'
        
        def replace_onblock(match):
            directive = match.group(1)
            params_expr = match.group(2).strip()
            
            # Parse parameters - could be string, variable, or array
            if params_expr.startswith('[') and params_expr.endswith(']'):
                # Array syntax: ['#children' => 'document.body', 'title' => 'block-title']
                # Convert PHP array to JavaScript object
                from php_converter import convert_php_array_to_json
                js_params = convert_php_array_to_json(params_expr)
                # Format with proper spacing
                js_params = re.sub(r'":', r'": ', js_params)
                js_params = re.sub(r',"', r', "', js_params)
                return f"${{this.subscribeBlock({js_params})}}"
            else:
                # Single parameter: 'title' or $blockName
                js_param = self._convert_php_to_js(params_expr)
                # Remove $ prefix from variables
                js_param = re.sub(r'\$(\w+)', r'\1', js_param)
                return f"${{this.subscribeBlock({js_param})}}"
        
        return re.sub(pattern, replace_onblock, blade_code)

    def _parse_block_expression(self, expression):
        """Parse block expression to extract name and attributes"""
        # Find first comma outside quotes and brackets
        comma_pos = self._find_outer_comma(expression)
        
        if comma_pos == -1:
            # Only block name, no attributes
            name = expression.strip()
            return {'name': self._convert_php_to_js(name), 'attributes': None}
        
        # Split name and attributes
        name_part = expression[:comma_pos].strip()
        attributes_part = expression[comma_pos + 1:].strip()
        
        name = self._convert_php_to_js(name_part)
        attributes = self._convert_block_attributes(attributes_part)
        
        return {'name': name, 'attributes': attributes}

    def _convert_block_attributes(self, attributes_expr):
        """Convert block attributes to JavaScript"""
        attributes_expr = attributes_expr.strip()
        
        # Check if it's array syntax
        if attributes_expr.startswith('[') and attributes_expr.endswith(']'):
            # Convert PHP array to JavaScript object
            return convert_php_array_to_json(attributes_expr)
        else:
            # Convert PHP expression to JavaScript
            return self._convert_php_to_js(attributes_expr)

    def _find_outer_comma(self, expression):
        """Find first comma outside quotes and brackets"""
        in_quotes = False
        quote_char = None
        bracket_count = 0
        paren_count = 0
        
        for i, char in enumerate(expression):
            if not in_quotes:
                if char in ['"', "'"]:
                    in_quotes = True
                    quote_char = char
                elif char == '[':
                    bracket_count += 1
                elif char == ']':
                    bracket_count -= 1
                elif char == '(':
                    paren_count += 1
                elif char == ')':
                    paren_count -= 1
                elif char == ',' and bracket_count == 0 and paren_count == 0:
                    return i
            else:
                if char == quote_char and (i == 0 or expression[i-1] != '\\'):
                    in_quotes = False
                    quote_char = None
        
        return -1

    def _is_simple_string_literal(self, view_expr):
        """Check if view_expr is a simple string literal without variables or function calls"""
        view_expr = view_expr.strip()
        
        # Must start and end with quotes
        if not ((view_expr.startswith('"') and view_expr.endswith('"')) or 
                (view_expr.startswith("'") and view_expr.endswith("'"))):
            return False
        
        # Check for variables ($) or function calls (parentheses)
        if '$' in view_expr or '(' in view_expr:
            return False
            
        return True

    def _convert_extends_expression(self, view_expr):
        """Convert @extends expression to JavaScript"""
        view_expr = view_expr.strip()
        
        # Handle string with variables: "theme.layout" -> `theme+'.layout'`
        if view_expr.startswith('"') and view_expr.endswith('"'):
            inner_content = view_expr[1:-1]
            # Convert PHP string concatenation to JavaScript
            js_expr = self._convert_php_string_concat(inner_content)
            return f"`{js_expr}`"
        
        # Handle single quotes: 'theme.layout' -> `theme+'.layout'`
        elif view_expr.startswith("'") and view_expr.endswith("'"):
            inner_content = view_expr[1:-1]
            js_expr = self._convert_php_string_concat(inner_content)
            return f"`{js_expr}`"
        
        # Handle function calls first: test('.def')
        elif '(' in view_expr and ')' in view_expr:
            # Function call - use direct conversion
            return php_to_js(view_expr)
        
        # Handle variables and concatenation: $theme.'.layout', $abc.'.def'.$Abxy
        elif '.' in view_expr and ('$' in view_expr or '"' in view_expr or "'" in view_expr):
            # Has concatenation - convert to template literal with App.Helper.execute
            js_expr = self._convert_php_string_concat(view_expr)
            return f"App.Helper.execute(() => `{js_expr}`)"
        
        # Handle simple variables: $theme
        else:
            # Check if it contains undefined variables (starts with $ and not in declared vars)
            if view_expr.startswith('$') and not self._is_variable_declared(view_expr):
                return f"App.Helper.execute(() => {php_to_js(view_expr)})"
            else:
                return php_to_js(view_expr)

    def _convert_php_string_concat(self, content):
        """Convert PHP string concatenation to JavaScript template literal"""
        # Use regex to find all parts separated by . outside quotes
        import re
        
        # Pattern to match: variable, string literal, or function call
        pattern = r'(\$[a-zA-Z_][a-zA-Z0-9_]*|"[^"]*"|\'[^\']*\'|[a-zA-Z_][a-zA-Z0-9_]*\([^)]*\))'
        parts = re.findall(pattern, content)
        
        if not parts:
            # Fallback: try to split by . and process each part
            parts = content.split('.')
        
        # Convert parts to JavaScript
        js_parts = []
        for part in parts:
            part = part.strip()
            if part.startswith('"') and part.endswith('"'):
                # String literal
                js_parts.append(f'"{part[1:-1]}"')
            elif part.startswith("'") and part.endswith("'"):
                # String literal
                js_parts.append(f"'{part[1:-1]}'")
            elif part.startswith('$'):
                # Variable
                var_name = part[1:]
                js_parts.append(f"{var_name}")
            elif '(' in part and ')' in part:
                # Function call
                js_parts.append(f"{php_to_js(part)}")
            else:
                # Other expression
                js_parts.append(f"{php_to_js(part)}")
        
        # Wrap the entire expression in template literal
        return f"${{{'+'.join(js_parts)}}}"

    def _is_variable_declared(self, view_expr):
        """Check if a variable is declared in the template"""
        # Extract variable name from $variable
        if view_expr.startswith('$'):
            var_name = view_expr[1:].split('.')[0]  # Get first part before any dots
            
            # Check if variable is declared in @vars, @let, @const, or @useState
            # This is a simple check - in a real implementation, you'd need to track
            # all declared variables from the template
            declared_vars = self._get_declared_variables()
            return var_name in declared_vars
        
        return True  # Non-variable expressions are considered "declared"

    def _get_declared_variables(self):
        """Get list of declared variables from the template"""
        # This is a simplified version - in practice, you'd need to track
        # variables declared in @vars, @let, @const, @useState directives
        # For now, return common variables that are typically declared
        return {
            'user', 'userState', 'setUserState', 'isEditModalOpen', 'setIsEditModalOpen',
            'posts', 'setPosts', 'loading', 'setLoading', 'data', 'config'
        }
