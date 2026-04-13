"""
Generators cho các functions (render, prerender, init, etc.)
"""

from config import JS_FUNCTION_PREFIX, HTML_ATTR_PREFIX
import re

class FunctionGenerators:
    def __init__(self, is_typescript=False):
        self._is_typescript = is_typescript
    
    def generate_render_function(self, template_content, vars_declaration, extended_view, extends_expression, extends_data, sections_info=None, has_prerender=False, setup_script="", directives_line="", outer_before="", outer_after=""):
        """Generate render function with support for outer content (junk content)"""
        # NOTE: vars_line and directives_line are now handled in wrapper scope
        # No need to call updateVariableData here
        vars_line = ""  # Don't generate vars line anymore
        update_call_line = ""  # Don't call updateVariableData
        view_id_line = "    \n"
        
        # Generate junk content blocks with try-catch for before and after separately
        junk_content_before = ""
        junk_content_after = ""
        junk_var_line = ""
        
        if outer_before and outer_before.strip():
            junk_var_line = "    let __junkContent__ = '';\n"
            junk_content_before = f"""    try {{
        __junkContent__ = `{outer_before}`;
    }} catch(e) {{
        // Ignore junk content errors
    }}
"""
        
        if outer_after and outer_after.strip():
            if not junk_var_line:
                junk_var_line = "    let __junkContent__ = '';\n"
            junk_content_after = f"""    try {{
        __junkContent__ = `{outer_after}`;
    }} catch(e) {{
        // Ignore junk content errors
    }}
"""
        
        # Filter out sections that are already in prerender
        # Only filter if hasPrerender is True (meaning some sections are in prerender)
        filtered_template = template_content
        if sections_info and has_prerender:
            for section in sections_info:
                section_name = section['name']
                use_vars = section.get('useVars', False)
                preloader = section.get('preloader', False)
                section_type = section.get('type', 'long')
                
                # Remove sections that are already in prerender
                # Only remove static sections (not using vars) that are in prerender
                if not use_vars:
                    if section_type == 'short':
                        pattern = fr'\$\{{{JS_FUNCTION_PREFIX}\.section\([\'"]{re.escape(section_name)}[\'"],\s*[^)]+\)\}}'
                        filtered_template = re.sub(pattern, '', filtered_template)
                    else:  # long section
                        pattern = fr'\$\{{{JS_FUNCTION_PREFIX}\.section\([\'"]{re.escape(section_name)}[\'"],\s*\`[^\`]*\`,\s*[\'"]html[\'"]\)\}}'
                        filtered_template = re.sub(pattern, '', filtered_template, flags=re.DOTALL)
                # If section uses vars, keep it in render (dynamic sections)
        
        # Không cần escape template content vì đã được xử lý đúng cách
        filtered_template_escaped = filtered_template
        
        # Replace updateStateByKey('stateKey', value) with update$stateKey(value)
        if directives_line and 'updateStateByKey' in directives_line:
            # Extract all state keys from directives_line
            state_key_matches = re.findall(r"updateStateByKey\('([^']+)'", directives_line)
            for state_key in state_key_matches:
                # Replace updateStateByKey('stateKey', value) with update$stateKey(value)
                pattern = rf"updateStateByKey\('{re.escape(state_key)}',\s*([^)]+)\)"
                replacement = rf"update${state_key}(\1)"
                filtered_template_escaped = re.sub(pattern, replacement, filtered_template_escaped)
        
        # Thêm setup script nhưng loại bỏ useState declarations nếu đã có cơ chế register & update
        setup_line = ""
        if setup_script:
            # Kiểm tra xem có cơ chế register & update không (có updateStateByKey)
            has_register_update = directives_line and 'updateStateByKey' in directives_line
            
            if has_register_update:
                # Nếu có cơ chế register & update, loại bỏ useState declarations từ setup script
                # Chỉ loại bỏ những useState declarations có state key đã được register
                filtered_setup = setup_script
                
                # Tìm tất cả state keys đã được register
                state_keys = []
                if directives_line:
                    # Tìm tất cả updateStateByKey('stateKey', value) trong directives_line
                    matches = re.findall(r"updateStateByKey\('([^']+)'", directives_line)
                    state_keys = matches
                
                # Loại bỏ useState declarations có state key đã được register
                for state_key in state_keys:
                    # Tìm và loại bỏ const [stateKey, setStateKey] = useState(...);
                    pattern = rf'const\s+\[{re.escape(state_key)},\s*[^]]+\]\s*=\s*useState\([^)]+\);'
                    filtered_setup = re.sub(pattern, '', filtered_setup)
                
                # Loại bỏ dòng trống nếu có
                filtered_setup = re.sub(r'^\s*\n', '', filtered_setup)
                
                setup_line = "    " + filtered_setup + "\n" if filtered_setup.strip() else ""
            else:
                # Nếu không có cơ chế register & update, giữ nguyên setup script
                setup_line = "    " + setup_script + "\n"
        
        # Replace App.View.section with this.__section in render function (for short sections)
        filtered_template_escaped = re.sub(r'App\.View\.section\(', 'this.__section(', filtered_template_escaped)
        # Replace App.View.text with this.__text in render function
        filtered_template_escaped = re.sub(r'App\.View\.text\(', 'this.__text(', filtered_template_escaped)
        # Replace App.View.foreach with this.__foreach in render function
        filtered_template_escaped = re.sub(r'App\.View\.foreach\(', 'this.__foreach(', filtered_template_escaped)
        # Add __ prefix to methods that don't have it yet
        filtered_template_escaped = re.sub(r'this\.subscribeBlock\(', 'this.__subscribeBlock(', filtered_template_escaped)
        filtered_template_escaped = re.sub(r'this\.useBlock\(', 'this.__useBlock(', filtered_template_escaped)
        filtered_template_escaped = re.sub(r'this\.showError\(', 'this.__showError(', filtered_template_escaped)
        
        if extended_view:
            data_param = ", " + extends_data if extends_data else ""
            return f"""function(__rc__ = null) {{
            {update_call_line}{view_id_line}{setup_line}    let __outputRenderedContent__ = '';
{junk_var_line}{junk_content_before}            try {{
                __outputRenderedContent__ = `{filtered_template_escaped}`;
            }} catch(e) {{
                if (e instanceof Error) {{
                    __outputRenderedContent__ = this.__showError(e.message);
                }} else {{
                    __outputRenderedContent__ = this.__showError('Unknown error');
                }}
                console.warn(e);
            }}
{junk_content_after}            return this.__extends('{extended_view}'{data_param});
            }}"""
        elif extends_expression:
            data_param = ", " + extends_data if extends_data else ""
            return f"""function(__rc__ = null) {{
            {update_call_line}{view_id_line}{setup_line}    let __outputRenderedContent__ = '';
{junk_var_line}{junk_content_before}            try {{
                __outputRenderedContent__ = `{filtered_template_escaped}`;
            }} catch(e) {{
                if (e instanceof Error) {{
                    __outputRenderedContent__ = this.__showError(e.message);
                }} else {{
                    __outputRenderedContent__ = this.__showError('Unknown error');
                }}
                console.warn(e);
            }}
{junk_content_after}            this.superViewPath = {extends_expression};
            return this.__extends(this.superViewPath{data_param});
            }}"""
        else:
            return f"""function(__rc__ = null) {{
            {update_call_line}{view_id_line}{setup_line}    let __outputRenderedContent__ = '';
{junk_var_line}{junk_content_before}            try {{
                __outputRenderedContent__ = `{filtered_template_escaped}`;
            }} catch(e) {{
                if (e instanceof Error) {{
                    __outputRenderedContent__ = this.__showError(e.message);
                }} else {{
                    __outputRenderedContent__ = this.__showError('Unknown error');
                }}
                console.warn(e);
            }}
{junk_content_after}            return __outputRenderedContent__;
            }}"""
    
    def generate_load_server_data_function(self, vars_declaration="", setup_script="", directives_line=""):
        """Generate loadServerData function - empty function (logic removed)"""
        return "function(__$spaViewData$__ = {}) {}"
    
    def generate_prerender_function(self, has_await, has_fetch, vars_line, view_id_line, template_content, extended_view=None, extends_expression=None, extends_data=None, sections_info=None, conditional_content=None, has_prerender=True):
        """Generate prerender function"""
        # If has_prerender is False, always return null
        if not has_prerender:
            return "function() {\n    return null;\n}"
        
        if not has_await and not has_fetch:
            return "function() {\n    return null;\n}"
        
        # Check if any section uses variables from @vars
        has_sections_with_vars = any(section.get('useVars', False) for section in (sections_info or []))
        
        # Check if there are conditional structures with vars
        has_conditional_with_vars = conditional_content and conditional_content.get('has_conditional_with_vars', False)
        
        # Check if we need preloader
        needs_preloader = (has_sections_with_vars or has_conditional_with_vars) and (has_await or has_fetch)
        
        # Generate prerender content based on sections
        prerender_sections = []
        
        for section in (sections_info or []):
            section_name = section['name']
            use_vars = section.get('useVars', False)
            preloader = section.get('preloader', False)
            section_type = section.get('type', 'long')
            
            if not use_vars:
                # Static sections (không dùng biến) - render trực tiếp trong prerender
                # Không cần đợi fetch/await vì không dùng dynamic data
                if section_type == 'short':
                    # Try both patterns: App.View.section and this.__section
                    section_match = re.search(fr'{JS_FUNCTION_PREFIX}\.section\([\'"]{re.escape(section_name)}[\'"],\s*([^)]+),\s*[\'"]string[\'"]\)', template_content)
                    if not section_match:
                        section_match = re.search(fr'this\.__section\([\'"]{re.escape(section_name)}[\'"],\s*([^)]+),\s*[\'"]string[\'"]\)', template_content)
                    if section_match:
                        section_content = section_match.group(1)
                        # Output using instance method for consistency
                        prerender_sections.append(f"${{this.__section('{section_name}', {section_content}, 'string')}}")
                else:  # long section
                    # Try both patterns: App.View.section and this.__section
                    section_match = re.search(fr'{JS_FUNCTION_PREFIX}\.section\([\'"]{re.escape(section_name)}[\'"],\s*`([^`]+)`,\s*[\'"]html[\'"]\)', template_content, re.DOTALL)
                    if not section_match:
                        section_match = re.search(fr'this\.__section\([\'"]{re.escape(section_name)}[\'"],\s*`([^`]+)`,\s*[\'"]html[\'"]\)', template_content, re.DOTALL)
                    if section_match:
                        section_content = section_match.group(1)
                        # Output using instance method for consistency
                        prerender_sections.append(f"${{this.__section('{section_name}', `{section_content}`, 'html')}}")
            elif preloader and section_type == 'long':
                # Dynamic LONG sections (dùng biến + có fetch/await) - render preloader version
                # Short sections với biến không cần preloader (metadata như title, description)
                # Check if it's a block or section
                is_block = f"this.__block('{section_name}'" in template_content
                
                # Use instance methods for consistency
                if is_block:
                    prerender_sections.append(f"${{this.__block('{section_name}', {{}}, `<div class=\"{HTML_ATTR_PREFIX}preloader\" ref=\"${{__VIEW_ID__}}\" data-view-name=\"${{__VIEW_PATH__}}\">${{this.__text('loading')}}</div>`)}}\n")
                else:
                    prerender_sections.append(f"${{this.__section('{section_name}', `<div class=\"{HTML_ATTR_PREFIX}preloader\" ref=\"${{__VIEW_ID__}}\" data-view-name=\"${{__VIEW_PATH__}}\">${{this.__text('loading')}}</div>`, 'html')}}")
        
        if prerender_sections:
            prerender_content = f"`\n" + '\n'.join(prerender_sections) + "\n`"
        elif has_conditional_with_vars:
            # Has conditional structures with vars but no sections to prerender
            # Just show general preloader - use instance methods
            preloader_html = f'<div class="{HTML_ATTR_PREFIX}preloader" ref="${{__VIEW_ID__}}" data-view-name="${{__VIEW_PATH__}}">${{this.__text(\'loading\')}}</div>'
            prerender_content = f"`{preloader_html}`"
        else:
            # No sections to prerender, use general preloader - use instance methods
            preloader_html = f'<div class="{HTML_ATTR_PREFIX}preloader" ref="${{__VIEW_ID__}}" data-view-name="${{__VIEW_PATH__}}">${{this.__text(\'loading\')}}</div>'
            prerender_content = f"`{preloader_html}`"
        
        if extended_view:
            data_param = ", " + extends_data if extends_data else ""
            # Prerender không khai báo vars - chỉ render với data có sẵn
            return """function() {
""" + view_id_line + """    let __outputRenderedContent__ = '';
            try {
                __outputRenderedContent__ = """ + prerender_content + """;
            } catch(e) {
                __outputRenderedContent__ = this.__showError(e.message);
                console.warn(e);
            }
            return this.__extends('""" + extended_view + """'""" + data_param + """);
            }"""
        elif extends_expression:
            data_param = ", " + extends_data if extends_data else ""
            # Prerender không khai báo vars - chỉ render với data có sẵn
            return """function() {
""" + view_id_line + """    let __outputRenderedContent__ = '';
            try {
                __outputRenderedContent__ = """ + prerender_content + """;
            } catch(e) {
                __outputRenderedContent__ = this.__showError(e.message);
                console.warn(e);
            }
            this.superViewPath = """ + extends_expression + """;
            return this.__extends(this.superViewPath""" + data_param + """);
            }"""
        else:
            # Prerender không khai báo vars - chỉ render với data có sẵn
            return """function() {
""" + view_id_line + """    let __outputRenderedContent__ = '';
            try {
                __outputRenderedContent__ = """ + prerender_content + """;
            } catch(e) {
                __outputRenderedContent__ = this.__showError(e.message);
                console.warn(e);
            }
            return __outputRenderedContent__;
            }"""
    
    def _convert_all_to_scan(self, template_content):
        """Convert all methods to Scan versions"""
        processed_template = template_content
        
        # Replace all methods with Scan versions
        processed_template = re.sub(r'this\.addBlock\(', 'this.__blockScan(', processed_template)
        processed_template = re.sub(r'this\.renderFollowingBlock\(', 'this.__followScan(', processed_template)
        processed_template = re.sub(r'this\.__include\(', 'this.__includeScan(', processed_template)
        processed_template = re.sub(r'this\.__includeif\(', 'this.__includeifScan(', processed_template)
        processed_template = re.sub(r'this\.__includewhen\(', 'this.__includewhenScan(', processed_template)
        processed_template = re.sub(r'this\.__extends\(', 'this.__extendsScan(', processed_template)
        processed_template = re.sub(r'this\.__showError\(', 'this.__showErrorScan(', processed_template)
        # Replace App.View.section with this.__sectionScan (for short sections)
        processed_template = re.sub(r'App\.View\.section\(', 'this.__sectionScan(', processed_template)
        # Replace this.__section with this.__sectionScan (for long sections)
        processed_template = re.sub(r'this\.__section\(', 'this.__sectionScan(', processed_template)
        # Replace App.View.text with this.__textScan
        processed_template = re.sub(r'App\.View\.text\(', 'this.__textScan(', processed_template)
        # Replace this.__text with this.__textScan
        processed_template = re.sub(r'this\.__text\(', 'this.__textScan(', processed_template)
        # Replace App.View.foreach with this.__foreachScan
        processed_template = re.sub(r'App\.View\.foreach\(', 'this.__foreachScan(', processed_template)
        # Replace this.__foreach with this.__foreachScan
        processed_template = re.sub(r'this\.__foreach\(', 'this.__foreachScan(', processed_template)
        processed_template = re.sub(r'this\.subscribe\(', 'this.__subscribeScan(', processed_template)
        processed_template = re.sub(r'this\.__subscribe\(', 'this.__subscribeScan(', processed_template)
        processed_template = re.sub(r'this\.__follow\(', 'this.__followScan(', processed_template)
        processed_template = re.sub(r'this\.__block\(', 'this.__blockScan(', processed_template)
        # Replace block-related methods (both with and without __ prefix)
        processed_template = re.sub(r'this\.subscribeBlock\(', 'this.__subscribeBlockScan(', processed_template)
        processed_template = re.sub(r'this\.__subscribeBlock\(', 'this.__subscribeBlockScan(', processed_template)
        processed_template = re.sub(r'this\.useBlock\(', 'this.__useBlockScan(', processed_template)
        processed_template = re.sub(r'this\.__useBlock\(', 'this.__useBlockScan(', processed_template)
        # Replace event-related methods
        processed_template = re.sub(r'this\.__addEventConfig\(', 'this.__addEventConfigScan(', processed_template)
        processed_template = re.sub(r'this\.__addEventQuickHandle\(', 'this.__addEventQuickHandleScan(', processed_template)
        # Replace App.View.renderView with App.View.scanRenderedView
        processed_template = re.sub(r'App\.View\.renderView\(', 'App.View.scanRenderedView(', processed_template)
        
        return processed_template