"""
Chuyển đổi các biểu thức PHP sang JavaScript
"""

from config import JS_FUNCTION_PREFIX
from utils import normalize_quotes
from php_js_converter import php_to_js_advanced
import re
import subprocess

def convert_php_array_with_php_r(php_array_expr):
    """Convert PHP array to JSON using php -r command"""
    try:
        # Tạo PHP code để convert array sang JSON
        php_code = f"try {{ echo json_encode({php_array_expr}); }} catch (Exception $e) {{ echo '[]'; }}"
        
        # Chạy php -r command
        result = subprocess.run(
            ['php', '-r', php_code],
            capture_output=True,
            text=True,
            timeout=5,  # 5 giây timeout
            check=False  # Không raise exception khi return code != 0
        )
        
        if result.returncode == 0:
            # Parse JSON output
            json_output = result.stdout.strip()
            return json_output
        else:
            print(f"PHP error: {result.stderr}")
            return None
            
    except (subprocess.TimeoutExpired, subprocess.SubprocessError, OSError) as e:
        print(f"Error running php -r: {e}")
        return None

def convert_php_array_to_json(expr):
    """Convert PHP array syntax to JSON object/array syntax using php -r"""
    if not expr or '[' not in expr:
        return expr
    
    # Tìm tất cả các mảng PHP và convert chúng
    def replace_array(match):
        full_array = match.group(0)  # Toàn bộ array expression
        inner = match.group(1).strip()
        
        if not inner:
            return '[]'
        
        # Check if this is array access vs array literal
        if (("'" in inner and inner.count("'") == 2 and not '=>' in inner) or 
            ('"' in inner and inner.count('"') == 2 and not '=>' in inner) or 
            (inner.replace('_', '').replace('.', '').isalnum() and not '=>' in inner)):
            return '[' + inner + ']'  # Keep as array access
        
        # Sử dụng php -r để convert array
        json_result = convert_php_array_with_php_r(full_array)
        if json_result is not None:
            return json_result
        
        # Fallback to old method if php -r fails
        return _convert_php_array_legacy(full_array)
    
    # Thử sử dụng php -r cho toàn bộ expression trước
    json_result = convert_php_array_with_php_r(expr)
    if json_result is not None and 'PHP error' not in str(json_result):
        return json_result
    
    # Fallback: chỉ xử lý quotes đơn giản
    expr = re.sub(r"'([^']*)'", r'"\1"', expr)
    return expr

def _process_array_content(inner_content):
    """Process array content directly without recursion"""
    if not inner_content:
        return '[]'
    
    # Process array elements
    elements = []
    current_element = ''
    paren_count = 0
    bracket_count = 0
    in_quotes = False
    quote_char = ''
    
    i = 0
    while i < len(inner_content):
        char = inner_content[i]
        
        if not in_quotes:
            if char in ['"', "'"]:
                in_quotes = True
                quote_char = char
                current_element += char
            elif char == '(':
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
            elif char == ',' and paren_count == 0 and bracket_count == 0:
                # End of element
                elements.append(current_element.strip())
                current_element = ''
            else:
                current_element += char
        else:
            if char == quote_char:
                # Check if it's escaped
                escape_count = 0
                j = i - 1
                while j >= 0 and inner_content[j] == '\\':
                    escape_count += 1
                    j -= 1
                
                # If even number of backslashes, quote is not escaped
                if escape_count % 2 == 0:
                    in_quotes = False
            
            current_element += char
        
        i += 1
    
    # Add last element
    if current_element.strip():
        elements.append(current_element.strip())
    
    # Process elements
    processed_elements = []
    for element in elements:
        element = element.strip()
        if not element:
            continue
            
        # Check if it's a key => value pair
        if '=>' in element:
            key, value = element.split('=>', 1)
            key = key.strip()
            value = value.strip()
            
            # Convert key
            if key.startswith("'") and key.endswith("'"):
                key_js = '"' + key[1:-1] + '"'
            elif key.startswith('"') and key.endswith('"'):
                key_js = key
            elif key.replace('_', '').replace('.', '').isalnum():
                key_js = '"' + key + '"'
            else:
                key_js = key
            
            # Convert value
            if value.startswith("'") and value.endswith("'"):
                value_js = '"' + value[1:-1] + '"'
            elif value.startswith('"') and value.endswith('"'):
                value_js = value
            elif value in ['true', 'false', 'null']:
                value_js = value
            elif value.replace('.', '').replace('-', '').isdigit():
                value_js = value
            else:
                # Try to convert nested arrays using php -r
                try:
                    value_js = convert_php_array_with_php_r(value)
                except:
                    value_js = value
            
            processed_elements.append(key_js + ': ' + value_js)
        else:
            # Simple value
            if element.startswith("'") and element.endswith("'"):
                element_js = '"' + element[1:-1] + '"'
            elif element.startswith('"') and element.endswith('"'):
                element_js = element
            elif element in ['true', 'false', 'null']:
                element_js = element
            elif element.replace('.', '').replace('-', '').isdigit():
                element_js = element
            else:
                # Try to convert nested arrays using php -r
                try:
                    element_js = convert_php_array_with_php_r(element)
                except:
                    element_js = element
            
            processed_elements.append(element_js)
    
    if not processed_elements:
        return '[]'
    elif any(':' in elem for elem in processed_elements):
        # This is a mixed array, treat as object
        return '{' + ', '.join(processed_elements) + '}'
    else:
        return '[' + ', '.join(processed_elements) + ']'

