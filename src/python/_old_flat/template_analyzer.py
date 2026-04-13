"""
Analyzer cho template content và sections
"""

from config import JS_FUNCTION_PREFIX
import re

class TemplateAnalyzer:
    def __init__(self):
        pass
    
    def analyze_sections_info(self, sections, vars_declaration, has_await, has_fetch):
        """Analyze sections and return detailed information"""
        sections_dict = {}  # Use dict to avoid duplicates, key = section_name
        
        if not sections:
            return []
        
        # Extract variable names from vars_declaration
        var_names = []
        if vars_declaration:
            vars_match = re.search(r'let\s*\{\s*([^}]+)\s*\}', vars_declaration)
            if vars_match:
                vars_content = vars_match.group(1)
                for var_part in vars_content.split(','):
                    var_name = var_part.split('=')[0].strip()
                    var_names.append(var_name)
        
        for section in sections:
            # Extract section name and content
            # Support both App.View.section, this.__section, and this.__block
            section_match = re.search(r'(?:App\.View\.section|this\.__section|this\.__block)\([\'"]([^\'"]+)[\'"]', section)
            if not section_match:
                continue
                
            section_name = section_match.group(1)
            
            # Determine section type
            # Long sections contain template string (backtick)
            # Short sections don't contain template string
            if '`' in section:
                # Contains template string -> long section
                section_type = "long"
            else:
                # No template string -> short section
                section_type = "short"
            
            # Check if section uses variables from @vars
            use_vars = False
            if var_names:
                for var_name in var_names:
                    # Check for various patterns that indicate variable usage in compiled JavaScript
                    patterns = [
                        f'${{{var_name}}}',  # Template literal usage
                        f'${{{JS_FUNCTION_PREFIX}.escString({var_name})}}',  # Escaped string
                        f'${{{JS_FUNCTION_PREFIX}.foreach({var_name}',  # Foreach loop
                        f'${{{JS_FUNCTION_PREFIX}.foreach({var_name},',  # Foreach loop with comma
                        f'${{{JS_FUNCTION_PREFIX}.foreach({var_name})',  # Foreach loop with paren
                        f'{JS_FUNCTION_PREFIX}.foreach({var_name}',  # Foreach loop without ${
                        f'{JS_FUNCTION_PREFIX}.foreach({var_name},',  # Foreach loop with comma without ${
                        f'{JS_FUNCTION_PREFIX}.foreach({var_name})',  # Foreach loop with paren without ${
                        f', {var_name})',  # Function parameter
                        f'({var_name})',  # Function call
                        f', {var_name}',  # Array/object property
                        f' {var_name}',  # Space before variable
                        f'{var_name}',  # Direct variable usage
                        f'"{var_name}":',  # Object property
                        f"'{var_name}':",  # Object property with single quotes
                        f'{{"{var_name}":',  # Object with variable
                        f"{{'{var_name}':",  # Object with variable single quotes
                    ]
                    
                    for pattern in patterns:
                        if pattern in section:
                            use_vars = True
                            break
                    if use_vars:
                        break
            
            # Determine preloader
            preloader = use_vars and (has_await or has_fetch)
            
            # Only add or update if not exists, or if current has useVars=True and existing doesn't
            if section_name not in sections_dict:
                sections_dict[section_name] = {
                    "name": section_name,
                    "type": section_type,
                    "useVars": use_vars,
                    "preloader": preloader
                }
            else:
                # Update if current section has useVars=True and existing doesn't
                existing = sections_dict[section_name]
                if use_vars and not existing['useVars']:
                    sections_dict[section_name] = {
                        "name": section_name,
                        "type": section_type,
                        "useVars": use_vars,
                        "preloader": preloader
                    }
        
        # Convert dict back to list
        return list(sections_dict.values())
    
    def analyze_conditional_structures(self, template_content, vars_declaration, has_await, has_fetch):
        """Analyze conditional structures outside sections that use vars and need async data"""
        conditional_info = {
            'has_conditional_with_vars': False,
            'conditional_content': template_content
        }
        
        if not vars_declaration or not (has_await or has_fetch):
            return conditional_info
        
        # Extract variable names from vars_declaration
        var_names = []
        vars_match = re.search(r'let\s*\{\s*([^}]+)\s*\}', vars_declaration)
        if vars_match:
            vars_content = vars_match.group(1)
            for var_part in vars_content.split(','):
                var_name = var_part.split('=')[0].strip()
                var_names.append(var_name)
        
        if not var_names:
            return conditional_info
        
        # Check if template has conditional structures outside sections
        # Look for @if, @elseif, @else, @endif, @switch, @case, @default, @endswitch
        conditional_patterns = [
            r'@if\s*\([^)]+\)',
            r'@elseif\s*\([^)]+\)',
            r'@else',
            r'@endif',
            r'@switch\s*\([^)]+\)',
            r'@case\s*\([^)]+\)',
            r'@default',
            r'@endswitch'
        ]
        
        has_conditionals = any(re.search(pattern, template_content) for pattern in conditional_patterns)
        
        if has_conditionals:
            # Check if any conditional uses variables from @vars
            for var_name in var_names:
                patterns = [
                    f'\${{{var_name}}}',
                    f'\${{{JS_FUNCTION_PREFIX}.escString\({var_name}\)}}',
                    f'\${{{JS_FUNCTION_PREFIX}.foreach\({var_name}',
                    f', {var_name}\)',
                    f'\({var_name}\)',
                    f', {var_name}',
                    f' {var_name}',
                ]
                
                for pattern in patterns:
                    if re.search(pattern, template_content):
                        conditional_info['has_conditional_with_vars'] = True
                        break
                if conditional_info['has_conditional_with_vars']:
                    break
        
        return conditional_info
