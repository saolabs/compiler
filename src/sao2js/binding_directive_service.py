"""
Binding Directive Service - Xử lý @val và @bind directives
@val và @bind là alias của nhau, cùng tạo ra data-binding attribute
"""

import re

class BindingDirectiveService:
    def __init__(self):
        pass
    
    def process_binding_directive(self, content, directive_pattern='val|bind'):
        """
        Process binding directives (@val and @bind are aliases)
        @val($userState->name) -> data-binding="userState.name"
        @bind($username) -> data-binding="username"
        Both directives produce the same output
        """
        def replace_binding_with_nested_parens(content):
            result = content
            while True:
                # Find @val or @bind directive
                match = re.search(rf'@({directive_pattern})\s*\(', result)
                if not match:
                    break
                
                start_pos = match.end() - 1  # Position of opening (
                paren_count = 0
                i = start_pos
                
                # Find matching closing parenthesis
                while i < len(result):
                    if result[i] == '(':
                        paren_count += 1
                    elif result[i] == ')':
                        paren_count -= 1
                        if paren_count == 0:
                            # Found matching closing parenthesis
                            expression = result[start_pos + 1:i].strip()
                            binding_value = self._convert_php_to_binding(expression)
                            replacement = f'data-binding="{binding_value}" data-view-id="${{__VIEW_ID__}}"'
                            result = result[:match.start()] + replacement + result[i + 1:]
                            break
                    i += 1
                else:
                    # No matching parenthesis found, break to avoid infinite loop
                    break
            
            return result
        
        return replace_binding_with_nested_parens(content)
    
    def process_val_directive(self, content):
        """
        Process @val directive (alias of process_binding_directive)
        @val($userState->name) -> data-binding="userState.name"
        @val($user['name']) -> data-binding="user.name"
        """
        return self.process_binding_directive(content, 'val')
    
    def process_bind_directive(self, content):
        """
        Process @bind directive (alias of process_binding_directive)
        @bind($username) -> data-binding="username"
        """
        return self.process_binding_directive(content, 'bind')
    
    def _convert_php_to_binding(self, php_expression):
        """
        Convert PHP expression to JavaScript binding notation
        $userState->name -> userState.name
        $user['name'] -> user.name
        $username -> username
        User::find($id)->profile->displayName -> User.find(id).profile.displayName
        """
        # Remove leading/trailing whitespace
        php_expression = php_expression.strip()
        
        # Convert $variable to variable (remove $ prefix)
        result = re.sub(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', r'\1', php_expression)
        
        # Convert PHP static method accessor (::) to (.)
        result = re.sub(r'::', '.', result)
        
        # Convert object accessor (->) to (.)
        result = re.sub(r'->', '.', result)
        
        # Convert array access ['key'] to .key
        result = re.sub(r"\['([^']+)'\]", r'.\1', result)
        result = re.sub(r'\["([^"]+)"\]', r'.\1', result)
        
        # Convert numeric array access [0] to .0 (though this is less common)
        result = re.sub(r'\[(\d+)\]', r'.\1', result)
        
        return result
    
    def process_all_binding_directives(self, content):
        """
        Process both @val and @bind directives in content (they are aliases)
        This method processes both directives in a single pass
        """
        # Process both @val and @bind directives together (they are aliases)
        return self.process_binding_directive(content, 'val|bind')