def _convert_php_array_legacy(expr):
    """Legacy method for converting PHP arrays (fallback)"""
    # Find all array patterns and convert them
    def replace_array(match):
        inner = match.group(1).strip()
        if not inner:
            return '[]'
        
        # Check if this is array access vs array literal (không phải nested array)
        if (("'" in inner and inner.count("'") == 2 and not '=>' in inner and inner.count('[') == 0) or 
            ('"' in inner and inner.count('"') == 2 and not '=>' in inner and inner.count('[') == 0) or 
            (inner.replace('_', '').replace('.', '').isalnum() and not '=>' in inner and inner.count('[') == 0)):
            return '[' + inner + ']'  # Keep as array access
        
        # Process array elements
        elements = []
        current_element = ''
        paren_count = 0
        bracket_count = 0
        in_quotes = False
        quote_char = ''
        i = 0
        
        while i < len(inner):
            char = inner[i]
            
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
                    elements.append(current_element.strip())
                    current_element = ''
                    i += 1
                    continue
            
            current_element += char
            i += 1
        
        if current_element.strip():
            elements.append(current_element.strip())
        
        # Process each element
        processed_elements = []
        has_key_value_pairs = False
        
        for element in elements:
            element = element.strip()
            if '=>' in element:
                # This is a key => value pair
                has_key_value_pairs = True
                key_value = element.split('=>', 1)
                key = key_value[0].strip().strip('\'"')
                value = key_value[1].strip()
                
                # Convert value to JS (recursively)
                value_js = _convert_php_array_legacy(value)
                
                # Normalize quotes in value_js
                value_js = normalize_quotes(value_js)
                
                # Handle key (add quotes if not already quoted)
                if not (key.startswith('"') and key.endswith('"')) and not (key.startswith("'") and key.endswith("'")):
                    key = f'"{key}"'
                
                processed_elements.append(f'{key}: {value_js}')
            else:
                # This is a simple value - check if it's a nested array
                if '[' in element and ']' in element:
                    # It's a nested array, try php -r first
                    json_result = convert_php_array_with_php_r(element)
                    if json_result is not None:
                        value_js = json_result
                    else:
                        # Fallback to recursive conversion
                        value_js = _convert_php_array_legacy(element)
                else:
                    # Simple value
                    value_js = element
                
                value_js = normalize_quotes(value_js)
                processed_elements.append(value_js)
        
        # If contains key-value pairs, it's an object
        if has_key_value_pairs:
            return '{' + ', '.join(processed_elements) + '}'
        else:
            # Check if this is a numeric array
            is_numeric_array = True
            for element in elements:
                if '=>' in element:
                    is_numeric_array = False
                    break
            
            if is_numeric_array:
                return '[' + ', '.join(processed_elements) + ']'
            else:
                # This is a mixed array, treat as object
                return '{' + ', '.join(processed_elements) + '}'
    
    # Apply array conversion recursively với balanced bracket matching
    def find_and_replace_arrays(text):
        result = text
        while '[' in result:
            # Tìm mảng đầu tiên
            start = result.find('[')
            if start == -1:
                break
            
            # Tìm closing bracket tương ứng với balanced matching
            bracket_count = 0
            end = start
            in_quotes = False
            quote_char = ''
            
            for i in range(start, len(result)):
                char = result[i]
                
                if not in_quotes:
                    if char in ['"', "'"]:
                        in_quotes = True
                        quote_char = char
                    elif char == '[':
                        bracket_count += 1
                    elif char == ']':
                        bracket_count -= 1
                        if bracket_count == 0:
                            end = i
                            break
                else:
                    if char == quote_char:
                        # Check if it's escaped
                        escape_count = 0
                        j = i - 1
                        while j >= 0 and result[j] == '\\':
                            escape_count += 1
                            j -= 1
                        
                        # If even number of backslashes, quote is not escaped
                        if escape_count % 2 == 0:
                            in_quotes = False
            
            if bracket_count == 0:
                # Tìm thấy mảng hoàn chỉnh
                array_expr = result[start:end+1]
                inner_content = array_expr[1:-1].strip()
                
                if not inner_content:
                    replacement = '[]'
                else:
                    # Check if this is array access vs array literal
                    if (("'" in inner_content and inner_content.count("'") == 2 and not '=>' in inner_content and inner_content.count('[') == 0) or 
                        ('"' in inner_content and inner_content.count('"') == 2 and not '=>' in inner_content and inner_content.count('[') == 0) or 
                        (inner_content.replace('_', '').replace('.', '').isalnum() and not '=>' in inner_content and inner_content.count('[') == 0)):
                        replacement = array_expr  # Keep as array access
                    else:
                        # Process as array literal - xử lý trực tiếp để tránh vòng lặp
                        replacement = _process_array_content(inner_content)
                
                result = result[:start] + replacement + result[end+1:]
            else:
                break
        
        return result
    
    expr = find_and_replace_arrays(expr)
    
    return expr

