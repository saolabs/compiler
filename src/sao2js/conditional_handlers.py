"""
Handlers cho các conditional directives (@if, @switch, etc.)
"""

from common.config import JS_FUNCTION_PREFIX
from common.php_converter import php_to_js
from common.utils import extract_balanced_parentheses
import re

class ConditionalHandlers:
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
    
    def process_if_directive(self, line, stack, output, is_attribute_context=False):
        """Process @if directive
        
        Args:
            is_attribute_context: True if directive is in tag attributes (e.g., <div @if(...)>)
                                 False if directive is in block/content (e.g., <div>@if(...))
        """
        if_pos = line.find('(')
        if if_pos != -1:
            condition_text, end_pos = extract_balanced_parentheses(line, if_pos)
            if condition_text is not None:
                condition_php = condition_text.strip()
                condition = php_to_js(condition_php)
                
                # Extract variables from condition
                variables = self._extract_variables(condition)
                state_vars_used = variables & self.state_variables
                
                # Store state vars for this if block (will be used in endif)
                watch_keys = list(state_vars_used) if state_vars_used else []
                
                # Check if inside a loop (for/while only, NOT foreach)
                # @foreach uses callback that returns template literal directly
                # Only @for and @while use __outputContent__ concatenation pattern
                loop_type, parent_concat_var = self._find_enclosing_loop(stack)
                parent_is_loop = loop_type is not None
                
                # Inside a for/while loop - use direct if/else (no reactive/IIFE wrapping)
                # The parent loop already handles reactivity via its own __reactive wrapper
                if parent_is_loop:
                    result = f"if({condition}){{"
                    output.append(result)
                    stack.append(('if', len(output), watch_keys, is_attribute_context, True, 'direct'))
                    return True
                
                rc_param = "(__rc__: any)" if self._is_typescript else "(__rc__)"
                
                # Only wrap with __reactive for block-level directives (not attributes, not in loops)
                if is_attribute_context or not watch_keys:
                    # Attribute directive or no state vars - no reactive wrapping (execute mode)
                    result = f"${{this.__execute(() => {{ if({condition}){{ return `"
                    output.append(result)
                    stack.append(('if', len(output), watch_keys, is_attribute_context, False, 'execute'))
                    return True
                
                # Reactive mode - push scope
                child_id = self.processor._generate_child_id('if')
                rc_id = self.processor._make_rc_id(child_id)
                result = f"${{this.__reactive('if', __rc__, {rc_id}, {watch_keys}, {rc_param} => {{ if({condition}){{ return `"
                
                # Push reactive scope for children inside this if
                self.processor._push_reactive_scope(child_id)
                
                output.append(result)
                stack.append(('if', len(output), watch_keys, is_attribute_context, False, 'reactive'))
                return True
        return False
    
    def process_elseif_directive(self, line, stack, output):
        """Process @elseif directive"""
        elseif_pos = line.find('(')
        if elseif_pos != -1:
            condition_text, end_pos = extract_balanced_parentheses(line, elseif_pos)
            if condition_text is not None:
                condition_php = condition_text.strip()
                condition = php_to_js(condition_php)
                
                # Check if in direct mode (inside for/while loop)
                if stack and stack[-1][0] == 'if':
                    is_direct = stack[-1][5] if len(stack[-1]) > 5 else None
                    if is_direct == 'direct':
                        result = f"}} else if({condition}){{"
                        output.append(result)
                        return True
                
                # Extract variables and merge with existing if block's watch keys
                variables = self._extract_variables(condition)
                state_vars_used = variables & self.state_variables
                
                # Update watch_keys in stack
                if stack and stack[-1][0] == 'if':
                    existing_keys = set(stack[-1][2]) if len(stack[-1]) > 2 else set()
                    new_keys = existing_keys | state_vars_used
                    stack[-1] = ('if', stack[-1][1], list(new_keys))
                
                result = f"`; }} else if({condition}){{ return `"
                output.append(result)
                return True
        return False
    
    def process_else_directive(self, line, stack, output):
        """Process @else directive"""
        # Check if in direct mode (inside for/while loop)
        if stack and stack[-1][0] == 'if':
            is_direct = stack[-1][5] if len(stack[-1]) > 5 else None
            if is_direct == 'direct':
                result = "} else {"
                output.append(result)
                return True
        
        result = f"`; }} else {{ return `"
        output.append(result)
        return True
    
    def process_endif_directive(self, stack, output):
        """Process @endif directive"""
        if stack and stack[-1][0] == 'if':
            mode = stack[-1][5] if len(stack[-1]) > 5 else None
            
            if mode == 'direct':
                # Direct mode (inside for/while loop) - just close the if block
                stack.pop()
                output.append("}")
                return True
            
            # Pop reactive scope if this was a reactive @if
            if mode == 'reactive':
                self.processor._pop_reactive_scope()
            
            stack.pop()
            
            output.append('`; }')
            output.append("return '';")
            
            # Normal block - close IIFE or watch
            output.append('})}')
        return True
        return True
    
    def process_switch_directive(self, line, stack, output, is_attribute_context=False):
        """Process @switch directive
        
        Args:
            is_attribute_context: True if directive is in tag attributes
                                 False if directive is in block/content
        """
        switch_pos = line.find('(')
        if switch_pos != -1:
            switch_content, end_pos = extract_balanced_parentheses(line, switch_pos)
            if switch_content is not None:
                condition_php = switch_content.strip()
                condition = php_to_js(condition_php)
                
                # Extract variables from condition
                variables = self._extract_variables(condition)
                state_vars_used = variables & self.state_variables
                watch_keys = list(state_vars_used) if state_vars_used else []
                
                # Check if parent is a loop using concatenation pattern
                parent_is_concat = False
                parent_type_name = None
                if stack:
                    parent_type = stack[-1][0]
                    if parent_type in ['for', 'foreach', 'while']:
                        parent_is_concat = True
                        parent_type_name = parent_type
                
                # Generate switch statement
                switch_logic = f"let __switchOutputContent__ = '';\nswitch({condition}) {{"
                
                rc_param = "(__rc__: any)" if self._is_typescript else "(__rc__)"
                
                # Generate hierarchical child ID
                child_id = self.processor._generate_child_id('switch')
                rc_id = self.processor._make_rc_id(child_id)
                
                # Determine output format based on parent context
                if parent_is_concat:
                    # Parent uses concatenation (+=), so add concat prefix and use this.__execute()
                    concat_var = f"__{parent_type_name}OutputContent__"
                    if is_attribute_context or not watch_keys:
                        result = f"{concat_var} += this.__execute(() => {{\n{switch_logic}"
                    else:
                        result = f"{concat_var} += this.__reactive('switch', __rc__, {rc_id}, {watch_keys}, {rc_param} => {{\n{switch_logic}"
                else:
                    # Parent uses template literal, so output template expression
                    if is_attribute_context or not watch_keys:
                        result = f"${{this.__execute(() => {{\n{switch_logic}"
                    else:
                        result = f"${{this.__reactive('switch', __rc__, {rc_id}, {watch_keys}, {rc_param} => {{\n{switch_logic}"
                
                # Push reactive scope for switch (no loop var)
                self.processor._push_reactive_scope(child_id)
                
                output.append(result)
                stack.append(('switch', len(output), watch_keys, is_attribute_context, parent_is_concat))
                return True
        return False
    
    def process_case_directive(self, line, stack, output):
        """Process @case directive"""
        case_pos = line.find('(')
        if case_pos != -1:
            case_content, end_pos = extract_balanced_parentheses(line, case_pos)
            if case_content is not None:
                condition = php_to_js(case_content.strip())
                result = f"\ncase {condition}:\n__switchOutputContent__ += `"
                output.append(result)
                # Don't push to stack - case is part of switch
                return True
        return False
    
    def process_default_directive(self, line, stack, output):
        """Process @default directive"""
        result = f"\ndefault:\n__switchOutputContent__ += `"
        output.append(result)
        # Don't push to stack - default is part of switch
        return True
    
    def process_break_directive(self, line, stack, output):
        """Process @break directive"""
        # Determine context by walking the stack
        for entry in reversed(stack):
            if entry[0] == 'switch':
                # Inside a switch - use switch break pattern (close template literal)
                result = "`;\nbreak;"
                output.append(result)
                return True
            if entry[0] in ['for', 'while']:
                # Inside a for/while loop - use direct break
                output.append("break;")
                return True
        # Default: switch break pattern (backward compatible)
        result = "`;\nbreak;"
        output.append(result)
        return True
    
    def process_endswitch_directive(self, stack, output):
        """Process @endswitch directive"""
        if stack and stack[-1][0] == 'switch':
            switch_info = stack.pop()
            parent_is_concat = switch_info[4] if len(switch_info) > 4 else False
            
            # Pop reactive scope
            self.processor._pop_reactive_scope()
            
            if parent_is_concat:
                # Parent uses concatenation, close with })
                result = "`;\n}\nreturn __switchOutputContent__;\n})"
            else:
                # Parent uses template literal, output template expression
                result = "`;\n}\nreturn __switchOutputContent__;\n})}"
            
            output.append(result)
            return True
        return False
