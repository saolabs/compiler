"""
PHP to JavaScript Converter - Advanced version for complex data structures
"""

import re
from typing import List, Dict, Any, Tuple

class PHPToJSConverter:
    """Advanced PHP to JavaScript converter for complex data structures"""

    def __init__(self):
        self.js_function_prefix = "App.View"
        # FIX(F3, docs/FIX_PLAN_2026-08-14.md): tên method định nghĩa trong
        # <script setup> của VIEW ĐANG COMPILE — set qua set_user_methods()
        # trước mỗi lần compile. Instance này là SINGLETON module-level
        # (_php_js_converter) dùng chung qua nhiều lần compile trong CÙNG một
        # tiến trình Python, nên PHẢI reset (set_user_methods(None)/set()) khi
        # bắt đầu compile một view KHÔNG có <script setup> — nếu không, method
        # của view TRƯỚC ĐÓ rò sang view sau.
        self._user_methods: set = set()
        self._current_view_path: str = ''
        # 1 dòng cảnh báo / (view, tên hàm) — tránh spam khi cùng hàm lạ xuất
        # hiện nhiều lần trong 1 template.
        self._warned_unknown: set = set()

    def set_user_methods(self, names, view_path: str = '') -> None:
        """Nạp tập tên method của view đang compile — gọi lại cho MỖI view
        (kể cả rỗng) để không rò trạng thái giữa các lần compile."""
        self._user_methods = set(names or ())
        self._current_view_path = view_path or ''

    def convert_php_expression_to_js(self, expr: str) -> str:
        """Convert PHP expression to JavaScript"""
        # print(f"DEBUG_CONVERT: {expr}")
        if not expr or not expr.strip():
            return "''"
            
        expr = expr.strip()
        
        # Protect ++ and -- operators
        expr = expr.replace('++', '__INC_OPERATOR__')
        expr = expr.replace('--', '__DEC_OPERATOR__')
        
        # Step 1: Handle string concatenation (. to +) FIRST - but only for actual concatenation
        # Skip function calls like route('api.users') and object access like now()->format()
        # Also skip nested function calls like json_encode(event('view.rendered'))
        if '.' in expr and ('$' in expr or '"' in expr or "'" in expr) and not re.match(r'^[a-zA-Z_][a-zA-Z0-9_]*\s*\(', expr.strip()):
            # Skip comparison operators like ===, ==, !=, !==, <, >, <=, >=
            if re.search(r'[=!<>]=?', expr):
                pass  # Skip string concatenation for comparison operators
            # Skip ternary operators like condition ? value1 : value2
            elif re.search(r'\?.*:', expr):
                pass  # Skip string concatenation for ternary operators
            # Check if this is object access pattern (var->method) - skip if so
            # Pattern: anything->anything (not just $var->method)
            # Also skip if it looks like JS object access (obj.prop)
            elif not re.search(r'[a-zA-Z_][a-zA-Z0-9_]*->[a-zA-Z_][a-zA-Z0-9_]*', expr) and \
                 not re.search(r'[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*', expr):
                expr = self._handle_string_concatenation(expr)
                # Restore ++ and -- operators
                expr = expr.replace('__INC_OPERATOR__', '++')
                expr = expr.replace('__DEC_OPERATOR__', '--')
                # IMPORTANT: Still need to convert PHP variables and add function prefixes
                # Remove PHP variable prefix $
                expr = re.sub(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', r'\1', expr)
                expr = self._add_function_prefixes(expr)
                return expr
        
        # Step 2: Convert object property access (-> to .) AFTER string concatenation
        expr = re.sub(r'->', '.', expr)
        
        # Step 3: Handle PHP string concatenation with + operator
        # Convert patterns like (+'string'+) to ('string')
        expr = re.sub(r'\(\+([\'"][^\'\"]*[\'\"])\+\)', r'(\1)', expr)
        
        # Fix patterns like config(+'app.debug'+) to config('app.debug')
        expr = re.sub(r'(\w+)\(\+([\'"][^\'\"]*[\'\"])\+\)', r'\1(\2)', expr)
        
        # Step 4: Handle patterns like +??+'string' FIRST
        expr = re.sub(r'\+\?\?\+([\'"][^\'\"]*[\'\"])', r'??+\1', expr)
        
        # Step 5: Handle PHP null coalescing operator ?? 
        # Fix patterns like +??+ to ?? (remove extra +)
        expr = re.sub(r'\+\?\?\+', '??', expr)
        expr = re.sub(r'\?\?\+\+', '??', expr)
        
        # Step 6: Handle patterns like +'string'+ (without parentheses) - but only for actual concatenation
        # Don't convert function calls like route('api.users') -> route(+'api.users'+)
        # Only convert when there's actual concatenation with variables
        # Check if this is a function call pattern (function_name('string'))
        if not re.match(r'^[a-zA-Z_][a-zA-Z0-9_]*\s*\([^)]*\)$', expr.strip()):
            # Skip comparison operators like ===, ==, !=, !==, <, >, <=, >=
            if not re.search(r'[=!<>]=?', expr):
                # Skip ternary operators like condition ? value1 : value2
                if not re.search(r'\?.*:', expr):
                    if '.' in expr and ('$' in expr or '+' in expr):
                        expr = re.sub(r'\+([\'"][^\'\"]*[\'\"])\+', r'+\1+', expr)
        
        # Step 7: Handle double + operators
        expr = re.sub(r'\+\+', '+', expr)
        
        # Step 8: Fix ternary operators with + characters
        # Fix patterns like condition ?+'value'+:'value' to condition ? 'value' : 'value'
        expr = re.sub(r'\?\s*\+([\'"][^\'\"]*[\'\"])\+\s*:', r'? \1 :', expr)
        expr = re.sub(r':\s*\+([\'"][^\'\"]*[\'\"])\+', r': \1', expr)
        
        # Step 9: Fix remaining + characters in function calls and expressions
        # Fix patterns like config(+'app.debug'+) to config('app.debug')
        expr = re.sub(r'(\w+)\(\+([\'"][^\'\"]*[\'\"])\+\)', r'\1(\2)', expr)
        
        # Fix nested function calls like json_encode(event(+'view.rendered'+))
        # Recursively fix until no more patterns found
        max_iterations = 10
        for _ in range(max_iterations):
            old_expr = expr
            # Fix patterns like func(+'string'+) - match any characters after the closing )
            expr = re.sub(r'(\w+)\(\+([\'"][^\'\"]*[\'\"])\+\)', r'\1(\2)', expr)
            # Also fix patterns with extra characters after like func(+'string'+))
            expr = re.sub(r'\(\+([\'"][^\'\"]*[\'\"])\+\)', r'(\1)', expr)
            if old_expr == expr:
                break
        
        # Fix patterns like +'string'+ to 'string' (standalone)
        expr = re.sub(r'\+([\'"][^\'\"]*[\'\"])\+', r'\1', expr)
        
        # Remove PHP variable prefix, but preserve function calls
        # Only remove $ from variable names, not from function calls
        expr = re.sub(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', r'\1', expr)
        
        # Remove PHP (array) cast - not needed in JavaScript
        expr = re.sub(r'\(array\)\s+', '', expr)
        
        # Handle complex array/object structures first
        expr = self._convert_complex_structures(expr)
        
        # Handle string concatenation
        expr = self._handle_string_concatenation(expr)
        
        # Add function prefixes
        expr = self._add_function_prefixes(expr)
        
        # Restore ++ and -- operators
        expr = expr.replace('__INC_OPERATOR__', '++')
        expr = expr.replace('__DEC_OPERATOR__', '--')
        
        return expr
    
    def _convert_complex_structures(self, expr: str) -> str:
        """Convert complex PHP structures (arrays, objects) to JavaScript"""
        
        # Process from innermost brackets outward
        max_iterations = 10  # Prevent infinite loops
        iteration = 0
        
        while '[' in expr and ']' in expr and iteration < max_iterations:
            iteration += 1
            
            # Find the innermost bracket pair
            start_pos = -1
            end_pos = -1
            bracket_count = 0
            
            for i, char in enumerate(expr):
                if char == '[':
                    if start_pos == -1:
                        start_pos = i
                    bracket_count += 1
                elif char == ']':
                    bracket_count -= 1
                    if bracket_count == 0:
                        end_pos = i
                        break
            
            if start_pos != -1 and end_pos != -1:
                array_content = expr[start_pos + 1:end_pos].strip()
                
                # Check if this is array access vs array literal
                if self._is_array_access(array_content):
                    # Skip this bracket pair
                    continue
                
                # Convert array elements
                elements = self._parse_array_elements(array_content)
                js_elements = []
                
                # Check if this is a mixed array (has both key=>value pairs and simple values)
                has_key_value_pairs = any('=>' in elem for elem in elements)
                has_simple_values = any('=>' not in elem for elem in elements)
                is_mixed_array = has_key_value_pairs and has_simple_values
                
                # Also check if this is an object array (only key=>value pairs)
                is_object_array = has_key_value_pairs and not has_simple_values
                
                if is_mixed_array:
                    # For mixed arrays, convert each element individually
                    for element in elements:
                        if '=>' in element:
                            # Key-value pair - check if it's a nested array
                            if element.startswith('[') and element.endswith(']'):
                                # This is a nested array with key-value pairs
                                nested_content = element[1:-1].strip()
                                nested_elements = self._parse_array_elements(nested_content)
                                
                                # Check if all elements are key-value pairs
                                all_key_value = all('=>' in elem for elem in nested_elements)
                                
                                if all_key_value:
                                    # Convert to object
                                    nested_object_parts = []
                                    for nested_elem in nested_elements:
                                        nested_js_element = self._convert_array_element(nested_elem)
                                        nested_object_parts.append(nested_js_element)
                                    js_elements.append('{' + ', '.join(nested_object_parts) + '}')
                                else:
                                    # Keep as array
                                    nested_js_elements = []
                                    for nested_elem in nested_elements:
                                        if '=>' in nested_elem:
                                            # Key-value pair in nested array
                                            nested_js_element = self._convert_array_element(nested_elem)
                                            nested_js_elements.append('{' + nested_js_element + '}')
                                        else:
                                            # Simple value in nested array
                                            nested_js_element = self._convert_value(nested_elem)
                                            nested_js_elements.append(nested_js_element)
                                    js_elements.append('[' + ', '.join(nested_js_elements) + ']')
                            else:
                                # Direct key-value pair
                                js_element = self._convert_array_element(element)
                                js_elements.append('{' + js_element + '}')
                        else:
                            # Simple value
                            js_element = self._convert_value(element)
                            js_elements.append(js_element)
                elif is_object_array:
                    # For object arrays, check if we have nested arrays
                    has_nested_arrays = any(element.startswith('[') and element.endswith(']') for element in elements)
                    
                    if has_nested_arrays:
                        # This is an object array with nested arrays - keep as array
                        for element in elements:
                            if '=>' in element:
                                # Key-value pair - check if it's a nested array
                                if element.startswith('[') and element.endswith(']'):
                                    # This is a nested array with key-value pairs
                                    nested_content = element[1:-1].strip()
                                    nested_elements = self._parse_array_elements(nested_content)
                                    
                                    # Check if all elements are key-value pairs
                                    all_key_value = all('=>' in elem for elem in nested_elements)
                                    
                                    if all_key_value:
                                        # Convert to object
                                        nested_object_parts = []
                                        for nested_elem in nested_elements:
                                            nested_js_element = self._convert_array_element(nested_elem)
                                            nested_object_parts.append(nested_js_element)
                                        js_elements.append('{' + ', '.join(nested_object_parts) + '}')
                                    else:
                                        # Keep as array
                                        nested_js_elements = []
                                        for nested_elem in nested_elements:
                                            if '=>' in nested_elem:
                                                # Key-value pair in nested array
                                                nested_js_element = self._convert_array_element(nested_elem)
                                                nested_js_elements.append('{' + nested_js_element + '}')
                                            else:
                                                # Simple value in nested array
                                                nested_js_element = self._convert_value(nested_elem)
                                                nested_js_elements.append(nested_js_element)
                                        js_elements.append('[' + ', '.join(nested_js_elements) + ']')
                                else:
                                    # Direct key-value pair
                                    js_element = self._convert_array_element(element)
                                    js_elements.append('{' + js_element + '}')
                            else:
                                # Simple value
                                js_element = self._convert_value(element)
                                js_elements.append(js_element)
                    else:
                        # Regular object array - combine all key-value pairs into single object
                        object_parts = []
                        for element in elements:
                            if '=>' in element:
                                # Key-value pair
                                js_element = self._convert_array_element(element)
                                object_parts.append(js_element)
                        # Combine into single object
                        new_content = '{' + ', '.join(object_parts) + '}'
                        expr = expr[:start_pos] + new_content + expr[end_pos + 1:]
                        continue
                else:
                    # Regular array processing
                    for element in elements:
                        js_element = self._convert_array_element(element)
                        js_elements.append(js_element)
                
                # Replace the bracket content
                new_content = '[' + ', '.join(js_elements) + ']'
                expr = expr[:start_pos] + new_content + expr[end_pos + 1:]
            else:
                break
        
        return expr
    
    def _is_array_access(self, content: str) -> bool:
        """Check if this is array access (e.g., ['key']) vs array literal"""
        # Simple heuristic: if it's a single quoted string or number, it's array access
        content = content.strip()
        if (content.startswith("'") and content.endswith("'") and content.count("'") == 2) or \
           (content.startswith('"') and content.endswith('"') and content.count('"') == 2) or \
           content.isdigit() or content.replace('.', '').isdigit():
            return True
        return False
    
    def _parse_array_elements(self, content: str) -> List[str]:
        """Parse array elements, handling nested structures"""
        elements = []
        current_element = ''
        paren_count = 0
        bracket_count = 0
        brace_count = 0
        in_quotes = False
        quote_char = ''
        i = 0
        
        while i < len(content):
            char = content[i]
            
            if (char == '"' or char == "'") and not in_quotes:
                in_quotes = True
                quote_char = char
                current_element += char
            elif char == quote_char and in_quotes:
                # Check if this is an escaped quote
                if i > 0 and content[i - 1] == '\\':
                    current_element += char
                else:
                    in_quotes = False
                    quote_char = ''
                    current_element += char
            elif not in_quotes:
                if char == '(':
                    paren_count += 1
                    current_element += char
                elif char == ')':
                    paren_count -= 1
                    current_element += char
                elif char == '[':
                    bracket_count += 1
                    current_element += char
                elif char == ']':
                    bracket_count -= 1
                    current_element += char
                elif char == '{':
                    brace_count += 1
                    current_element += char
                elif char == '}':
                    brace_count -= 1
                    current_element += char
                elif char == ',' and paren_count == 0 and bracket_count == 0 and brace_count == 0:
                    # This is a top-level comma
                    elements.append(current_element.strip())
                    current_element = ''
                else:
                    current_element += char
            else:
                current_element += char
            
            i += 1
        
        # Add the last element
        if current_element.strip():
            elements.append(current_element.strip())
        
        return elements
    
    def _convert_array_element(self, element: str) -> str:
        """Convert a single array element to JavaScript"""
        element = element.strip()
        
        # Check if it's a key => value pair
        if '=>' in element:
            key_value_parts = self._split_key_value(element)
            if len(key_value_parts) == 2:
                key, value = key_value_parts
                js_key = self._convert_key(key)
                js_value = self._convert_value(value)
                return f'"{js_key}": {js_value}'
        
        # It's a simple value
        return self._convert_value(element)
    
    def _split_key_value(self, element: str) -> List[str]:
        """Split key => value pair, handling nested structures"""
        # Find the first => that's not inside quotes or nested structures
        paren_count = 0
        bracket_count = 0
        in_quotes = False
        quote_char = ''
        
        for i, char in enumerate(element):
            if (char == '"' or char == "'") and not in_quotes:
                in_quotes = True
                quote_char = char
            elif char == quote_char and in_quotes:
                if i > 0 and element[i - 1] == '\\':
                    continue
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
                elif char == '=' and i + 1 < len(element) and element[i + 1] == '>' and paren_count == 0 and bracket_count == 0:
                    key = element[:i].strip()
                    value = element[i + 2:].strip()
                    return [key, value]
        
        return [element]
    
    def _convert_key(self, key: str) -> str:
        """Convert array key to JavaScript key"""
        key = key.strip()
        
        # Remove quotes if present
        if (key.startswith("'") and key.endswith("'")) or (key.startswith('"') and key.endswith('"')):
            key = key[1:-1]
        
        return key
    
    def _convert_value(self, value: str) -> str:
        """Convert array value to JavaScript value"""
        value = value.strip()
        
        # Handle different value types
        if value.startswith("'") and value.endswith("'"):
            # String value
            return f'"{value[1:-1]}"'
        elif value.startswith('"') and value.endswith('"'):
            # String value
            return value
        elif value.isdigit() or value.replace('.', '').isdigit():
            # Numeric value
            return value
        elif value.lower() in ['true', 'false', 'null']:
            # Boolean/null values
            return value.lower()
        elif value.startswith('[') and value.endswith(']'):
            # Nested array - recursive conversion
            return self._convert_complex_structures(value)
        elif value.startswith('"') or value.startswith("'"):
            # Quoted string
            return value
        elif value.isalnum() or value.replace('_', '').isalnum():
            # Simple identifier (variable name)
            return value
        else:
            # Variable or expression
            return self._convert_simple_expression(value)
    
    def _split_concat_operands(self, expr: str):
        """Tách biểu thức theo dấu '.' nối chuỗi ở mức ngoài cùng.

        Chỉ coi '.' là toán tử nối khi nó không nằm trong ngoặc và không phải
        dấu thập phân của số (1.5). Từng toán hạng trả về NGUYÊN VĂN, nên phép
        toán bên trong ($count * 10) không bị vỡ.
        """
        parts, buf, depth, i = [], [], 0, 0
        while i < len(expr):
            ch = expr[i]
            if ch in '([{':
                depth += 1
            elif ch in ')]}':
                depth -= 1
            elif ch == '.' and depth == 0:
                prev = expr[i - 1] if i else ''
                nxt = expr[i + 1] if i + 1 < len(expr) else ''
                if not (prev.isdigit() and nxt.isdigit()):
                    parts.append(''.join(buf))
                    buf = []
                    i += 1
                    continue
            buf.append(ch)
            i += 1
        parts.append(''.join(buf))
        return parts

    def _restore_string_literal(self, literal: str) -> str:
        """Placeholder → literal JS. Chuỗi nháy kép của PHP có nội suy biến nên
        thành template literal; nháy đơn giữ nguyên."""
        if literal.startswith('"'):
            inner = re.sub(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', r'${\1}', literal[1:-1])
            return f"`{inner}`"
        return literal

    def _handle_string_concatenation(self, expr: str) -> str:
        """Handle string concatenation (. to +) while preserving object property access"""
        
        # Handle string concatenation with variables and string literals
        # But skip function calls like route('api.users') and object method calls like now().format()
        # Also skip nested function calls like json_encode(event('view.rendered'))
        if '.' in expr and ('$' in expr or '"' in expr or "'" in expr) and not re.match(r'^[a-zA-Z_][a-zA-Z0-9_]*\s*\(', expr.strip()):
            # Skip array literals like ['name' => 'John', 'email' => 'john@example.com']
            if expr.strip().startswith('[') and expr.strip().endswith(']'):
                return expr
            # Skip object literals like {"name": "John", "email": "john@example.com"}
            if expr.strip().startswith('{') and expr.strip().endswith('}'):
                return expr
            # Skip null coalescing operator like __VIEW_NAME__ ?? 'layouts.base'
            if '??' in expr:
                return expr
            # Skip comparison operators like __VIEW_PATH__ === 'layouts.base'
            # But check outside string literals to avoid matching < in '<strong>'
            expr_without_strings = re.sub(r"'[^']*'", '', expr)
            expr_without_strings = re.sub(r'"[^"]*"', '', expr_without_strings)
            if re.search(r'[=!<>]=?|==|!=|<=|>=|===|!==', expr_without_strings):
                return expr
            # Skip ternary operators like condition ? value1 : value2
            if re.search(r'\?.*:', expr):
                return expr
            
            # Skip JS style property access (obj.prop)
            # This prevents converting console.log to console+log
            if re.search(r'\b[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*\b', expr_without_strings):
                return expr

            # Also skip object method calls like now().format() or App.Helper.now().format()
            # Pattern: method().method() or object.method().method()
            if re.search(r'[a-zA-Z_][a-zA-Z0-9_]*\s*\([^)]*\)\s*\.\s*[a-zA-Z_][a-zA-Z0-9_]*\s*\(', expr):
                return expr
            # Protect string literals FIRST before processing
            string_literals = []
            def protect_string_literal(match):
                pattern = match.group(0)
                placeholder = f"__STR_LIT_{len(string_literals)}__"
                string_literals.append(pattern)
                return placeholder
            
            # Protect single-quoted strings
            expr_protected = re.sub(r"'[^']*'", protect_string_literal, expr)
            # Protect double-quoted strings
            expr_protected = re.sub(r'"[^"]*"', protect_string_literal, expr_protected)
            
            # Tách theo dấu '.' NỐI CHUỖI ở mức ngoài cùng, giữ NGUYÊN VĂN từng
            # toán hạng. Bản cũ tách theo cả khoảng trắng rồi nối tất cả bằng '+'
            # nên toán tử nằm giữa hai vế bị nuốt:
            #   $count * 10 . '%'  →  count+*+10+'%'   (JS không parse được)
            #   $count + 1 . 'px'  →  count+++1+'px'
            js_parts = []
            for operand in self._split_concat_operands(expr_protected):
                operand = operand.strip()
                if not operand:
                    continue
                # Bỏ '$' TRƯỚC khi khôi phục literal: làm ngược lại thì '$foo'
                # nằm trong chuỗi nháy đơn cũng bị cắt mất '$'.
                operand = re.sub(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', r'\1', operand)
                operand = re.sub(
                    r'__STR_LIT_(\d+)__',
                    lambda m: self._restore_string_literal(string_literals[int(m.group(1))]),
                    operand,
                )
                js_parts.append(operand)

            return '+'.join(js_parts)
        
        # Protect string literals FIRST before processing object access
        string_literals = []
        def protect_string_literal(match):
            pattern = match.group(0)
            placeholder = f"__STR_LIT_{len(string_literals)}__"
            string_literals.append(pattern)
            return placeholder
        
        # Protect single-quoted strings
        expr = re.sub(r"'[^']*'", protect_string_literal, expr)
        # Protect double-quoted strings
        expr = re.sub(r'"[^"]*"', protect_string_literal, expr)
        
        # Protect object property access patterns
        object_access_patterns = []
        def protect_object_access(match):
            pattern = match.group(0)
            placeholder = f"__OBJ_ACCESS_{len(object_access_patterns)}__"
            object_access_patterns.append(pattern)
            return placeholder
        
        # Protect object property access patterns (word.word)
        expr = re.sub(r'\b\w+\.\w+\b', protect_object_access, expr)
        
        # Convert remaining . to + for concatenation
        expr = re.sub(r'\s+\.\s+', ' + ', expr)
        expr = re.sub(r'\.\s+', ' + ', expr)
        expr = re.sub(r'\s+\.', ' + ', expr)
        
        # Restore object property access patterns
        for i, pattern in enumerate(object_access_patterns):
            expr = expr.replace(f"__OBJ_ACCESS_{i}__", pattern)
        
        # Restore string literals LAST
        for i, literal in enumerate(string_literals):
            expr = expr.replace(f"__STR_LIT_{i}__", literal)
        
        return expr
    
    def _convert_simple_expression(self, expr: str) -> str:
        """Convert simple PHP expression to JavaScript"""
        expr = expr.strip()
        
        # Convert object property access
        expr = re.sub(r'->', '.', expr)
        
        # Handle string concatenation
        expr = self._handle_string_concatenation(expr)
        
        # Add function prefixes
        expr = self._add_function_prefixes(expr)
        
        return expr
    
    def _add_function_prefixes(self, expr: str) -> str:
        """Add App.View. or App.Helper. prefix to PHP functions based on ViewConfig"""
        from common.config import ViewConfig, APP_VIEW_NAMESPACE, APP_HELPER_NAMESPACE
        
        # Get all known functions (View + Helper)
        all_functions = [
            'count', 'min', 'max', 'abs', 'ceil', 'floor', 'round', 'sqrt',
            'strlen', 'substr', 'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper',
            'isset', 'empty', 'is_null', 'is_array', 'is_string', 'is_numeric',
            'array_key_exists', 'in_array', 'array_merge', 'array_push', 'array_pop',
            'json_encode', 'json_decode', 'md5', 'sha1', 'base64_encode', 'base64_decode',
            'now', 'today', 'date', 'time', 'strtotime', 'mktime',
            'diffInDays', 'diffInHours', 'diffInMinutes', 'diffInSeconds',
            'addDays', 'subDays', 'addHours', 'subHours', 'addMinutes', 'subMinutes',
            'format', 'parse', 'createFromFormat',
            'env', 'config', 'auth', 'request', 'response', 'session', 'cache',
            'view', 'redirect', 'route', 'url', 'asset', 'mix',
            'collect', 'dd', 'dump', 'logger', 'abort', 'old', 'slug',
            # Additional functions that might be used
            'ucfirst', 'lcfirst', 'str_replace', 'explode', 'implode', 'array_unique',
            'formatDate', 'formatNumber', 'formatCurrency', 'truncate', 'number_format',
            'updateTitle', 'updateDescription', 'updateKeywords',
            'getUrlParams', 'buildUrl', 'isInViewport', 'scrollTo', 'copyToClipboard',
            'getDeviceType', 'isMobile', 'isTablet', 'isDesktop'
        ]
        
        for func in all_functions:
            pattern = r'(?<![\w.])(' + re.escape(func) + r')\s*\('
            # Use ViewConfig to determine correct prefix
            if ViewConfig.is_view_function(func):
                replacement = f'{APP_VIEW_NAMESPACE}.\\1('
            else:
                replacement = f'{APP_HELPER_NAMESPACE}.\\1('
            expr = re.sub(pattern, replacement, expr)

        # Fallback: prefix any remaining bare function call (not already prefixed,
        # not a JS/TS built-in or keyword) with App.Helper.
        _JS_BUILTINS = {
            'function', 'return', 'if', 'else', 'for', 'while', 'do', 'switch',
            'case', 'break', 'continue', 'new', 'delete', 'typeof', 'instanceof',
            'void', 'throw', 'try', 'catch', 'finally', 'class', 'extends',
            'import', 'export', 'default', 'const', 'let', 'var', 'async', 'await',
            'yield', 'super', 'this', 'true', 'false', 'null', 'undefined',
            'Array', 'Object', 'String', 'Number', 'Boolean', 'Symbol', 'BigInt',
            'Math', 'JSON', 'Date', 'RegExp', 'Error', 'Map', 'Set', 'WeakMap',
            'WeakSet', 'Promise', 'Proxy', 'Reflect', 'parseInt', 'parseFloat',
            'isNaN', 'isFinite', 'encodeURIComponent', 'decodeURIComponent',
            'encodeURI', 'decodeURI', 'escape', 'unescape', 'eval', 'console',
            'setTimeout', 'setInterval', 'clearTimeout', 'clearInterval',
            'requestAnimationFrame', 'cancelAnimationFrame',
            'useState', 'updateRealState', 'lockUpdateRealState', 'updateStateByKey',
        }
        def _prefix_bare_function(m):
            name = m.group(1)
            if name in _JS_BUILTINS:
                return m.group(0)
            # FIX(F3): method định nghĩa trong <script setup> của CHÍNH view
            # này → gọi qua this.view (compiled output đã setUserDefinedConfig
            # bind method lên view), KHÔNG rơi vào App.Helper (không tồn tại
            # → TypeError lúc runtime, giết cả view — xem docs/FIX_PLAN_2026-08-14.md §F3).
            if name in self._user_methods:
                return f'this.view.{name}('
            # Tên lạ: không phải helper đã biết (loop `all_functions` ở trên
            # đã lookbehind-loại các tên đó), không phải method của view,
            # không phải JS builtin → fallback App.Helper.<name> như cũ, NHƯNG
            # cảnh báo lúc compile thay vì im lặng tới khi chạy mới nổ.
            warn_key = (self._current_view_path, name)
            if warn_key not in self._warned_unknown:
                self._warned_unknown.add(warn_key)
                where = f' trong "{self._current_view_path}"' if self._current_view_path else ''
                print(
                    f'[sao2js] Cảnh báo{where}: `{name}(...)` không khớp method nào '
                    f'trong <script setup> lẫn danh sách helper đã biết — sẽ compile '
                    f'thành `{APP_HELPER_NAMESPACE}.{name}(...)`. Nếu đây là method của '
                    f'component, kiểm tra chính tả / đã export trong <script setup> chưa.'
                )
            return f'{APP_HELPER_NAMESPACE}.{name}('
        expr = re.sub(r'(?<![\w.])([a-zA-Z_][a-zA-Z0-9_]*)\s*\(', _prefix_bare_function, expr)

        return expr


# Tên LoopContext trong JS output là `__loop` — đúng tham số callback mà
# sao2js sinh ra: `(item, __loopKey, __loopIndex, __loop) => ...`.
#
# Nhưng biểu thức đi vào đây ĐÃ QUA preprocessor, nơi mọi bí danh loop được
# gom về `$loop` (tên Laravel dùng ở nhánh Blade — xem LOOP_ALIASES trong
# src/preprocessor/expression-transformer.js). Sau khi bỏ `$`, ta còn `loop`
# — ĐỊNH DANH KHÔNG TỒN TẠI trong callback JS.
#
# Không map thì: SSR chạy đúng (Laravel cấp `$loop`), CSR ném
# `ReferenceError: loop is not defined` GIẾT CẢ VIEW ngay lúc mount — đúng lớp
# lệch SSR/CSR của F1/F3/F4. Đo được với `@click(remove(__loop.index))`.
#
# CHỈ đổi định danh `loop` đứng độc lập: không đụng `__loop` (đã đúng),
# không đụng property sau dấu chấm (`x.loop`), không đụng `loopFoo`/`myloop`.
_LOOP_IDENT_RE = re.compile(r'(?<![\w.$])loop(?![\w$])')


def _rename_loop_identifier(expr: str) -> str:
    """`loop` (do preprocessor sinh từ `$loop`) → `__loop` (tham số callback JS).

    Bỏ qua nội dung TRONG chuỗi: `{{ 'loop' }}` là dữ liệu người dùng, đổi thành
    `'__loop'` là sửa sai nội dung hiển thị.
    """
    if 'loop' not in expr:
        return expr
    out = []
    i = 0
    n = len(expr)
    while i < n:
        ch = expr[i]
        if ch in ('"', "'", '`'):
            quote = ch
            j = i + 1
            while j < n:
                if expr[j] == '\\':
                    j += 2
                    continue
                if expr[j] == quote:
                    j += 1
                    break
                j += 1
            out.append(expr[i:j])   # nguyên văn, không đụng
            i = j
            continue
        j = i
        while j < n and expr[j] not in ('"', "'", '`'):
            j += 1
        out.append(_LOOP_IDENT_RE.sub('__loop', expr[i:j]))
        i = j
    return ''.join(out)


# Global instance for backward compatibility
_php_js_converter = PHPToJSConverter()

def php_to_js_advanced(expr: str) -> str:
    """Advanced PHP to JavaScript conversion function"""
    # Rename ở ENTRY POINT: convert_php_expression_to_js có nhiều nhánh return
    # sớm, vá từng nhánh sẽ sót. Đây là cửa duy nhất mà template_ast/php_converter
    # đi qua nên phủ trọn.
    return _rename_loop_identifier(_php_js_converter.convert_php_expression_to_js(expr))


def set_user_methods(names, view_path: str = '') -> None:
    """
    Nạp tên method của <script setup> view đang compile vào converter dùng
    chung — gọi lại cho MỖI view (kể cả names rỗng) TRƯỚC khi compile phần
    render/echo của view đó. Xem docs/FIX_PLAN_2026-08-14.md §F3.
    """
    _php_js_converter.set_user_methods(names, view_path)


def get_user_methods() -> set:
    """Tập tên method của view đang compile — nạp qua set_user_methods()."""
    return set(_php_js_converter._user_methods)