def php_to_js(expr):
    """Convert PHP expression to JavaScript using advanced converter"""
    if expr is None:
        return "''"
    
    # Remove PHP closure use(...) syntax
    expr = re.sub(r'\s+use\s*\([^)]*\)', '', expr)
    
    # Handle foreach BEFORE converting => to :
    foreach_pattern = r'\bforeach\s*\(\s*(.*?)\s*as\s*\$?(\w+)(\s*=>\s*\$?(\w+))?\s*\)(\s*)\{'
    
    def replace_foreach(match):
        array_expr = match.group(1).strip()
        first_var = match.group(2)
        space_before_brace = match.group(5) if len(match.groups()) >= 5 else ' '
        
        if match.group(3):  # Has key => value  
            key_var = first_var  # first var is key
            value_var = match.group(4)  # second var is value
            return f'{JS_FUNCTION_PREFIX}.foreach({array_expr}, ({value_var}, {key_var}, __loopIndex, loop) =>{space_before_brace}{{'
        else:  # Only value
            value_var = first_var
            return f'{JS_FUNCTION_PREFIX}.foreach({array_expr}, ({value_var}, __loopKey, __loopIndex, loop) =>{space_before_brace}{{'
    
    expr = re.sub(foreach_pattern, replace_foreach, expr)
    
    # Use advanced converter for complex structures
    expr = php_to_js_advanced(expr)
    
    return expr

def convert_php_to_js(php_expr):
    """Convert PHP expressions to JavaScript equivalents"""
    php_expr = php_expr.strip()
    
    # Convert PHP operators to JavaScript with correct precedence order:
    # (1) String concatenation "." -> "+"
    # (2) Object accessor "->" -> "."  
    # (3) Static accessor "::" -> "."
    
    # Step 1: Convert string concatenation (.) to (+) but be careful with object access and floats
    # Don't convert dots that are part of numbers or already converted object access
    php_expr = convert_string_concatenation(php_expr)
    
    # Step 2: Convert object accessor (->) to (.)
    php_expr = re.sub(r'->', '.', php_expr)
    
    # Step 3: Convert static accessor (::) to (.)
    # Handle $Class::$property and Class::method patterns
    php_expr = re.sub(r'::', '.', php_expr)
    
    # Remove $ prefix from variables (but keep it in template strings)
    php_expr = convert_php_variables(php_expr)
    
    return php_expr

def convert_string_concatenation(php_expr):
    """Convert PHP string concatenation (.) to JavaScript (+) with proper precedence"""
    # Protect string literals first
    string_literals = []
    def protect_string_literal(match):
        pattern = match.group(0)
        placeholder = f"__STR_LIT_{len(string_literals)}__"
        string_literals.append(pattern)
        return placeholder
    
    # Protect single-quoted strings
    php_expr = re.sub(r"'[^']*'", protect_string_literal, php_expr)
    # Protect double-quoted strings
    php_expr = re.sub(r'"[^"]*"', protect_string_literal, php_expr)
    
    # Pattern to match string concatenation dots (not in numbers, not in decimals)
    # Look for dots that are surrounded by non-digit characters or are at word boundaries
    pattern = r'(\w|\]|\))\s*\.\s*(?=\w|\$|\'|"|\()'
    
    result = re.sub(pattern, r'\1+', php_expr)
    
    # Restore string literals
    for i, literal in enumerate(string_literals):
        result = result.replace(f"__STR_LIT_{i}__", literal)
    
    return result

def convert_php_variables(php_expr):
    """Convert $variable to variable (remove $ prefix)"""
    # Don't convert $ in template strings (between backticks)
    # Simple approach: convert $word patterns that are not in template strings
    result = re.sub(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', r'\1', php_expr)
    return result