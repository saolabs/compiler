"""
Event Directive Processor - Xử lý event directives theo format mới
"""

import re

class EventDirectiveProcessor:
    def __init__(self, usestate_variables=None):
        """
        Initialize Event Directive Processor
        @param usestate_variables: Set of variable names that have useState declarations
        """
        self.usestate_variables = usestate_variables or set()
    
    def process_event_directive(self, event_type, expression):
        """
        Process event directive theo format mới
        Input: @click(handleClick(Event, 'test'))
        Output: ${self.addEventConfig("click", [{"handler": "handleClick", "params": ["@EVENT", "test"]}])}
        
        Rules:
        - Tất cả đều dùng __addEventConfig
        - Hàm không có $ prefix: dùng object format {"handler": "name", "params": [...]}
        - Biểu thức: dùng arrow function format (event) => ... hoặc () => ...
        - Hàm có $ prefix: 
          - Nếu có useState → dùng (event) => setStateKey(...)
          - Nếu không → dùng arrow function thông thường
        """
        try:
            raw_expr = expression.strip()

            # Split by comma để xử lý từng phần
            parts = self.split_by_comma(raw_expr)
            
            # Phân loại các parts: handlers (không có $) vs expressions (có $ hoặc biểu thức)
            # Tất cả đều dùng __addEventConfig, chỉ khác format:
            # - Handler functions → object format: {"handler": "name", "params": [...]}
            # - Expressions → arrow function format: (event) => ... hoặc () => ...
            # Giữ nguyên thứ tự như trong input
            handler_items = []
            
            for part in parts:
                part = part.strip()
                if not part:
                    continue
                
                # Kiểm tra xem có phải là function call không có $ prefix không
                if self._is_function_call_without_dollar(part):
                    # Hàm không có $ prefix → dùng object format
                    handler = self.parse_handler(part)
                    if handler:
                        # Parameters đã được xử lý bởi parse_handler_parameters
                        # (có thể là object config string hoặc giá trị thông thường)
                        processed_params = handler['params']
                        
                        # Build handler object
                        params_str = ','.join(processed_params)
                        handler_str = f'{{"handler":"{handler["handler"]}","params":[{params_str}]}}'
                        handler_items.append(handler_str)
                else:
                    # Biểu thức hoặc hàm có $ prefix
                    # Kiểm tra xem có nested function calls phức tạp không
                    # Nếu có → dùng object handler để chắc chắn hơn
                    if self._has_nested_function_calls(part):
                        # Có nested calls → parse như handler function
                        # Xử lý cả trường hợp có $ prefix (state setter)
                        handler = self._parse_handler_with_dollar(part)
                        if handler:
                            # Parse và process parameters (có thể chứa function calls)
                            params_string = ', '.join(handler['params'])
                            processed_params = self.parse_handler_parameters(params_string)
                            
                            # Build handler object
                            params_str = ','.join(processed_params)
                            handler_str = f'{{"handler":"{handler["handler"]}","params":[{params_str}]}}'
                            handler_items.append(handler_str)
                        else:
                            # Nếu không parse được → dùng arrow function
                            expressions = self._split_expressions_by_semicolon(part)
                            for expr in expressions:
                                expr_js = self._process_expression_to_arrow(expr)
                                handler_items.append(expr_js)
                    else:
                        # Không có nested calls → dùng arrow function format
                        # Tách biểu thức có `;` thành nhiều arrow functions
                        # Ví dụ: $a++; $b-- → [() => a++, () => b--]
                        expressions = self._split_expressions_by_semicolon(part)
                        for expr in expressions:
                            expr_js = self._process_expression_to_arrow(expr)
                            handler_items.append(expr_js)
            
            # Luôn dùng __addEventConfig với mixed format, giữ nguyên thứ tự
            if handler_items:
                handlers_str = f'[{",".join(handler_items)}]'
                event_config = f'this.__addEventConfig("{event_type}", {handlers_str})'
                return f"${{{event_config}}}"
            
            return ''
                   
        except Exception as e:
            print(f"Event directive error: {e}")
            return ''
    
    def parse_event_handlers(self, expression):
        """
        Parse multiple event handlers từ expression
        """
        handlers = []
        
        # Split by comma, respecting nested parentheses
        handler_strings = self.split_by_comma(expression)
        
        for handler_string in handler_strings:
            handler_string = handler_string.strip()
            if not handler_string:
                continue
                
            # Parse handler name and parameters
            handler = self.parse_handler(handler_string)
            if handler:
                handlers.append(handler)
        
        return handlers
    
    def parse_handler(self, handler_string):
        """
        Parse individual handler: handleClick(Event, 'test') or handleClick
        Chỉ parse hàm không có $ prefix
        """
        # Match function name and parameters
        match = re.match(r'^([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$', handler_string, re.DOTALL)
        if match:
            handler_name = match.group(1)
            params_string = match.group(2).strip()
            
            # Parse parameters
            params = self.parse_handler_parameters(params_string)
            
            return {
                'handler': handler_name,
                'params': params
            }
        else:
            # No parentheses - just function name
            match = re.match(r'^([a-zA-Z_][a-zA-Z0-9_]*)$', handler_string.strip())
            if match:
                handler_name = match.group(1)
                return {
                    'handler': handler_name,
                    'params': []
                }
        
        return None
    
    def parse_handler_parameters(self, params_string):
        """
        Parse handler parameters: Event, 'test', 123
        Nếu param là function call → dùng object config
        """
        if not params_string.strip():
            return []
        
        # Split by comma, respecting nested structures
        params = self.split_by_comma(params_string)
        
        # Process each parameter
        processed_params = []
        for param in params:
            param = param.strip()
            
            # Kiểm tra xem có phải là function call không (có hoặc không có $ prefix)
            # Nếu là function call → parse thành object config
            if self._is_function_call_in_param(param):
                handler = self._parse_function_call_in_param(param)
                if handler:
                    # Build object config string
                    # in_params_context=True vì đang xử lý params trong object config
                    processed_handler_params = []
                    for p in handler['params']:
                        processed_handler_params.append(self.process_parameter(p.strip(), in_params_context=True))
                    params_str = ','.join(processed_handler_params)
                    handler_str = f'{{"handler":"{handler["handler"]}","params":[{params_str}]}}'
                    processed_params.append(handler_str)
                    continue
            
            # Không phải function call → xử lý như bình thường
            # in_params_context=True vì đang xử lý params trong object config
            processed_param = self.process_parameter(param, in_params_context=True)
            processed_params.append(processed_param)
        
        return processed_params
    
    def _is_function_call_in_param(self, param):
        """
        Kiểm tra xem param có phải là function call không (có hoặc không có $ prefix)
        Ví dụ: outerFunc($count, @event) → True
        Ví dụ: $setCount($count + 1) → True
        Ví dụ: $count → False
        """
        param = param.strip()
        # Match function call pattern: name(...) hoặc $name(...)
        match = re.match(r'^(\$?[a-zA-Z_][a-zA-Z0-9_]*)\s*\(', param, re.DOTALL)
        return match is not None
    
    def _parse_function_call_in_param(self, param):
        """
        Parse function call trong param thành handler object
        Ví dụ: outerFunc($count, @event) → {"handler": "outerFunc", "params": ["$count", "@event"]}
        Ví dụ: $setCount($count + 1) → {"handler": "setCount", "params": ["$count + 1"]}
        """
        param = param.strip()
        
        # Match function call với hoặc không có $ prefix
        match = re.match(r'^(\$?[a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$', param, re.DOTALL)
        if match:
            func_name_with_dollar = match.group(1)
            params_string = match.group(2).strip()
            
            # Xử lý tên hàm
            if func_name_with_dollar.startswith('$'):
                func_name = func_name_with_dollar[1:]  # Bỏ $ prefix
                # Kiểm tra xem có phải là state variable không
                if func_name in self.usestate_variables:
                    # Là state setter → dùng tên setter
                    handler_name = f'set{func_name[0].upper()}{func_name[1:]}' if func_name and len(func_name) > 0 else f'set{func_name}'
                else:
                    # Không phải state variable → dùng tên hàm bình thường
                    handler_name = func_name
            else:
                # Không có $ prefix
                handler_name = func_name_with_dollar
            
            # Parse parameters
            params = self.split_by_comma(params_string)
            
            return {
                'handler': handler_name,
                'params': params
            }
        
        return None
    
    def _parse_handler_with_dollar(self, handler_string):
        """
        Parse handler có $ prefix (state setter)
        Ví dụ: $setCount(nestedCall($count, $count + 1))
        → {"handler": "setCount", "params": ["nestedCall($count, $count + 1)"]}
        """
        # Match function call với $ prefix: $name(...)
        match = re.match(r'^\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$', handler_string, re.DOTALL)
        if match:
            func_name = match.group(1)
            params_string = match.group(2).strip()
            
            # Kiểm tra xem có phải là state variable không
            if func_name in self.usestate_variables:
                # Là state setter → dùng tên setter
                # If func_name already starts with "set", use it as is (it's already a setter name)
                # Otherwise, capitalize first letter: count -> setCount
                if func_name.startswith('set') and len(func_name) > 3:
                    setter_name = func_name  # Already a setter name like "setCount"
                else:
                    setter_name = f'set{func_name[0].upper()}{func_name[1:]}' if func_name and len(func_name) > 0 else f'set{func_name}'
                
                # Parse parameters
                params = self.split_by_comma(params_string)
                
                return {
                    'handler': setter_name,
                    'params': params
                }
            else:
                # Không phải state variable → dùng tên hàm bình thường (bỏ $)
                params = self.split_by_comma(params_string)
                
                return {
                    'handler': func_name,
                    'params': params
                }
        
        return None
    
    def _has_event_with_access(self, param):
        """
        Kiểm tra xem param có chứa $event với property access hoặc method call không
        Ví dụ: $event.target, $event.preventDefault(), test($event.type)
        """
        # Pattern: $event theo sau bởi . hoặc chữ khác (property/method access)
        # Hoặc $event nằm trong function call: func($event.prop)
        pattern = r'\$(?:event|Event|EVENT)\s*\.'
        return re.search(pattern, param, re.IGNORECASE) is not None
    
    def _convert_event_with_access_to_arrow(self, param):
        """
        Convert expression có $event.property hoặc $event.method() thành arrow function
        Input: $event.target → Output: (event) => event.target
        Input: test($event.type, $message) → Output: (event) => test(event.type, message)
        """
        # Convert $event/$Event/$EVENT thành event (JavaScript variable)
        result = re.sub(r'\$(?:event|Event|EVENT)(?![a-zA-Z])', 'event', param, flags=re.IGNORECASE)
        
        # Convert các PHP variables khác thành JS variables
        result = self.convert_php_variable_to_js(result)
        
        # Convert PHP array syntax to JS object
        result = self.convert_php_array_to_js_object(result)
        
        # Wrap trong arrow function
        return f'(event) => {result}'
    
    def process_parameter(self, param, in_params_context=False):
        """
        Process individual parameter để handle special cases
        Nếu là biểu thức → trả về (event) => ... format
        Nếu không → trả về giá trị thông thường
        in_params_context: True nếu đang xử lý params trong object config
        """
        # Nếu đã là object config string (có dạng {"handler":...}), không xử lý lại
        if isinstance(param, str) and param.strip().startswith('{"handler"'):
            return param
        
        # SPECIAL CASE: Nếu có $event với property/method access → convert thành arrow function
        # Ví dụ: $event.target → (event) => event.target
        # Phải làm TRƯỚC các bước normalize khác
        if self._has_event_with_access(param):
            return self._convert_event_with_access_to_arrow(param)
        
        # QUAN TRỌNG: Normalize event aliases TRƯỚC khi convert PHP variables
        # Thêm alias support: $event/$Event/$EVENT → @EVENT (phải làm trước convert_php_variable_to_js)
        param = re.sub(r'\$(?:event|Event|EVENT)(?![a-zA-Z])', '@EVENT', param, flags=re.IGNORECASE)
        
        # Đảm bảo @event/@Event/@EVENT được chuyển thành @EVENT (chuẩn hóa)
        param = re.sub(r'@(?:event|Event|EVENT)(?![a-zA-Z])', '@EVENT', param, flags=re.IGNORECASE)
        
        # @EVENT luôn là chuỗi "@EVENT" để hệ thống thay thế sau
        if param.strip() == '@EVENT':
            return '"@EVENT"'
        
        # Convert PHP variable to JavaScript variable ($item -> item)
        # Phải làm SAU khi đã normalize event aliases
        param = self.convert_php_variable_to_js(param)
        
        # Xử lý Event parameters trong mọi context
        param = self.process_event_in_string(param)
        
        # Xử lý @attr(...) -> "#ATTR:..."
        param = self.process_attr_prop_in_string(param, '@attr', '#ATTR')
        
        # Xử lý @prop(...) -> "#PROP:..."
        param = self.process_attr_prop_in_string(param, '@prop', '#PROP')
        
        # Xử lý @val(...) -> "#VALUE:..."
        param = self.process_attr_prop_in_string(param, '@val', '#VALUE')
        
        # Xử lý @value(...) -> "#VALUE:..."
        param = self.process_attr_prop_in_string(param, '@value', '#VALUE')
        
        # Convert PHP array syntax to JavaScript object syntax
        param = self.convert_php_array_to_js_object(param)
        
        # Nếu đã là arrow function (từ các bước xử lý trước), không wrap lại
        if '=>' in param:
            # Nhưng vẫn cần thay thế @EVENT trong arrow function nếu có
            param = re.sub(r'(?<!")@(?:EVENT|event|Event)(?!")', '"@EVENT"', param)
            return param
        
        # Thay thế @EVENT/@event/@Event (không có quotes) thành "@EVENT" trong expression
        # Sử dụng regex để tránh thay thế @EVENT đã có quotes
        param = re.sub(r'(?<!")@(?:EVENT|event|Event)(?!")', '"@EVENT"', param, flags=re.IGNORECASE)
        
        # Nếu đang xử lý params trong object config và param là biến đơn giản
        # (không phải string, không phải directive đã xử lý) → wrap trong () => để lấy giá trị runtime
        if in_params_context:
            # Kiểm tra xem có phải là string (đã có quotes) không
            is_string = (param.startswith('"') and param.endswith('"')) or (param.startswith("'") and param.endswith("'"))
            # Kiểm tra xem có phải là directive đã xử lý không (#ATTR, #PROP, #VALUE)
            is_directive = param.startswith('"#') or param.startswith("'#")
            # Kiểm tra xem có phải là biến đơn giản (valid JS identifier) không
            is_simple_var = self._is_valid_js_identifier(param) and not is_string and not is_directive
            
            if is_simple_var:
                return f'() => {param}'
        
        # Nếu là biểu thức → wrap trong arrow function
        if self._looks_like_expression(param):
            return f'(event) => {param}'
        
        return param
    
    def process_data_type(self, param):
        """
        Process parameter để xác định đúng kiểu dữ liệu
        """
        param = param.strip()
        
        # Nếu đã có quotes thì giữ nguyên
        if (param.startswith('"') and param.endswith('"')) or (param.startswith("'") and param.endswith("'")):
            return param
            
        # Nếu là object hoặc array JavaScript thì giữ nguyên
        if (param.startswith('{') and param.endswith('}')) or (param.startswith('[') and param.endswith(']')):
            return param
            
        # Nếu là number thì giữ nguyên
        if param.isdigit() or (param.startswith('-') and param[1:].isdigit()):
            return param
            
        # Nếu là boolean thì giữ nguyên
        if param.lower() in ['true', 'false']:
            return param
            
        # Nếu là null thì giữ nguyên
        if param.lower() == 'null':
            return param
            
        # Nếu là string thì wrap trong quotes
        return f'"{param}"'
    
    def convert_php_array_to_js_object(self, param):
        """
        Convert PHP array syntax to JavaScript object syntax
        ['key' => 'value'] -> {"key": "value"}
        """
        # Use the new PHP to JS converter with proper precedence
        from php_converter import convert_php_to_js
        param = convert_php_to_js(param)
        
        # If it's already a JavaScript object/array, return as is
        if param.startswith('{') and param.endswith('}'):
            return param
        if param.startswith('[') and param.endswith(']') and ' => ' not in param:
            return param
            
        # If it's a PHP array, convert to JavaScript object
        if param.startswith('[') and param.endswith(']'):
            # Use advanced converter for complex structures
            from php_js_converter import php_to_js_advanced
            param = php_to_js_advanced(param)
        
        return param
    
    def _fix_nested_arrays(self, param):
        """
        Fix nested arrays in converted object
        {"test": {"hahaha", 12}} -> {"test": ["hahaha", 12]}
        """
        # Find patterns like {"value1", "value2"} and convert to ["value1", "value2"]
        import re
        
        # Pattern to match {"value1", "value2"} - array values
        pattern = r'\{\s*"([^"]+)"\s*,\s*"([^"]+)"\s*\}'
        
        def replace_array(match):
            val1 = match.group(1)
            val2 = match.group(2)
            return f'["{val1}", "{val2}"]'
        
        param = re.sub(pattern, replace_array, param)
        
        # Pattern to match {"value1", 123} - mixed array values
        pattern2 = r'\{\s*"([^"]+)"\s*,\s*(\d+)\s*\}'
        
        def replace_mixed_array(match):
            val1 = match.group(1)
            val2 = match.group(2)
            return f'["{val1}", {val2}]'
        
        param = re.sub(pattern2, replace_mixed_array, param)
        
        return param
    
    def process_event_in_string(self, param):
        """
        Process Event parameters trong string một cách recursive
        Không xử lý 'event' trong arrow function như (event) => ...
        """
        # Nếu đã là arrow function, không xử lý (tránh match 'event' trong (event) =>)
        if '=>' in param:
            return param
        
        # Nếu có pattern (event) hoặc (Event), không xử lý (đó là arrow function parameter)
        if re.search(r'\(\s*event\s*\)', param, re.IGNORECASE):
            return param
        
        # Tìm tất cả Event variations trong string
        # Match @event, @Event, @EVENT (với @ prefix)
        # Không match 'event' đơn lẻ (tránh match trong các từ khác)
        # Cho phép @event trước dấu ), dấu phẩy, khoảng trắng, hoặc kết thúc string
        # Pattern: @event, @Event, @EVENT (không có chữ cái sau)
        event_pattern = r'@(?:Event|EVENT|event)(?![a-zA-Z])'
        param = re.sub(event_pattern, '@EVENT', param, flags=re.IGNORECASE)
        
        # Thêm alias support: $event, $Event, $EVENT cũng chuyển thành @EVENT
        # Pattern: $event, $Event, $EVENT (không có chữ cái sau)
        dollar_event_pattern = r'\$(?:Event|EVENT|event)(?![a-zA-Z])'
        param = re.sub(dollar_event_pattern, '@EVENT', param, flags=re.IGNORECASE)
        
        return param
    
    def process_attr_prop_in_string(self, param, directive, prefix):
        """
        Process @attr, @prop, @val, @value trong string một cách recursive
        """
        # Tìm tất cả @directive(...) hoặc @directive() trong string
        pattern = re.escape(directive) + r'\s*\(\s*([^)]*)\s*\)'
        
        def replace_match(match):
            value = match.group(1).strip()
            
            if not value:
                # Trường hợp @directive() - không có tham số
                return f'"{prefix}"'
            else:
                # Loại bỏ quotes nếu có
                value = value.strip('"\'')
                # Thay thế @directive(...) bằng "#PREFIX:..." hoặc "#PREFIX"
                return f'"{prefix}:{value}"'
        
        param = re.sub(pattern, replace_match, param, flags=re.IGNORECASE)
        
        return param
    
    def _split_expressions_by_semicolon(self, expr):
        """
        Tách biểu thức có `;` thành nhiều biểu thức
        Ví dụ: $a++; $b-- → ['$a++', '$b--']
        """
        if ';' not in expr:
            return [expr]
        
        expressions = []
        current = ''
        paren_count = 0
        bracket_count = 0
        in_quotes = False
        quote_char = ''
        
        i = 0
        while i < len(expr):
            char = expr[i]
            
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
                elif char == ';' and paren_count == 0 and bracket_count == 0:
                    # Semicolon at level 0 - expression separator
                    if current.strip():
                        expressions.append(current.strip())
                    current = ''
                    i += 1
                    continue
                else:
                    current += char
            else:
                current += char
                if char == quote_char and (i == 0 or expr[i-1] != '\\'):
                    in_quotes = False
                    quote_char = ''
            
            i += 1
        
        # Add last expression
        if current.strip():
            expressions.append(current.strip())
        
        return expressions if expressions else [expr]
    
    def split_by_comma(self, expression):
        """
        Split expression by comma, respecting nested parentheses, square brackets and quotes
        """
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
                    # Comma at level 0 - parameter separator
                    params.append(current.strip())
                    current = ''
                    i += 1
                    continue
                else:
                    current += char
            else:
                current += char
                if char == quote_char and (i == 0 or expression[i-1] != '\\'):
                    in_quotes = False
                    quote_char = ''
            
            i += 1
        
        # Add last parameter
        if current.strip():
            params.append(current.strip())
        
        return params
    
    def build_event_config(self, event_type, handlers):
        """
        Build event config theo format mới
        """
        handler_configs = []
        
        for handler in handlers:
            handler_name = handler['handler']
            params = handler['params']
            
            # Process parameters để handle special cases
            processed_params = []
            for param in params:
                processed_params.append(self.process_parameter(param))
            
            handler_configs.append({
                'handler': handler_name,
                'params': processed_params
            })
        
        # Build JavaScript array string manually để control quoting
        handlers_str = self.build_handlers_string(handler_configs)
        
        return f'this.__addEventConfig("{event_type}", {handlers_str})'
    
    def build_handlers_string(self, handler_configs):
        """
        Build JavaScript array string manually để control quoting
        """
        handlers = []
        
        for config in handler_configs:
            handler_name = config['handler']
            params = config['params']
            
            # Build params array with proper quoting
            processed_params = []
            for param in params:
                # Param đã được xử lý bởi process_parameter, có thể đã là arrow function
                # Kiểm tra xem đã là arrow function chưa
                if isinstance(param, str) and ('=>' in param or param.startswith('(event) =>') or param.startswith('() =>')):
                    # Đã là arrow function → giữ nguyên
                    processed_params.append(param)
                    continue
                
                # @EVENT đã được xử lý trong process_parameter thành "@EVENT" (có quotes)
                if isinstance(param, str) and (param == '@EVENT' or param == '"@EVENT"'):
                    processed_params.append('"@EVENT"')
                    continue
                
                if isinstance(param, str):
                    # First, convert PHP variable to JS if needed ($item -> item)
                    param = self.convert_php_variable_to_js(param)
                    
                    # If it's a string and not already quoted, check what it is
                    if not (param.startswith('"') and param.endswith('"')) and not (param.startswith("'") and param.endswith("'")):
                        # Check if it's a complex structure (array/object)
                        if param.startswith('[') and param.endswith(']'):
                            processed_params.append(param)
                        elif param.startswith('{') and param.endswith('}'):
                            processed_params.append(param)
                        elif param.startswith('@'):
                            # Special directive like @EVENT
                            processed_params.append(f'"{param}"')
                        elif self._is_valid_js_identifier(param):
                            # Valid JavaScript variable/identifier - don't wrap in quotes
                            processed_params.append(param)
                        else:
                            # Simple string value
                            processed_params.append(f'"{param}"')
                    else:
                        processed_params.append(param)
                else:
                    # Convert to string for non-string types
                    processed_params.append(str(param))
            
            params_str = ','.join(processed_params)
            
            # Build handler object
            handler_str = f'{{"handler":"{handler_name}","params":[{params_str}]}}'
            handlers.append(handler_str)
        
        return f'[{",".join(handlers)}]'

    def _looks_like_expression(self, s: str) -> bool:
        """
        Phát hiện chuỗi là biểu thức JS (không phải literal/identifier đơn)
        - Chứa toán tử, dấu ngoặc, hoặc gọi hàm
        - Không phải chỉ là số, boolean, null, hay identifier hợp lệ
        - Không phải @EVENT (đã là special directive)
        """
        t = s.strip()
        
        # Nếu đã là arrow function, không phải biểu thức đơn giản
        if '=>' in t:
            return False
        
        # @EVENT là special directive, không phải biểu thức
        if t == '@EVENT' or t == '"@EVENT"':
            return False
        
        # literals
        if t.isdigit() or (t.startswith('-') and t[1:].isdigit()):
            return False
        if t.lower() in ['true', 'false', 'null']:
            return False
        if self._is_valid_js_identifier(t):
            return False
        # function call or operators/parentheses
        op_chars = ['+', '-', '*', '/', '%', '>', '<', '=', '!', '&', '|', '?', ':']
        if '(' in t and ')' in t:
            return True
        if any(ch in t for ch in op_chars):
            return True
        # spaces between tokens can indicate expression
        if ' ' in t:
            return True
        return False

    def _looks_like_handlers(self, expr: str) -> bool:
        """
        Kiểm tra biểu thức giống danh sách handler function calls, ví dụ: fn(a), fn2(), handle
        """
        parts = self.split_by_comma(expr)
        if not parts:
            return False
        for p in parts:
            p = p.strip()
            # Check if it's a function call with parameters
            match = re.match(r'^[a-zA-Z_$][a-zA-Z0-9_$]*\s*\((.*)\)$', p)
            if match:
                params_str = match.group(1).strip()
                # If parameters contain expressions, this should be quickHandle
                if params_str and self._contains_expressions(params_str):
                    return False  # Not a simple handler, use quickHandle
            elif not re.match(r'^[a-zA-Z_$][a-zA-Z0-9_$]*$', p):
                # Not a simple variable or function call
                return False
        return True

    def _contains_expressions(self, params_str: str) -> bool:
        """
        Check if parameter string contains expressions (operators, method calls, etc.)
        """
        params_str = params_str.strip()
        if not params_str:
            return False
        
        # Check for mathematical operators
        if re.search(r'[+\-*/%]', params_str):
            return True
        
        # Check for method calls like obj.method() or obj->method()
        if '->' in params_str or re.search(r'\.[a-zA-Z_]', params_str):
            return True
        
        # Check for array access like arr[0]
        if re.search(r'\[.*\]', params_str):
            return True
        
        # Check for ternary operators
        if '?' in params_str and ':' in params_str:
            return True
        
        return False
    
    def _is_valid_js_identifier(self, param):
        """
        Check if param is a valid JavaScript identifier/variable name
        Valid identifiers: letters, digits, underscore, dollar sign
        Must start with letter, underscore, or dollar sign
        Examples: item, userState, _private, $local
        """
        # JavaScript identifier pattern
        js_identifier_pattern = r'^[a-zA-Z_$][a-zA-Z0-9_$]*$'
        return bool(re.match(js_identifier_pattern, param))
    
    def convert_php_variable_to_js(self, param):
        """
        Convert PHP variable syntax to JavaScript
        $item -> item
        """
        # Pattern to match PHP variables: $variableName
        php_var_pattern = r'\$([a-zA-Z_][a-zA-Z0-9_]*)'
        # Replace $variable with variable
        param = re.sub(php_var_pattern, r'\1', param)
        return param
    
    def _has_nested_function_calls(self, expr):
        """
        Kiểm tra xem expression có chứa nested function calls không
        Ví dụ: nestedCall(outerFunc(...), innerFunc(...)) → True
        Ví dụ: $setCount(nestedCall(...)) → True
        Ví dụ: test($a, $b) → False
        """
        expr = expr.strip()
        
        # Tìm function call pattern: name(...) hoặc $name(...)
        match = re.match(r'^(\$?[a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$', expr, re.DOTALL)
        if match:
            params_string = match.group(2).strip()
            if not params_string:
                return False
            
            # Đếm số function calls trong params (không tính function call ngoài cùng)
            # Pattern: tên hàm + ( (có thể có $ prefix)
            nested_pattern = r'\$?[a-zA-Z_][a-zA-Z0-9_]*\s*\('
            matches = re.findall(nested_pattern, params_string)
            # Nếu có nhiều hơn 0 function calls trong params → có nested calls
            return len(matches) > 0
        
        return False
    
    def _is_function_call_without_dollar(self, expr):
        """
        Kiểm tra xem có phải là function call không có $ prefix và KHÔNG phải state variable
        Tất cả function calls không có $ prefix → luôn dùng object handler
        Ví dụ: test(), handleClick(Event), nestedCall(...) → True (handler function)
        Ví dụ: $test(), $press++, setCount(...) → False (có $ hoặc biểu thức)
        Ví dụ: count(...) nếu count có trong usestate_variables → False (state variable, không phải handler)
        """
        expr = expr.strip()
        
        # Match function call pattern: name(...)
        match = re.match(r'^([a-zA-Z_][a-zA-Z0-9_]*)\s*\(', expr, re.DOTALL)
        if match:
            func_name = match.group(1)
            # Không có $ prefix
            if not func_name.startswith('$'):
                # Kiểm tra xem có phải là state variable không (có trong usestate_variables)
                # Nếu là state variable → không phải handler function → xử lý như biểu thức
                if func_name in self.usestate_variables:
                    return False  # State variable → xử lý như biểu thức
                
                # Tất cả function calls không có $ prefix → luôn là handler function (dùng object handler)
                return True
        
        # Match simple function name: name
        match = re.match(r'^([a-zA-Z_][a-zA-Z0-9_]*)$', expr)
        if match:
            func_name = match.group(1)
            if not func_name.startswith('$'):
                # Kiểm tra xem có phải là state variable không
                if func_name in self.usestate_variables:
                    return False  # State variable → xử lý như biểu thức
                return True
        
        return False
    
    def _process_expression_to_arrow(self, expr):
        """
        Xử lý biểu thức thành arrow function format
        - Nếu có $ prefix và có useState → dùng (event) => setStateKey(...)
        - Nếu không → dùng (event) => ... hoặc () => ...
        """
        original_expr = expr.strip()
        
        # Convert PHP variable to JavaScript
        expr_js = self.convert_php_variable_to_js(original_expr)
        
        # Xử lý Event parameters
        expr_js = self.process_event_in_string(expr_js)
        
        # Xử lý @attr, @prop, @val, @value
        expr_js = self.process_attr_prop_in_string(expr_js, '@attr', '#ATTR')
        expr_js = self.process_attr_prop_in_string(expr_js, '@prop', '#PROP')
        expr_js = self.process_attr_prop_in_string(expr_js, '@val', '#VALUE')
        expr_js = self.process_attr_prop_in_string(expr_js, '@value', '#VALUE')
        
        # Convert PHP array syntax to JavaScript object syntax
        expr_js = self.convert_php_array_to_js_object(expr_js)
        
        # Kiểm tra xem có phải là function call không có $ prefix không
        # Ví dụ: setCount($count - 1) → setCount(count - 1)
        # Ví dụ: count(...) nếu count có trong usestate_variables → cần dùng setter
        match = re.match(r'^([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$', original_expr, re.DOTALL)
        if match:
            func_name = match.group(1)
            params_str = match.group(2).strip()
            
            # Nếu là state variable (có trong usestate_variables), cần dùng setter
            if func_name in self.usestate_variables:
                # Parse params - nếu param là function call (không phải state variable) → dùng object config
                params = self.split_by_comma(params_str)
                processed_params = []
                for param in params:
                    param = param.strip()
                    # Kiểm tra xem có phải là function call không
                    if self._is_function_call_in_param(param):
                        # Function call → parse thành object config
                        handler = self._parse_function_call_in_param(param)
                        if handler:
                            # Build object config string
                            processed_handler_params = []
                            for p in handler['params']:
                                processed_handler_params.append(self.process_parameter(p.strip(), in_params_context=True))
                            params_str_inner = ','.join(processed_handler_params)
                            handler_str = f'{{"handler":"{handler["handler"]}","params":[{params_str_inner}]}}'
                            processed_params.append(handler_str)
                            continue
                    
                    # Không phải function call → xử lý như bình thường
                    param_js = self.convert_php_variable_to_js(param)
                    param_js = self.process_event_in_string(param_js)
                    param_js = self.process_attr_prop_in_string(param_js, '@attr', '#ATTR')
                    param_js = self.process_attr_prop_in_string(param_js, '@prop', '#PROP')
                    param_js = self.process_attr_prop_in_string(param_js, '@val', '#VALUE')
                    param_js = self.process_attr_prop_in_string(param_js, '@value', '#VALUE')
                    param_js = self.convert_php_array_to_js_object(param_js)
                    # Thay thế @EVENT thành "@EVENT" nếu chưa có quotes
                    param_js = re.sub(r'(?<!")@EVENT(?!")', '"@EVENT"', param_js)
                    processed_params.append(param_js)
                
                params_str_js = ', '.join(processed_params)
                # If func_name already starts with "set", use it as is (it's already a setter name)
                # Otherwise, capitalize first letter: count -> setCount
                if func_name.startswith('set') and len(func_name) > 3:
                    setter_name = func_name  # Already a setter name like "setCount"
                else:
                    setter_name = f'set{func_name[0].upper()}{func_name[1:]}' if func_name and len(func_name) > 0 else f'set{func_name}'
                return f'(event) => {setter_name}({params_str_js})'
        
        # Kiểm tra xem có phải là function call với $ prefix không
        # Ví dụ: $test($a+1) → test(a + 1)
        # Ví dụ: $setCount($count + 1) → setCount(count + 1)
        match = re.match(r'^\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$', original_expr, re.DOTALL)
        if match:
            func_name = match.group(1)
            params_str = match.group(2).strip()
            
            # Kiểm tra xem có phải là state variable hoặc setter không (có trong usestate_variables)
            # Nếu là state variable hoặc setter → cần dùng setter
            if func_name in self.usestate_variables:
                # Có useState → dùng (event) => setStateKey(...)
                # Parse params - nếu param là function call (không phải state variable) → dùng object config
                params = self.split_by_comma(params_str)
                processed_params = []
                for param in params:
                    param = param.strip()
                    # Kiểm tra xem có phải là function call không
                    if self._is_function_call_in_param(param):
                        # Function call → parse thành object config
                        handler = self._parse_function_call_in_param(param)
                        if handler:
                            # Build object config string
                            processed_handler_params = []
                            for p in handler['params']:
                                processed_handler_params.append(self.process_parameter(p.strip(), in_params_context=True))
                            params_str_inner = ','.join(processed_handler_params)
                            handler_str = f'{{"handler":"{handler["handler"]}","params":[{params_str_inner}]}}'
                            processed_params.append(handler_str)
                            continue
                    
                    # Không phải function call → xử lý như bình thường
                    param_js = self.convert_php_variable_to_js(param)
                    # Xử lý Event parameters
                    param_js = self.process_event_in_string(param_js)
                    # Chuẩn hóa @event thành @EVENT
                    param_js = re.sub(r'@(?:event|Event|EVENT)(?![a-zA-Z])', '@EVENT', param_js, flags=re.IGNORECASE)
                    # Xử lý @attr, @prop, @val, @value
                    param_js = self.process_attr_prop_in_string(param_js, '@attr', '#ATTR')
                    param_js = self.process_attr_prop_in_string(param_js, '@prop', '#PROP')
                    param_js = self.process_attr_prop_in_string(param_js, '@val', '#VALUE')
                    param_js = self.process_attr_prop_in_string(param_js, '@value', '#VALUE')
                    # Convert PHP array syntax to JavaScript object syntax
                    param_js = self.convert_php_array_to_js_object(param_js)
                    # Thay thế @EVENT thành "@EVENT" nếu chưa có quotes
                    param_js = re.sub(r'(?<!")@EVENT(?!")', '"@EVENT"', param_js)
                    processed_params.append(param_js)
                
                params_str_js = ', '.join(processed_params)
                # If func_name already starts with "set", use it as is (it's already a setter name)
                # Otherwise, capitalize first letter: count -> setCount
                if func_name.startswith('set') and len(func_name) > 3:
                    setter_name = func_name  # Already a setter name like "setCount"
                else:
                    setter_name = f'set{func_name[0].upper()}{func_name[1:]}' if func_name and len(func_name) > 0 else f'set{func_name}'
                return f'(event) => {setter_name}({params_str_js})'
            else:
                # Không có useState → dùng arrow function thông thường
                return f'(event) => {expr_js}'
        
        # Kiểm tra xem có phải là biểu thức với $ variable không
        # Ví dụ: $press++, $hoverCount+=2
        if re.search(r'\$[a-zA-Z_][a-zA-Z0-9_]*', original_expr):
            # Có $ variable → kiểm tra useState
            # Extract variable name
            var_match = re.search(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', original_expr)
            if var_match:
                var_name = var_match.group(1)
                if var_name in self.usestate_variables:
                    # Có useState → dùng (event) => setStateKey(...)
                    # Convert expression: $press++ → press + 1
                    # Hoặc giữ nguyên expression và wrap trong setter
                    # Setter name format: setStateKey (capitalize first letter)
                    setter_name = f'set{var_name[0].upper()}{var_name[1:]}' if var_name and len(var_name) > 0 else f'set{var_name}'
                    # For assignment: $press++ → setPress(press + 1)
                    # For simple increment: $press++ → setPress(press + 1)
                    if '++' in expr_js:
                        expr_js = expr_js.replace('++', '')
                        return f'(event) => {setter_name}({expr_js} + 1)'
                    elif '+=' in expr_js:
                        # $hoverCount+=2 → setHoverCount(hoverCount + 2)
                        parts = expr_js.split('+=')
                        if len(parts) == 2:
                            var = parts[0].strip()
                            value = parts[1].strip()
                            return f'(event) => {setter_name}({var} + {value})'
                    elif '=' in expr_js and not expr_js.startswith('='):
                        # $test = $hoverCount + 2 → setTest(hoverCount + 2)
                        parts = expr_js.split('=', 1)
                        if len(parts) == 2:
                            var = parts[0].strip()
                            value = parts[1].strip()
                            # Setter name format: setStateKey (capitalize first letter)
                            setter_name = f'set{var[0].upper()}{var[1:]}' if var and len(var) > 0 else f'set{var}'
                            return f'(event) => {setter_name}({value})'
                    else:
                        # Other expression with useState variable
                        return f'(event) => {setter_name}({expr_js})'
        
        # Không có useState hoặc không phải $ variable → dùng arrow function thông thường
        # Kiểm tra xem có cần event parameter không
        if '@EVENT' in expr_js or 'event' in expr_js.lower():
            return f'(event) => {expr_js}'
        else:
            return f'() => {expr_js}'
    
    def build_event_config_unified(self, event_type, handlers, arrow_functions):
        """
        Build event config với unified format: arrow functions + handler objects
        Tất cả đều dùng __addEventConfig
        Ví dụ: @click($a++; $b--, test($a, $b))
        → __addEventConfig('click', [() => a++, () => b--, {"handler": "test", "params": [a, b]}])
        """
        handler_items = []
        
        # Add arrow functions TRƯỚC
        handler_items.extend(arrow_functions)
        
        # Add handlers (objects) SAU
        for handler in handlers:
            handler_name = handler['handler']
            params = handler['params']
            
            # Process parameters
            processed_params = []
            for param in params:
                processed_params.append(self.process_parameter(param))
            
            # Build handler object
            params_str = ','.join(processed_params)
            handler_str = f'{{"handler":"{handler_name}","params":[{params_str}]}}'
            handler_items.append(handler_str)
        
        handlers_str = f'[{",".join(handler_items)}]'
        return f'this.__addEventConfig("{event_type}", {handlers_str})'
    
    def build_event_config_mixed(self, event_type, handlers, quick_handles):
        """
        Build event config với mixed format: quick_handles (arrow functions) + handlers (objects)
        DEPRECATED: Dùng build_event_config_unified thay thế
        """
        return self.build_event_config_unified(event_type, handlers, quick_handles)
