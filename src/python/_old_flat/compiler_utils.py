"""
Utility methods cho compiler
"""

import json
from config import JS_FUNCTION_PREFIX

class CompilerUtils:
    def __init__(self):
        pass
    
    def format_fetch_config(self, fetch_config):
        """Format fetch config without extra quotes around template strings"""
        if not fetch_config:
            return 'null'
        
        # Format the config manually to avoid json.dumps adding quotes
        config_parts = []
        for key, value in fetch_config.items():
            if isinstance(value, str):
                # Check if it's a template string (starts with ` and ends with `)
                if value.startswith('`') and value.endswith('`'):
                    # Template string - don't add quotes
                    config_parts.append(f'"{key}": {value}')
                else:
                    # Regular string - add quotes
                    config_parts.append(f'"{key}": "{value}"')
            elif isinstance(value, (dict, list)):
                # Object or array - use json.dumps
                config_parts.append(f'"{key}": {json.dumps(value, ensure_ascii=False)}')
            else:
                # Other types (numbers, booleans, etc.) - use json.dumps
                config_parts.append(f'"{key}": {json.dumps(value, ensure_ascii=False)}')
        
        return '{' + ', '.join(config_parts) + '}'
    
    def format_attrs(self, attrs_dict):
        """Format attributes dictionary to JavaScript object string"""
        import json
        return json.dumps(attrs_dict, separators=(',', ':'))
    
    def format_attributes_to_json(self, attributes):
        """Format attributes dictionary to JSON string for scripts/styles"""
        if not attributes:
            return '{}'
        
        # Handle boolean and string attributes properly
        formatted_attrs = {}
        for key, value in attributes.items():
            if isinstance(value, bool):
                formatted_attrs[key] = value
            else:
                formatted_attrs[key] = str(value)
        
        return json.dumps(formatted_attrs, separators=(',', ':'))
