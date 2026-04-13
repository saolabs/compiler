"""
Handlers cho các loop directives (@foreach, @for, etc.)
"""

from common.config import JS_FUNCTION_PREFIX
from common.php_converter import php_to_js
from common.utils import extract_balanced_parentheses
import re

class LoopHandlers:
    def __init__(self, state_variables=None, processor=None, is_typescript=False):
        self.state_variables = state_variables or set()
        self.processor = processor
        self._is_typescript = is_typescript
    
    def _find_enclosing_loop(self, stack):
        """Walk the stack to find the enclosing for/while loop.
        Returns (loop_type, concat_var) or (None, None) if not found.
        Returns (None, None) if a 'foreach' is encountered first."""
        for entry in reversed(stack):
            if entry[0] in ['for', 'while']:
                return entry[0], f"__{entry[0]}OutputContent__"
            if entry[0] == 'foreach':
                return None, None
        return None, None
    
    def _extract_variables(self, expr):
        """Extract variable names from expression, excluding method names after dot notation"""
        variables = set()
        
        # Remove method calls after dots (e.g., App.Helper.count -> remove count)
        # Replace .methodName( with .PLACEHOLDER( to avoid extracting method names
        expr_cleaned = re.sub(r'\.([a-zA-Z_][a-zA-Z0-9_]*)\s*\(', '.METHODCALL(', expr)
        
        var_pattern = r'\b([a-zA-Z_][a-zA-Z0-9_]*)\b'
        matches = re.findall(var_pattern, expr_cleaned)
        for var_name in matches:
            # Exclude JS keywords, common functions, and placeholder
            if var_name not in ['if', 'else', 'return', 'function', 'const', 'let', 'var', 'true', 'false', 'null', 'undefined', 'Array', 'Object', 'String', 'Number', 'METHODCALL', 'App', 'View', 'Helper']:
                variables.add(var_name)
        return variables
    
    def process_foreach_directive(self, line, stack, output, is_attribute_context=False):
        """Process @foreach directive
        
        Args:
            is_attribute_context: True if directive is in tag attributes
                                 False if directive is in block/content
        """
        foreach_pos = line.find('(')
        if foreach_pos != -1:
            foreach_content, end_pos = extract_balanced_parentheses(line, foreach_pos)
            if foreach_content is not None:
                as_match = re.match(r'\s*(.*?)\s+as\s+\$?(\w+)(\s*=>\s*\$?(\w+))?\s*$', foreach_content)
                if as_match:
                    array_expr_php = as_match.group(1)
                    array_expr = php_to_js(array_expr_php)
                    first_var = as_match.group(2)
                    
                    if as_match.group(3):  # Has key => value
                        key_var = first_var
                        value_var = as_match.group(4)
                        if self._is_typescript:
                            callback = f'({value_var}: any, {key_var}: any, __loopIndex: any, __loop: any) => `'
                        else:
                            callback = f'({value_var}, {key_var}, __loopIndex, __loop) => `'
                    else:  # Only value
                        value_var = first_var
                        if self._is_typescript:
                            callback = f'({value_var}: any, __loopKey: any, __loopIndex: any, __loop: any) => `'
                        else:
                            callback = f'({value_var}, __loopKey, __loopIndex, __loop) => `'
                    
                    # Extract variables from array expression
                    variables = self._extract_variables(array_expr)
                    state_vars_used = variables & self.state_variables
                    watch_keys = list(state_vars_used) if state_vars_used else []
                    
                    # Use this.__foreach for instance method
                    foreach_call = f"this.__foreach({array_expr}, {callback}"
                    
                    # Check if inside a parent for/while loop (concat context)
                    parent_loop_type, parent_concat_var = self._find_enclosing_loop(stack)
                    in_concat_context = parent_loop_type is not None
                    
                    # Generate hierarchical child ID
                    child_id = self.processor._generate_child_id('foreach')
                    
                    # Only wrap with __reactive for block-level directives (not attributes)
                    if is_attribute_context:
                        # Attribute directive - no reactive wrapping
                        result = f"${{{foreach_call}"
                    elif in_concat_context:
                        # Inside a for/while loop - use concatenation
                        rc_id = self.processor._make_rc_id(child_id)
                        rc_param = "(__rc__: any)" if self._is_typescript else "(__rc__)"
                        result = f"{parent_concat_var} += this.__reactive('foreach', __rc__, {rc_id}, {watch_keys}, {rc_param} => {foreach_call}"
                    else:
                        # Block directive - wrap with __reactive in template literal
                        rc_id = self.processor._make_rc_id(child_id)
                        rc_param = "(__rc__: any)" if self._is_typescript else "(__rc__)"
                        result = f"${{this.__reactive('foreach', __rc__, {rc_id}, {watch_keys}, {rc_param} => {foreach_call}"
                    
                    # Push reactive scope for children inside foreach
                    self.processor._push_reactive_scope(child_id, '__loopIndex')
                    
                    output.append(result)
                    stack.append(('foreach', len(output), is_attribute_context, in_concat_context))
                    return True
        return False
    
    def process_endforeach_directive(self, stack, output):
        """Process @endforeach directive"""
        if stack and stack[-1][0] == 'foreach':
            is_attribute = stack[-1][2] if len(stack[-1]) > 2 else False
            in_concat = stack[-1][3] if len(stack[-1]) > 3 else False
            # Pop reactive scope
            self.processor._pop_reactive_scope()
            stack.pop()
            if is_attribute:
                # Attribute directive - no watch wrapper
                output.append('`)}') 
            elif in_concat:
                # Inside a for/while loop - close with statement end
                output.append('`));')
            else:
                # Block directive in template literal - close watch wrapper
                output.append('`))}') 
        return True
    
    def process_for_directive(self, line, stack, output, is_attribute_context=False):
        """Process @for directive
        
        Args:
            is_attribute_context: True if directive is in tag attributes
                                 False if directive is in block/content
        """
        for_pos = line.find('(')
        if for_pos != -1:
            for_content, end_pos = extract_balanced_parentheses(line, for_pos)
            if for_content is not None:
                # Parse @for($i = 0; $i < 10; $i++)
                for_match = re.match(r'\s*\$?(\w+)\s*=\s*(.*?);\s*\$?\1\s*([<>=!]+)\s*(.*?);\s*\$?\1\s*\+\+\s*$', for_content)
                if for_match:
                    var_name = for_match.group(1)
                    start_value_php = for_match.group(2)
                    start_value = php_to_js(start_value_php)
                    operator = for_match.group(3)
                    end_value_php = for_match.group(4)
                    end_value = php_to_js(end_value_php)
                    
                    # Extract variables from start and end values
                    variables = self._extract_variables(start_value) | self._extract_variables(end_value)
                    state_vars_used = variables & self.state_variables
                    watch_keys = list(state_vars_used) if state_vars_used else []
                    
                    # Generate for loop with __for() wrapper
                    for_logic = f"let __forOutputContent__ = ``;\nfor (let {var_name} = {start_value}; {var_name} {operator} {end_value}; {var_name}++) {{__loop.setCurrentTimes({var_name});"
                    
                    # Wrap in __for() with __loop parameter
                    if self._is_typescript:
                        for_call = f"this.__for('increment', {start_value}, {end_value}, (__loop: any) => {{\n{for_logic}"
                    else:
                        for_call = f"this.__for('increment', {start_value}, {end_value}, (__loop) => {{\n{for_logic}"
                    
                    # Only wrap with __reactive for block-level directives (not attributes)
                    has_watch = not is_attribute_context and watch_keys
                    
                    # Check if inside a parent for/while loop (concat context)
                    parent_loop_type, parent_concat_var = self._find_enclosing_loop(stack)
                    in_concat_context = parent_loop_type is not None
                    
                    # Generate hierarchical child ID
                    child_id = self.processor._generate_child_id('for')
                    
                    if is_attribute_context:
                        result = f"${{{for_call}"
                    elif in_concat_context:
                        # Inside a for/while loop - use concatenation
                        if has_watch:
                            rc_id = self.processor._make_rc_id(child_id)
                            rc_param = "(__rc__: any)" if self._is_typescript else "(__rc__)"
                            result = f"{parent_concat_var} += this.__reactive('for', __rc__, {rc_id}, {watch_keys}, {rc_param} => {{ return {for_call}"
                        else:
                            result = f"{parent_concat_var} += {for_call}"
                    elif not watch_keys:
                        result = f"${{{for_call}"
                    else:
                        rc_id = self.processor._make_rc_id(child_id)
                        rc_param = "(__rc__: any)" if self._is_typescript else "(__rc__)"
                        result = f"${{this.__reactive('for', __rc__, {rc_id}, {watch_keys}, {rc_param} => {for_call}"
                    
                    # Push reactive scope for children inside for loop
                    self.processor._push_reactive_scope(child_id, var_name)
                    
                    output.append(result)
                    stack.append(('for', len(output), is_attribute_context, has_watch, in_concat_context))
                    return True
        return False
    
    def process_endfor_directive(self, stack, output):
        """Process @endfor directive"""
        if stack and stack[-1][0] == 'for':
            is_attribute = stack[-1][2] if len(stack[-1]) > 2 else False
            has_watch = stack[-1][3] if len(stack[-1]) > 3 else False
            in_concat = stack[-1][4] if len(stack[-1]) > 4 else False
            # Pop reactive scope
            self.processor._pop_reactive_scope()
            stack.pop()
            
            if is_attribute:
                # Attribute directive - close __for
                result = "\n}\nreturn __forOutputContent__;\n})\n}"
            elif in_concat and has_watch:
                # Nested in parent loop with reactive - close __for, reactive callback, reactive call, statement
                result = "\n}\nreturn __forOutputContent__;\n})\n});"
            elif in_concat:
                # Nested in parent loop without reactive - close __for, statement
                result = "\n}\nreturn __forOutputContent__;\n});"
            elif has_watch:
                # Block directive with watch in template literal - close __for, reactive, ${}
                result = "\n}\nreturn __forOutputContent__;\n})\n)}"
            else:
                # Block directive without watch in template literal - close __for, ${}
                result = "\n}\nreturn __forOutputContent__;\n})\n}"
            
            output.append(result)
        return True
    
    def process_while_directive(self, line, stack, output, is_attribute_context=False):
        """Process @while directive
        
        Args:
            is_attribute_context: True if directive is in tag attributes
                                 False if directive is in block/content
        """
        while_pos = line.find('(')
        if while_pos != -1:
            while_content, end_pos = extract_balanced_parentheses(line, while_pos)
            if while_content is not None:
                condition_php = while_content
                condition = php_to_js(condition_php)
                
                # Extract variables from condition
                variables = self._extract_variables(condition)
                state_vars_used = variables & self.state_variables
                watch_keys = list(state_vars_used) if state_vars_used else []
                
                # Generate while loop with __while() wrapper and __loop.next()
                loop_param = "(__loop: any)" if self._is_typescript else "(__loop)"
                while_logic = f"let __whileOutputContent__ = ``;\nlet __whileIterations__ = 0;\nwhile({condition} && __whileIterations__ < 10000) {{\n__loop.next();"
                while_call = f"this.__while({loop_param} => {{\n{while_logic}"
                
                # Generate hierarchical child ID (always, for scope tracking)
                child_id = self.processor._generate_child_id('while')
                
                # Check if inside a parent for/while loop (concat context)
                parent_loop_type, parent_concat_var = self._find_enclosing_loop(stack)
                in_concat_context = parent_loop_type is not None
                
                has_watch = not is_attribute_context and watch_keys
                
                if is_attribute_context:
                    result = f"${{{while_call}"
                elif in_concat_context:
                    # Inside a for/while loop - use concatenation
                    if has_watch:
                        rc_id = self.processor._make_rc_id(child_id)
                        rc_param = "(__rc__: any)" if self._is_typescript else "(__rc__)"
                        result = f"{parent_concat_var} += this.__reactive('while', __rc__, {rc_id}, {watch_keys}, {rc_param} => {{ return {while_call}"
                    else:
                        result = f"{parent_concat_var} += {while_call}"
                elif not watch_keys:
                    result = f"${{{while_call}"
                else:
                    rc_id = self.processor._make_rc_id(child_id)
                    rc_param = "(__rc__: any)" if self._is_typescript else "(__rc__)"
                    result = f"${{this.__reactive('while', __rc__, {rc_id}, {watch_keys}, {rc_param} => {while_call}"
                
                # Push reactive scope for children inside while (no explicit loop var)
                self.processor._push_reactive_scope(child_id)
                
                output.append(result)
                stack.append(('while', len(output), is_attribute_context, has_watch, in_concat_context))
                return True
        return False
    
    def process_endwhile_directive(self, stack, output):
        """Process @endwhile directive"""
        if stack and stack[-1][0] == 'while':
            is_attribute = stack[-1][2] if len(stack[-1]) > 2 else False
            has_watch = stack[-1][3] if len(stack[-1]) > 3 else False
            in_concat = stack[-1][4] if len(stack[-1]) > 4 else False
            # Pop reactive scope
            self.processor._pop_reactive_scope()
            stack.pop()
            
            if is_attribute:
                # Attribute directive - close while, __while callback, ${}
                result = "\n__whileIterations__++;\n}\nreturn __whileOutputContent__;\n})\n}"
            elif in_concat and has_watch:
                # Nested in parent loop with reactive - close while, __while, reactive callback, reactive call, statement
                result = "\n__whileIterations__++;\n}\nreturn __whileOutputContent__;\n})\n});"
            elif in_concat:
                # Nested in parent loop without reactive - close while, __while, statement
                result = "\n__whileIterations__++;\n}\nreturn __whileOutputContent__;\n});"
            elif has_watch:
                # Block directive with watch - close while, __while, reactive, ${}
                result = "\n__whileIterations__++;\n}\nreturn __whileOutputContent__;\n})\n)}"
            else:
                # Block directive without watch - close while, __while, ${}
                result = "\n__whileIterations__++;\n}\nreturn __whileOutputContent__;\n})\n}"
            
            output.append(result)
        return True
