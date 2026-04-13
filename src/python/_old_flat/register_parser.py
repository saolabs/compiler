"""
Parser cho directive @register với format mới
"""

import re
from config import JS_FUNCTION_PREFIX

class RegisterParser:
    def __init__(self):
        self.register_content = ""
        self.scripts = []
        self.styles = []
        self.userDefined = {}
        self.setup_content = []  # Store setup script content for file top
        self.setup_lang = None  # Store language attribute from <script setup>
    
    def reset(self):
        self.register_content = ""
        self.scripts = []
        self.styles = []
        self.userDefined = {}
        self.setup_content = []  # Reset setup content
        self.setup_lang = None  # Reset lang
        # Reset merged content to prevent data leakage
        if hasattr(self, '_merged_content'):
            delattr(self, '_merged_content')

    def parse_register_content(self, content, view_name=None):
        self.register_content = content
        self.scripts = []
        self.styles = []
        self.userDefined = {}
        self.setup_lang = None  # Reset lang for each parse
        # Reset merged content to prevent data leakage
        if hasattr(self, '_merged_content'):
            delattr(self, '_merged_content')
        
        self._parse_scripts(content)
        self._parse_styles(content)
        
        return self.get_all_data()
    
    def _parse_scripts(self, content):
        processed_exports = set()
        processed_scripts = set()  # Track processed scripts to avoid duplicates
        
        # Find all script tags with attributes - parse once
        all_script_matches = re.finditer(r'<script([^>]*?)>(.*?)</script>', content, re.DOTALL)
        
        for match in all_script_matches:
            attrs_string = match.group(1)
            script_content = match.group(2).strip()
            full_match = match.group(0)
            
            # Skip if already processed
            if full_match in processed_scripts:
                continue
            processed_scripts.add(full_match)
            
            # Check if this is <script setup> tag
            is_setup_script = 'setup' in attrs_string
            
            # Extract lang attribute for script setup (case-insensitive)
            if is_setup_script:
                lang_match = re.search(r'lang=(["\']?)([^"\'\s>]+)\1', attrs_string, re.IGNORECASE)
                if lang_match:
                    lang_value = lang_match.group(2).lower()
                    # Check if it's TypeScript variant
                    if lang_value in ['ts', 'typescript']:
                        self.setup_lang = 'typescript'
            
            # Check if it's external script (has src)
            if 'src=' in attrs_string:
                # External script - handle both regular URLs and Blade syntax
                src_match = re.search(r'src=(["\'])([^"\']*?(?:\{\{[^}]*\}\}[^"\']*?)*[^"\']*?)\1', attrs_string)
                if src_match:
                    src = src_match.group(2)
                    script_obj = {
                        'type': 'src',
                        'src': src
                    }
                    
                    # Parse attributes (exclude src to avoid duplication)
                    attributes = self._parse_attributes(attrs_string, exclude_attrs=['src'])
                    if attributes.get('id'):
                        script_obj['id'] = attributes['id']
                    if attributes.get('className'):
                        script_obj['className'] = attributes['className']
                    if attributes.get('attributes'):
                        script_obj['attributes'] = attributes['attributes']
                    
                    self.scripts.append(script_obj)
            else:
                # Inline script
                if not script_content:
                    continue
                
                # Parse attributes
                script_obj = {
                    'type': 'code',
                    'content': script_content
                }
                
                # Add attributes
                attributes = self._parse_attributes(attrs_string)
                if attributes.get('id'):
                    script_obj['id'] = attributes['id']
                if attributes.get('className'):
                    script_obj['className'] = attributes['className']
                if attributes.get('attributes'):
                    script_obj['attributes'] = attributes['attributes']
                
                # is_setup_script already defined above when we detected <script setup>

                
                export_match = self._find_export_object(script_content)
                remaining_content = script_content
                
                if export_match:
                    obj_content = export_match.group(1)
                    obj_hash = hash(obj_content)
                    
                    if obj_hash not in processed_exports:
                        processed_exports.add(obj_hash)
                        self._extract_to_user_defined(obj_content)
                    
                    # Remove export statement from script content
                    remaining_content = self._remove_export_from_script(script_content, is_setup_script)
                
                # For setup scripts, store remaining content for file top (after removing export)
                if is_setup_script:
                    # For script setup, keep the entire content including export default
                    # The export default will be used in the output
                    if script_content.strip():
                        self.setup_content.append(script_content.strip())
                # For regular scripts, only add if there's remaining content after removing export
                elif remaining_content.strip():
                    script_obj['content'] = remaining_content
                    self.scripts.append(script_obj)
    
    def _parse_styles(self, content):
        # Parse inline styles with attributes
        style_matches = re.finditer(r'<style([^>]*?)>(.*?)</style>', content, re.DOTALL)
        
        for match in style_matches:
            attrs_string = match.group(1)
            css_content = match.group(2).strip()
            
            if css_content:
                style_obj = {
                    'type': 'code',
                    'content': css_content
                }
                
                # Parse attributes
                attributes = self._parse_attributes(attrs_string)
                if attributes.get('id'):
                    style_obj['id'] = attributes['id']
                if attributes.get('className'):
                    style_obj['className'] = attributes['className']
                if attributes.get('attributes'):
                    style_obj['attributes'] = attributes['attributes']
                
                self.styles.append(style_obj)
        
        # Parse external stylesheets with attributes
        # Use a more flexible pattern that handles Blade syntax better
        link_pattern = r'<link([^>]*?)rel=["\']stylesheet["\']([^>]*?)>'
        link_matches = re.finditer(link_pattern, content)
        
        for match in link_matches:
            full_tag = match.group(0)
            attrs_combined = match.group(1) + match.group(2)
            
            # Extract href - handle both regular URLs and Blade syntax
            href_match = re.search(r'href=(["\'])([^"\']*?(?:\{\{[^}]*\}\}[^"\']*?)*[^"\']*?)\1', full_tag)
            if href_match:
                href = href_match.group(2)
                
                style_obj = {
                    'type': 'href',
                    'href': href
                }
                
                # Parse attributes (exclude href and rel to avoid duplication)
                attributes = self._parse_attributes(attrs_combined, exclude_attrs=['href', 'rel'])
                if attributes.get('id'):
                    style_obj['id'] = attributes['id']
                if attributes.get('className'):
                    style_obj['className'] = attributes['className']
                if attributes.get('attributes'):
                    style_obj['attributes'] = attributes['attributes']
                
                self.styles.append(style_obj)
    
    def _parse_attributes(self, attrs_string, exclude_attrs=None):
        """Parse HTML attributes from string and extract id, class, and other attributes"""
        if exclude_attrs is None:
            exclude_attrs = []
        
        result = {
            'id': '',
            'className': '',
            'attributes': {}
        }
        
        if not attrs_string or not attrs_string.strip():
            return result
        
        # Parse attributes using regex
        attr_pattern = r'(\w+(?:-\w+)*)(?:=(["\'])([^"\']*?)\2|(?==|\s|$))'
        matches = re.findall(attr_pattern, attrs_string)
        
        for match in matches:
            attr_name = match[0].strip()
            attr_value = match[2] if len(match) > 2 else None
            
            # Skip excluded attributes
            if attr_name in exclude_attrs:
                continue
            
            if attr_name == 'id':
                result['id'] = attr_value or ''
            elif attr_name == 'class':
                result['className'] = attr_value or ''
            elif attr_name in ['script', 'view', 'component', 'setup', 'defer', 'async']:
                # Boolean attributes - if present without value, set to true
                if attr_value is None or attr_value == '':
                    result['attributes'][attr_name] = True
                else:
                    result['attributes'][attr_name] = attr_value
            else:
                # Other attributes
                if attr_value is None or attr_value == '':
                    # Boolean attribute
                    result['attributes'][attr_name] = True
                else:
                    result['attributes'][attr_name] = attr_value
        
        # Clean up empty attributes
        if not result['attributes']:
            del result['attributes']
        
        return result

    def _remove_export_from_script(self, script_content, is_setup_script=False):
        """Remove export statement from script content and return remaining content"""
        # Remove export statement first
        export_pattern = r'export\s+(?:default\s+)?(\{)'
        match = re.search(export_pattern, script_content, re.DOTALL)
        
        remaining_content = script_content
        
        if match:
            # Find the full export statement including trailing semicolon/newline
            export_start = match.start()
            start_pos = match.start(1)
            brace_count = 1
            end_pos = start_pos + 1
            
            # Find matching closing brace
            while end_pos < len(script_content) and brace_count > 0:
                char = script_content[end_pos]
                if char == '{':
                    brace_count += 1
                elif char == '}':
                    brace_count -= 1
                end_pos += 1
            
            if brace_count == 0:
                # Include trailing semicolon if present
                while end_pos < len(script_content) and script_content[end_pos] in [';', ' ', '\t']:
                    end_pos += 1
                
                # Include trailing newlines
                while end_pos < len(script_content) and script_content[end_pos] in ['\n', '\r']:
                    end_pos += 1
                
                # Remove the export statement
                remaining_content = script_content[:export_start] + script_content[end_pos:]
        
        # For setup scripts, return empty string (all remaining content goes to file top)
        # For regular scripts, remove import statements only (these will be handled at file level)
        if is_setup_script:
            return ""
        else:
            # Remove all import statements (these will be handled at file level)
            import_pattern = r'import\s+.*?(?:from\s+["\'][^"\']*["\']|["\'][^"\']*["\'])\s*;?\s*\n?'
            remaining_content = re.sub(import_pattern, '', remaining_content, flags=re.MULTILINE)
        
        return remaining_content.strip()

    def _remove_only_export_from_script(self, script_content):
        """Remove only export statement from script content, keep everything else including imports"""
        # Remove export statement only
        export_pattern = r'export\s+(?:default\s+)?(\{)'
        match = re.search(export_pattern, script_content, re.DOTALL)
        
        if not match:
            return script_content
        
        # Find the full export statement including trailing semicolon/newline
        export_start = match.start()
        start_pos = match.start(1)
        brace_count = 1
        end_pos = start_pos + 1
        
        # Find matching closing brace
        while end_pos < len(script_content) and brace_count > 0:
            char = script_content[end_pos]
            if char == '{':
                brace_count += 1
            elif char == '}':
                brace_count -= 1
            end_pos += 1
        
        if brace_count == 0:
            # Include trailing semicolon if present
            while end_pos < len(script_content) and script_content[end_pos] in [';', ' ', '\t']:
                end_pos += 1
            
            # Include trailing newlines
            while end_pos < len(script_content) and script_content[end_pos] in ['\n', '\r']:
                end_pos += 1
            
            # Remove the export statement
            remaining_content = script_content[:export_start] + script_content[end_pos:]
            return remaining_content.strip()
        
        return script_content

    def _find_export_object(self, script_content):
        """Find and extract export object with proper brace matching"""
        # Look for export or export default
        export_pattern = r'export\s+(?:default\s+)?(\{)'
        match = re.search(export_pattern, script_content, re.DOTALL)
        
        if not match:
            return None
        
        start_pos = match.start(1)
        brace_count = 1
        end_pos = start_pos + 1
        
        # Find matching closing brace
        while end_pos < len(script_content) and brace_count > 0:
            char = script_content[end_pos]
            if char == '{':
                brace_count += 1
            elif char == '}':
                brace_count -= 1
            end_pos += 1
        
        if brace_count == 0:
            obj_content = script_content[start_pos:end_pos]
            
            class MockMatch:
                def group(self, n):
                    return obj_content
            
            return MockMatch()
        
        return None
    
    def _is_setup_script(self, attributes):
        """Check if script has setup, view, or component attributes"""
        if not attributes or 'attributes' not in attributes:
            return False
        
        script_attributes = attributes['attributes']
        setup_attrs = ['setup', 'view', 'component']
        
        # Check if any setup attribute exists
        return any(attr in script_attributes for attr in setup_attrs)
    
    def _extract_to_user_defined(self, obj_content):
        """Just store the raw object content - bê nguyên vào userDefined"""
        obj_content = obj_content.strip()
        
        # Chỉ cần store raw object content - main_compiler sẽ sử dụng trực tiếp
        # Vì có thể có nhiều exports, ta merge vào một object
        if obj_content.startswith('{') and obj_content.endswith('}'):
            # Remove outer braces và merge content
            inner_content = obj_content[1:-1].strip()
            if inner_content:
                # Nếu đã có userDefined content, merge với dấu phẩy
                if hasattr(self, '_merged_content'):
                    self._merged_content += ",\n    " + inner_content
                else:
                    self._merged_content = inner_content
    
    def get_scripts(self):
        return self.scripts
    
    def get_styles(self):
        return self.styles
    
    def get_user_defined(self):
        return self.userDefined
    
    def get_all_data(self):
        # Get merged lifecycle object
        lifecycle_obj = self.get_lifecycle_obj()
        
        return {
            'scripts': self.scripts,
            'styles': self.styles,
            'userDefined': lifecycle_obj,  # Raw object string
            # Backward compatibility
            'lifecycle': lifecycle_obj,  # main_compiler expects 'lifecycle'
            'setup': self.get_setup_script(),
            'setupContent': self.get_setup_content(),  # Content for file top
            'setupLang': self.setup_lang,  # Language attribute
            'sections': {},
            'css': {
                'inline': self.get_inline_css(),
                'external': self.get_external_css()
            },
            'resources': self.get_resources()
        }
    
    # Backward compatibility methods
    def get_lifecycle_obj(self):
        # Return merged object content như format cũ
        if hasattr(self, '_merged_content') and self._merged_content:
            return "{\n    " + self._merged_content + "\n}"
        return self.userDefined if isinstance(self.userDefined, str) else "{}"
    
    def get_setup_script(self):
        code_scripts = [s['content'] for s in self.scripts if s['type'] == 'code']
        return '\n\n'.join(code_scripts) if code_scripts else ""
    
    def get_setup_content(self):
        """Get setup script content that should go to file top"""
        return '\n\n'.join(self.setup_content) if self.setup_content else ""
    
    def get_section_scripts(self):
        return {}
    
    def get_all_scripts(self, view_name=None):
        return {
            'lifecycle': self.userDefined,
            'setup': self.get_setup_script(),
            'scripts': self.scripts
        }
    
    def get_inline_css(self):
        inline_css = [s['content'] for s in self.styles if s['type'] == 'code']
        return '\n'.join(inline_css) if inline_css else ""
    
    def get_external_css(self):
        return [s['href'] for s in self.styles if s['type'] == 'href']
    
    def get_resources(self):
        resources = []
        
        for script in self.scripts:
            if script['type'] == 'src':
                attrs = {'src': script['src']}
                
                # Add other attributes
                if 'attributes' in script:
                    attrs.update(script['attributes'])
                if 'id' in script and script['id']:
                    attrs['id'] = script['id']
                if 'className' in script and script['className']:
                    attrs['class'] = script['className']
                
                resources.append({
                    'tag': 'script',
                    'uuid': f"script-{len(resources)}",
                    'attrs': attrs
                })
        
        for style in self.styles:
            if style['type'] == 'href':
                attrs = {
                    'rel': 'stylesheet',
                    'href': style['href']
                }
                
                # Add other attributes
                if 'attributes' in style:
                    attrs.update(style['attributes'])
                if 'id' in style and style['id']:
                    attrs['id'] = style['id']
                if 'className' in style and style['className']:
                    attrs['class'] = style['className']
                
                resources.append({
                    'tag': 'link',
                    'uuid': f"link-{len(resources)}",
                    'attrs': attrs
                })
        
        return resources
