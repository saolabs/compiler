"""
Processors cho các Blade directives khác nhau
"""

from config import JS_FUNCTION_PREFIX, HTML_ATTR_PREFIX
from php_converter import php_to_js, convert_php_array_to_json
from utils import extract_balanced_parentheses
import re

class DirectiveProcessor:
    def __init__(self):
        self.loop_counter = 0
        self.init_functions = []
        self.stack = []
        
    
    
    
    def process_auth_directive(self, line):
        """Process @auth directive"""
        if line.startswith('@auth'):
            return f'${{{JS_FUNCTION_PREFIX}.execute(() => {{\n    if({JS_FUNCTION_PREFIX}.isAuth()){{\n        return `'
        elif line.startswith('@guest'):
            return f'${{{JS_FUNCTION_PREFIX}.execute(() => {{\n    if(!{JS_FUNCTION_PREFIX}.isAuth()){{\n        return `'
        return None
    
    def process_endauth_directive(self, line):
        """Process @endauth/@endguest directive"""
        if line.startswith('@endauth') or line.startswith('@endguest'):
            return '`\n    }\n})}'
        return None
    
    def process_can_directive(self, line):
        """Process @can/@cannot directive"""
        if line.startswith('@cannot'):
            # Extract permission from @cannot('permission')
            cannot_match = re.match(r'@cannot\s*\(\s*[\'"]([^\'"]*)[\'"]\s*\)', line)
            if cannot_match:
                permission = cannot_match.group(1)
                return f'${{{JS_FUNCTION_PREFIX}.execute(() => {{\nif({JS_FUNCTION_PREFIX}.cannot(\'{permission}\')){{\nreturn `'
        elif line.startswith('@can'):
            # Extract permission from @can('permission')
            can_match = re.match(r'@can\s*\(\s*[\'"]([^\'"]*)[\'"]\s*\)', line)
            if can_match:
                permission = can_match.group(1)
                return f'${{{JS_FUNCTION_PREFIX}.execute(() => {{\nif({JS_FUNCTION_PREFIX}.can(\'{permission}\')){{\nreturn `'
        return None
    
    def process_endcan_directive(self, line):
        """Process @endcan/@endcannot directive"""
        if line.startswith('@endcan') or line.startswith('@endcannot'):
            return '`\n    }\n})}'
        return None
    
    def process_csrf_directive(self, line):
        """Process @csrf directive"""
        if line.startswith('@csrf'):
            return f'<input type="hidden" name="_token" value="${{{JS_FUNCTION_PREFIX}.getCsrfToken()}}">'
        return None
    
    def process_method_directive(self, line):
        """Process @method directive"""
        if line.startswith('@method'):
            # Extract method from @method('PUT')
            method_match = re.match(r'@method\s*\(\s*[\'"]([^\'"]*)[\'"]\s*\)', line)
            if method_match:
                method = method_match.group(1).upper()
                return f'<input type="hidden" name="_method" value="{method}">'
        return None
    
    def process_error_directive(self, line):
        """Process @error directive"""
        if line.startswith('@error'):
            # Extract field from @error('field')
            error_match = re.match(r'@error\s*\(\s*[\'"]([^\'"]*)[\'"]\s*\)', line)
            if error_match:
                field = error_match.group(1)
                return f'${{{JS_FUNCTION_PREFIX}.execute(() => {{\nif({JS_FUNCTION_PREFIX}.hasError(\'{field}\')){{\nreturn `'
        return None
    
    def process_enderror_directive(self, line):
        """Process @enderror directive"""
        if line.startswith('@enderror'):
            return '`\n    }\n})}'
        return None
    
    def process_hassection_directive(self, line):
        """Process @hasSection directive"""
        if line.startswith('@hasSection'):
            # Extract section from @hasSection('section')
            section_match = re.match(r'@hasSection\s*\(\s*[\'"]([^\'"]*)[\'"]\s*\)', line)
            if section_match:
                section = section_match.group(1)
                return f'${{{JS_FUNCTION_PREFIX}.execute(() => {{\nif({JS_FUNCTION_PREFIX}.hasSection(\'{section}\')){{\nreturn `'
        return None
    
    def process_endhassection_directive(self, line):
        """Process @endhassection directive"""
        if line.startswith('@endhassection'):
            return '`\n    }\n})}'
        return None
    
    def process_empty_directive(self, line, stack, output):
        """Process @empty directive"""
        if line.startswith('@empty'):
            # Extract variable from @empty($variable)
            empty_match = re.match(r'@empty\s*\(\s*\$?(\w+)\s*\)', line)
            if empty_match:
                variable = empty_match.group(1)
                result = f'${{{JS_FUNCTION_PREFIX}.execute(() => {{\nif({JS_FUNCTION_PREFIX}.isEmpty({variable})){{\nreturn `'
                output.append(result)
                stack.append(('empty', len(output)))
                return True
        return False
    
    def process_isset_directive(self, line, stack, output):
        """Process @isset directive"""
        if line.startswith('@isset'):
            # Extract variable from @isset($variable)
            isset_match = re.match(r'@isset\s*\(\s*\$?(\w+)\s*\)', line)
            if isset_match:
                variable = isset_match.group(1)
                result = f'${{{JS_FUNCTION_PREFIX}.execute(() => {{\nif({JS_FUNCTION_PREFIX}.isSet({variable})){{\nreturn `'
                output.append(result)
                stack.append(('isset', len(output)))
                return True
        return False
    
    def process_endempty_directive(self, stack, output):
        """Process @endempty directive"""
        if stack and stack[-1][0] == 'empty':
            stack.pop()
            result = "`;\n    }\n})}"
            output.append(result)
            return True
        return False
    
    def process_endisset_directive(self, stack, output):
        """Process @endisset directive"""
        if stack and stack[-1][0] == 'isset':
            stack.pop()
            result = "`;\n    }\n})}"
            output.append(result)
            return True
        return False
    
    def process_unless_directive(self, line):
        """Process @unless directive"""
        if line.startswith('@unless'):
            # Extract condition from @unless($condition)
            unless_match = re.match(r'@unless\s*\(\s*(.*?)\s*\)', line)
            if unless_match:
                condition = php_to_js(unless_match.group(1))
                return f'${{{JS_FUNCTION_PREFIX}.execute(() => {{\nif(!({condition})){{\nreturn `'
        return None
    
    def process_endunless_directive(self, line):
        """Process @endunless directive"""
        if line.startswith('@endunless'):
            return '`\n    }\n})}'
        return None
    
    def process_php_directive(self, line, stack, output):
        """Process @php directive"""
        if line.startswith('@php'):
            result = f'${{{JS_FUNCTION_PREFIX}.execute(() => {{'
            output.append(result)
            stack.append(('php', len(output)))
            return True
        return False
    
    def process_endphp_directive(self, stack, output):
        """Process @endphp directive"""
        if stack and stack[-1][0] == 'php':
            stack.pop()
            result = '})}'
            output.append(result)
            return True
        return False
    
    def process_json_directive(self, line):
        """Process @json directive
        Handles:
        - @json(['test' => true]) -> ${JSON.stringify({"test":true})}
        - @json(['test'=>$state]) -> ${this.__output(['state'], () => JSON.stringify({"test":state}))}
        """
        if not line.startswith('@json'):
            return None

        # Extract balanced parentheses content
        m = re.match(r'@json\s*\(', line)
        if not m:
            return None
        
        start_pos = m.end() - 1
        from utils import extract_balanced_parentheses
        content, end_pos = extract_balanced_parentheses(line, start_pos)
        
        if content is None:
            return None

        expr = content.strip()
        
        # Parse variable names from the expression
        # Similar to @out directive - detect $variables inside the array
        vars_set = []
        in_single = False
        in_double = False
        escape = False
        i = 0
        
        while i < len(expr):
            ch = expr[i]
            if escape:
                escape = False
                i += 1
                continue
            if ch == '\\':
                escape = True
                i += 1
                continue
            if in_single:
                if ch == "'":
                    in_single = False
                i += 1
                continue
            if in_double:
                if ch == '"':
                    in_double = False
                    i += 1
                    continue
                # inside double quotes, $var is valid
                if ch == '$':
                    j = i + 1
                    if j < len(expr) and re.match(r'[a-zA-Z_]', expr[j]):
                        start = j
                        j += 1
                        while j < len(expr) and re.match(r'[a-zA-Z0-9_]', expr[j]):
                            j += 1
                        name = expr[start:j]
                        if name not in vars_set:
                            vars_set.append(name)
                        i = j
                        continue
                i += 1
                continue

            # not in any quote
            if ch == "'":
                in_single = True
                i += 1
                continue
            if ch == '"':
                in_double = True
                i += 1
                continue
            if ch == '$':
                j = i + 1
                if j < len(expr) and re.match(r'[a-zA-Z_]', expr[j]):
                    start = j
                    j += 1
                    while j < len(expr) and re.match(r'[a-zA-Z0-9_]', expr[j]):
                        j += 1
                    name = expr[start:j]
                    if name not in vars_set:
                        vars_set.append(name)
                    i = j
                    continue
            i += 1

        # Smart conversion for PHP array with variables
        from php_converter import php_to_js
        
        # Replace variables with placeholders first
        expr_for_conv = expr
        var_placeholders = {}
        for i, var_name in enumerate(vars_set):
            placeholder = f'__VAR_{i}__'
            var_placeholders[placeholder] = var_name
            # Replace $var with placeholder string
            expr_for_conv = expr_for_conv.replace(f'${var_name}', f'"{placeholder}"')
        
        # Convert PHP array to JSON
        try:
            json_str = convert_php_array_to_json(expr_for_conv)
        except Exception:
            json_str = expr_for_conv
        
        # Replace placeholders back with JavaScript variable references
        for placeholder, var_name in var_placeholders.items():
            json_str = json_str.replace(f'"{placeholder}"', var_name)
        
        # Convert any remaining PHP syntax to JS (like true/false)
        try:
            js_expr = php_to_js(json_str)
        except Exception:
            js_expr = json_str

        # Build the output
        if vars_set:
            # Has variables - use __output with subscription
            subscribe = ','.join([f"'{v}'" for v in vars_set])
            subscribe_js = f'[{subscribe}]'
            return '${' + f"this.__output({subscribe_js}, () => JSON.stringify({js_expr}))" + '}'
        else:
            # No variables - direct JSON.stringify
            return '${' + f"JSON.stringify({js_expr})" + '}'
    
    def process_lang_directive(self, line):
        """Process @lang directive"""
        if line.startswith('@lang'):
            # Extract key from @lang('key')
            lang_match = re.match(r'@lang\s*\(\s*[\'"]([^\'"]*)[\'"]\s*\)', line)
            if lang_match:
                key = lang_match.group(1)
                return f'${{{JS_FUNCTION_PREFIX}.lang(\'{key}\')}}'
        return None
    
    def process_choice_directive(self, line):
        """Process @choice directive"""
        if line.startswith('@choice'):
            # Extract key and count from @choice('key', count)
            choice_match = re.match(r'@choice\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*(\d+)\s*\)', line)
            if choice_match:
                key = choice_match.group(1)
                count = choice_match.group(2)
                return f'${{{JS_FUNCTION_PREFIX}.choice(\'{key}\', {count})}}'
        return None

    def process_exec_directive(self, line):
        """Process @exec directive"""
        if not line.startswith('@exec'):
            return None

        # Extract balanced parentheses content
        m = re.match(r'@exec\s*\(', line)
        if not m:
            return None
        start_pos = m.end() - 1
        from utils import extract_balanced_parentheses
        content, end_pos = extract_balanced_parentheses(line, start_pos)
        
        if content is None:
            return None

        # Convert PHP expression to JavaScript
        from php_converter import php_to_js
        try:
            js_expr = php_to_js(content)
        except Exception:
            js_expr = content

        # Produce string like: ${this.__execute(() => {code;})}
        return '${' + JS_FUNCTION_PREFIX + '.execute(() => {' + js_expr + ';})}'

    def process_out_directive(self, line):
        """Process @out directive
        Convert @out(<php expression>) to ${this.__output([vars], () => <js expression>)}
        """
        if not line.startswith('@out'):
            return None

        # Extract balanced parentheses content
        m = re.match(r'@out\s*\(', line)
        if not m:
            return None
        start_pos = m.end() - 1
        from utils import extract_balanced_parentheses
        content, end_pos = extract_balanced_parentheses(line, start_pos)
        if content is None:
            return None

        expr = content.strip()

        # Parse variable names similar to PHP implementation: ignore $ inside single-quoted strings
        vars_set = []
        in_single = False
        in_double = False
        escape = False
        i = 0
        while i < len(expr):
            ch = expr[i]
            if escape:
                escape = False
                i += 1
                continue
            if ch == '\\':
                escape = True
                i += 1
                continue
            if in_single:
                if ch == "'":
                    in_single = False
                i += 1
                continue
            if in_double:
                if ch == '"':
                    in_double = False
                    i += 1
                    continue
                # inside double, $var is valid
                if ch == '$':
                    j = i + 1
                    if j < len(expr) and re.match(r'[a-zA-Z_]', expr[j]):
                        start = j
                        j += 1
                        while j < len(expr) and re.match(r'[a-zA-Z0-9_]', expr[j]):
                            j += 1
                        name = expr[start:j]
                        if name not in vars_set:
                            vars_set.append(name)
                        i = j
                        continue
                i += 1
                continue

            # not in any quote
            if ch == "'":
                in_single = True
                i += 1
                continue
            if ch == '"':
                in_double = True
                i += 1
                continue
            if ch == '$':
                j = i + 1
                if j < len(expr) and re.match(r'[a-zA-Z_]', expr[j]):
                    start = j
                    j += 1
                    while j < len(expr) and re.match(r'[a-zA-Z0-9_]', expr[j]):
                        j += 1
                    name = expr[start:j]
                    if name not in vars_set:
                        vars_set.append(name)
                    i = j
                    continue
            i += 1

        # Convert PHP expression to JavaScript
        from php_converter import php_to_js
        try:
            js_expr = php_to_js(expr)
        except Exception:
            js_expr = expr

        # Build subscribe array
        if vars_set:
            subscribe = ','.join([f"'{v}'" for v in vars_set])
            subscribe_js = f'[{subscribe}]'
        else:
            subscribe_js = '[]'

        # Return the output wrapper using this.__output
        # Produce string like: ${this.__output(['a','b'], () => (expr))}
        return '${' + "this.__output(" + subscribe_js + ", () => (" + js_expr + "))}"
    
    def process_register_directive(self, line, stack, output):
        """Process @register directive - chỉ để đánh dấu, không tạo output"""
        if line.startswith('@register'):
            # Chỉ đánh dấu để parser biết bắt đầu @register block
            stack.append(('register', len(output)))
            return True
        return False
    
    def process_endregister_directive(self, stack, output):
        """Process @endregister directive - chỉ để đánh dấu, không tạo output"""
        if stack and stack[-1][0] == 'register':
            stack.pop()
            # Không tạo output, chỉ đóng register block
            return True
        return False
    
    def process_wrapper_directive(self, line, stack, output):
        """Process @wrapper/@view directive"""
        line_lower = line.lower()
        if line_lower.startswith('@wrapper') or line_lower.startswith('@view'):
            # Parse parameters từ @wrapper(...) or @view(...)
            params_match = re.search(r'@(?:wrapper|view)\s*\((.*)\)', line, re.IGNORECASE)
            if params_match:
                params_str = params_match.group(1).strip()
                if not params_str:
                    # Không có tham số
                    arg1 = "'div'"
                    arg2 = '{}'
                else:
                    # Parse tham số
                    params = self._parse_wrapper_params(params_str)
                    if len(params) == 1:
                        arg1 = params[0]  # Giữ nguyên để convert sau
                        arg2 = '{}'
                    else:
                        arg1 = params[0]  # Giữ nguyên để convert sau
                        # Parse arg2 để lấy phần sau dấu =
                        arg2_str = params[1]
                        if '=' in arg2_str and not arg2_str.strip().startswith('['):
                            # Chỉ split nếu không phải array syntax
                            arg2 = arg2_str.split('=', 1)[1].strip()
                        else:
                            arg2 = arg2_str
            else:
                # Không có dấu ngoặc
                arg1 = "'div'"
                arg2 = '{}'
            
            # Convert PHP to JavaScript
            from php_converter import php_to_js
            arg1_js = php_to_js(arg1)
            arg2_js = php_to_js(arg2)
            
            # Tạo output với function call
            wrapper_output = '${' + JS_FUNCTION_PREFIX + '.startWrapper(' + arg1_js + ', ' + arg2_js + ', __VIEW_ID__)}'
            
            stack.append(('wrapper', len(output)))
            return wrapper_output
        return False
    
    def process_endwrapper_directive(self, stack, output):
        """Process @endwrapper directive"""
        if stack and stack[-1][0] == 'wrapper':
            stack.pop()
            return '${' + JS_FUNCTION_PREFIX + '.endWrapper(__VIEW_ID__)}'
        return False
    
    def _parse_wrapper_params(self, params_str):
        """Parse wrapper parameters với support cho function calls, nested functions, và strings"""
        params = []
        current = ''
        bracket_count = 0
        paren_count = 0
        in_string = False
        string_char = ''
        i = 0
        
        while i < len(params_str):
            char = params_str[i]
            
            if not in_string:
                if char in ['"', "'"]:
                    in_string = True
                    string_char = char
                    current += char
                elif char == '[':
                    bracket_count += 1
                    current += char
                elif char == ']':
                    bracket_count -= 1
                    current += char
                elif char == '(':
                    paren_count += 1
                    current += char
                elif char == ')':
                    paren_count -= 1
                    current += char
                elif char == ',' and bracket_count == 0 and paren_count == 0:
                    params.append(current.strip())
                    current = ''
                else:
                    current += char
            else:
                current += char
                # Handle escaped quotes
                if char == string_char:
                    # Check if it's escaped
                    escape_count = 0
                    j = i - 1
                    while j >= 0 and params_str[j] == '\\':
                        escape_count += 1
                        j -= 1
                    
                    # If even number of backslashes, quote is not escaped
                    if escape_count % 2 == 0:
                        in_string = False
            
            i += 1
        
        if current.strip():
            params.append(current.strip())
        
        return params
    
    def process_let_directive(self, line, stack, output):
        """Process @let directive - khai báo biến không cần kiểm tra isset"""
        if line.startswith('@let'):
            # Extract expression from @let(...)
            let_match = re.search(r'@let\s*\((.*)\)', line)
            if let_match:
                expression = let_match.group(1).strip()
                # Convert PHP to JavaScript
                from php_converter import php_to_js
                js_expression = php_to_js(expression)
                # Tạo output với function call
                let_output = f'${{{JS_FUNCTION_PREFIX}.execute(() => {{'
                output.append(let_output)
                # Thêm các dòng code
                code_lines = js_expression.split(';')
                for code_line in code_lines:
                    if code_line.strip():
                        output.append(f'    {code_line.strip()};')
                output.append('})}')
                return True
        return False
    
    def process_const_directive(self, line, stack, output):
        """Process @const directive - khai báo biến không cần kiểm tra isset"""
        if line.startswith('@const'):
            # Extract expression from @const(...)
            const_match = re.search(r'@const\s*\((.*)\)', line)
            if const_match:
                expression = const_match.group(1).strip()
                # Convert PHP to JavaScript
                from php_converter import php_to_js
                js_expression = php_to_js(expression)
                # Tạo output với function call
                const_output = f'${{{JS_FUNCTION_PREFIX}.execute(() => {{'
                output.append(const_output)
                # Thêm các dòng code
                code_lines = js_expression.split(';')
                for code_line in code_lines:
                    if code_line.strip():
                        output.append(f'    {code_line.strip()};')
                output.append('})}')
                return True
        return False
    
    def process_usestate_directive(self, line, stack, output):
        """Process @useState directive - tạo state với tên biến tùy chỉnh"""
        if line.startswith('@useState'):
            # Extract expression from @useState(...)
            usestate_match = re.search(r'@useState\s*\((.*)\)', line)
            if usestate_match:
                expression = usestate_match.group(1).strip()
                # Parse 2 parameters: value, stateName
                params = self._parse_usestate_params(expression)
                if len(params) == 3:
                    value, state_name, set_state_name = params
                    # Convert PHP to JavaScript
                    from php_converter import php_to_js
                    value_js = php_to_js(value)
                    state_name_js = php_to_js(state_name)
                    set_state_name_js = php_to_js(set_state_name)
                    # Tạo output với function call
                    usestate_output = f'${{{JS_FUNCTION_PREFIX}.execute(() => {{'
                    output.append(usestate_output)
                    output.append(f'    const [{state_name_js}, {set_state_name_js}] = useState({value_js});')
                    output.append('})}')
                    return True
        return False
    
    def _parse_usestate_params(self, expression):
        """Parse @useState parameters"""
        params = []
        current = ''
        paren_count = 0
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
                elif char == ',' and paren_count == 0:
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

    def _format_attrs(self, attrs_str):
        """Format attributes string"""
        # Parse PHP array syntax và convert thành HTML attributes
        if attrs_str.startswith('[') and attrs_str.endswith(']'):
            # Remove brackets
            attrs_str = attrs_str[1:-1].strip()
            if not attrs_str:
                return ''
            
            # Parse key => value pairs
            attrs = []
            parts = attrs_str.split(',')
            for part in parts:
                part = part.strip()
                if '=>' in part:
                    key, value = part.split('=>', 1)
                    key = key.strip().strip('\'"')
                    value = value.strip().strip('\'"')
                    attrs.append(f'{key}="{value}"')
            
            return ' '.join(attrs)
        
        return attrs_str