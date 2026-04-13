"""
Handlers cho các section directives (@section, @endsection, @yield, etc.)
"""

from config import JS_FUNCTION_PREFIX
from php_converter import php_to_js
from utils import extract_balanced_parentheses
import re

class SectionHandlers:
    def __init__(self):
        pass
    
    def process_section_directive(self, line, stack, output, sections):
        """Process @section directive"""
        # Use extract_balanced_parentheses to properly handle nested parentheses
        section_pos = line.find('(')
        if section_pos != -1:
            section_content, end_pos = extract_balanced_parentheses(line, section_pos)
            if section_content is not None:
                # Parse section content - could be ('name') or ('name', value)
                # Find the first comma that's not inside quotes or parentheses
                first_arg_end = self._find_first_comma(section_content)
                
                if first_arg_end != -1:
                    # Two parameter version: @section('name', value)
                    section_name_raw = section_content[:first_arg_end].strip()
                    section_value_raw = section_content[first_arg_end + 1:].strip()
                    
                    # Extract section name from quotes
                    name_match = re.match(r'[\'"]([^\'"]*)[\'"]', section_name_raw)
                    if name_match:
                        section_name = name_match.group(1)
                        
                        # Check if this is a simple string literal (no PHP concatenation or variables)
                        is_simple_string = (
                            ((section_value_raw.startswith("'") and section_value_raw.endswith("'")) or 
                             (section_value_raw.startswith('"') and section_value_raw.endswith('"'))) and
                            # No PHP concatenation operator
                            ' .' not in section_value_raw and '. ' not in section_value_raw and
                            # No PHP variables
                            '$' not in section_value_raw
                        )
                        
                        if is_simple_string:
                            # Direct string literal - fix escaping only
                            section_value = self._ensure_proper_escaping(section_value_raw)
                        else:
                            # Complex expression with concatenation or variables - use php_to_js
                            section_value = php_to_js(section_value_raw) if section_value_raw else "''"
                        
                        result = '${' + JS_FUNCTION_PREFIX + '.section(\'' + section_name + '\', ' + section_value + ', \'string\')}'
                        output.append(result)
                        sections.append(result)
                        return True
                else:
                    # Single parameter version: @section('name')
                    name_match = re.match(r'[\'"]([^\'"]*)[\'"]', section_content.strip())
                    if name_match:
                        section_name = name_match.group(1)
                        stack.append(('section', section_name, len(output)))
                        return True
        
        return False
    
    def _find_first_comma(self, content):
        """Find the first comma that's not inside quotes or parentheses"""
        depth = 0
        in_single_quote = False
        in_double_quote = False
        
        for i, char in enumerate(content):
            if char == "'" and not in_double_quote:
                in_single_quote = not in_single_quote
            elif char == '"' and not in_single_quote:
                in_double_quote = not in_double_quote
            elif char == '(' and not in_single_quote and not in_double_quote:
                depth += 1
            elif char == ')' and not in_single_quote and not in_double_quote:
                depth -= 1
            elif char == ',' and depth == 0 and not in_single_quote and not in_double_quote:
                return i
        
        return -1
        
        return False
    
    def process_endsection_directive(self, stack, output, sections):
        """Process @endsection directive"""
        if stack and stack[-1][0] == 'section':
            _, section_name, start_idx = stack.pop()
            # Filter out boolean values and join strings
            section_content = '\n'.join([str(item) for item in output[start_idx:] if isinstance(item, str)])
            output[:] = output[:start_idx]  # Modify output in place
            
            # Determine section type based on content
            section_type = 'html' if self._contains_html_tags(section_content) else 'string'
            
            section_line = '${' + JS_FUNCTION_PREFIX + '.section(\'' + section_name + '\', `' + section_content + '`, \'' + section_type + '\')}'
            output.append(section_line)
            sections.append(section_line)
        return True
    
    def process_block_directive(self, line, stack, output, sections):
        """Process @block directive - similar to @section"""
        # Two parameter version: @block('name', attributes)
        match_two = re.match(r'@block\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*(.*?)\s*\)', line)
        if match_two and ',' in line:
            block_name = match_two.group(1)
            block_attributes = match_two.group(2)
            block_attributes_js = php_to_js(block_attributes) if block_attributes else "{}"
            result = '${this.__block(\'' + block_name + '\', ' + block_attributes_js + ', `'
            stack.append(('block', block_name, len(output), block_attributes_js))
            return True
        
        # Single parameter version: @block('name')
        match_one = re.match(r'@block\s*\(\s*[\'"]([^\'"]*)[\'"]|([^)]*)\s*\)', line)
        if match_one:
            block_name = match_one.group(1) or php_to_js(match_one.group(2))
            result = '${this.__block(\'' + block_name + '\', {}, `'
            stack.append(('block', block_name, len(output), '{}'))
            return True
        
        return False
    
    def process_endblock_directive(self, stack, output, sections):
        """Process @endblock/@endBlock directive - similar to @endsection"""
        if stack and stack[-1][0] == 'block':
            _, block_name, start_idx, block_attributes = stack.pop()
            # Filter out boolean values and join strings
            block_content = '\n'.join([str(item) for item in output[start_idx:] if isinstance(item, str)])
            output[:] = output[:start_idx]  # Modify output in place
            
            # Complete the block call
            block_line = '${this.__block(\'' + block_name + '\', ' + block_attributes + ', `' + block_content + '`)}'
            output.append(block_line)
            sections.append(block_line)
        return True

    def _contains_html_tags(self, content):
        """Check if content contains HTML tags"""
        # Simple check for HTML tags
        html_pattern = r'<[a-zA-Z][^>]*>'
        return bool(re.search(html_pattern, content))

    def _ensure_proper_escaping(self, section_value):
        """Ensure section value is properly escaped for JavaScript string literals"""
        # If it's already a string literal (starts and ends with quotes), fix escaping
        if ((section_value.startswith("'") and section_value.endswith("'")) or 
            (section_value.startswith('"') and section_value.endswith('"'))):
            # Extract content and re-escape properly
            quote_char = section_value[0]
            content = section_value[1:-1]  # Remove quotes
            
            # Escape single quotes in content
            if quote_char == "'":
                content = content.replace("\\'", "'")  # Unescape first
                content = content.replace("'", "\\'")  # Re-escape properly
            else:
                content = content.replace('\\"', '"')  # Unescape first  
                content = content.replace('"', '\\"')  # Re-escape properly
            
            return quote_char + content + quote_char
        
        return section_value
