"""
Main compiler class tổng hợp tất cả các module
"""

import re
import json
import os
from config import JS_FUNCTION_PREFIX, HTML_ATTR_PREFIX, APP_HELPER_NAMESPACE
from parsers import DirectiveParsers
from template_processor import TemplateProcessor
from template_analyzer import TemplateAnalyzer
from function_generators import FunctionGenerators
from compiler_utils import CompilerUtils
from wrapper_parser import WrapperParser
from register_parser import RegisterParser
from config import ViewConfig
from declaration_tracker import DeclarationTracker
from binding_directive_service import BindingDirectiveService
from style_directive_handler import StyleDirectiveHandler
from show_directive_handler import ShowDirectiveHandler

class BladeCompiler:
    def __init__(self):
        self.parsers = DirectiveParsers()
        self._is_typescript = False  # Initialize TypeScript flag FIRST
        self.template_processor = TemplateProcessor(is_typescript=self._is_typescript)
        self.template_analyzer = TemplateAnalyzer()
        self.function_generators = FunctionGenerators(is_typescript=self._is_typescript)
        self.compiler_utils = CompilerUtils()
        self.wrapper_parser = WrapperParser()
        self.register_parser = RegisterParser()
        self.declaration_tracker = DeclarationTracker()
        self.binding_directive_service = BindingDirectiveService()
        self.style_directive_handler = StyleDirectiveHandler()
        self.show_directive_handler = ShowDirectiveHandler()
        self.view_template = self._load_view_template()
    
    def _load_view_template(self):
        """Load view.js template from compiler/templates/"""
        try:
            # Get path to view.js (relative to this Python file)
            current_dir = os.path.dirname(os.path.abspath(__file__))
            # Go up to src/python/_old_flat/, then to src/, then to templates/
            template_path = os.path.join(current_dir, '..', '..', 'templates', 'view.js')
            template_path = os.path.normpath(template_path)
            
            with open(template_path, 'r', encoding='utf-8') as f:
                return f.read()
        except Exception as e:
            print(f"Warning: Could not load view.js template: {e}")
            return None
    
    def convert_view_path_to_function_name(self, view_path):
        """Convert view path to function name (e.g., WebPagesHome -> WebPagesHome)"""
        # View path đã được format sẵn ở PascalCase từ Node.js wrapper
        # Không cần xử lý thêm, trả về nguyên bản
        return view_path
        
    def compile_blade_to_js(self, blade_code, view_name, function_name=None, factory_function_name=None):
        """Main compiler function"""
        blade_code = blade_code.strip()
        
        # If function_name not provided, generate from view_name
        if function_name is None:
            function_name = self.convert_view_path_to_function_name(view_name)
        
        # If factory_function_name not provided, use function_name
        if factory_function_name is None:
            factory_function_name = function_name
        
        # Reset reactive counter for this view (each view starts from 0)
        self.template_processor.reactive_counter = 0
        
        # ========================================================================
        # PRIORITY 1 (HIGHEST): Process @verbatim...@endverbatim blocks FIRST
        # ========================================================================
        # @verbatim blocks have ABSOLUTE PRIORITY - all content inside is preserved
        # as-is, NO processing, NO compilation, NO escaping, NO directive parsing
        # This must be done before ANY other processing to ensure complete protection
        verbatim_blocks = {}
        verbatim_counter = 0
        
        def protect_verbatim_block(match):
            nonlocal verbatim_counter
            # Store the ENTIRE content between @verbatim and @endverbatim
            # This content will be restored EXACTLY as-is at the end
            content = match.group(1)
            placeholder = f"__VERBATIM_BLOCK_{verbatim_counter}__"
            verbatim_blocks[placeholder] = content
            verbatim_counter += 1
            return placeholder
        
        # Match @verbatim...@endverbatim (case insensitive, multiline)
        verbatim_pattern = r'@verbatim\s*(.*?)\s*@endverbatim'
        blade_code = re.sub(verbatim_pattern, protect_verbatim_block, blade_code, flags=re.DOTALL | re.IGNORECASE)
        
        # ========================================================================
        # ========================================================================
        # PRIORITY 2: Remove @ssr blocks (server-side only) BEFORE processing
        # ========================================================================
        # Everything inside @ssr should be completely skipped (including script setup)
        # This is done AFTER @verbatim to ensure @ssr inside @verbatim is preserved
        # Note: @ssr blocks are already removed in Node.js wrapper (compiler/index.js)
        # but we keep this here as a safety measure for direct Python compiler usage
        
        # Remove @ssr...@endssr and all case variations
        # Matches: @serverside/@endserverside, @serverSide/@endServerSide, @ssr/@endssr, 
        #          @SSR/@endSSR, @useSSR/@enduseSSR, @useSsr/@enduseSsr, etc.
        ssr_pattern = r'@(?:serverside|serverSide|ssr|SSR|useSSR|useSsr)\b[\s\S]*?@end(?:serverside|serverSide|ServerSide|SSR|Ssr|ssr|useSSR|useSsr)\b'
        blade_code = re.sub(ssr_pattern, '', blade_code, flags=re.IGNORECASE)
        
        # ========================================================================
        # PRIORITY 3: Process @register/@endregister blocks BEFORE escaping backticks
        # ========================================================================
        # @register blocks are processed AFTER @verbatim and @ssr to ensure @register inside
        # @verbatim or @ssr is NOT processed. This protects content inside @register blocks
        # from being escaped (they contain raw JavaScript code)
        register_blocks = {}
        register_counter = 0
        
        def protect_register_block(match):
            nonlocal register_counter
            # Get full match including @register and @endregister
            # Note: This will NOT match @register inside @verbatim (already replaced)
            full_content = match.group(0)
            placeholder = f"__REGISTER_BLOCK_{register_counter}__"
            register_blocks[placeholder] = full_content
            register_counter += 1
            return placeholder
        
        # Match @register with optional parameters and @endregister (case insensitive)
        # Also match aliases: @setup/@endsetup, @script/@endscript
        # IMPORTANT: This will NOT match inside @verbatim blocks (already protected)
        register_pattern = r'@(?:register|setup|script)\s*(?:\([^)]*\))?\s*(.*?)\s*@end(?:register|setup|script)'
        blade_code = re.sub(register_pattern, protect_register_block, blade_code, flags=re.DOTALL | re.IGNORECASE)
        
        # ========================================================================
        # Protect <script setup> blocks from backtick escaping
        # ========================================================================
        # <script setup> contains raw JavaScript that should NOT have backticks escaped
        script_setup_blocks = {}
        script_setup_counter = 0
        
        def protect_script_setup_block(match):
            nonlocal script_setup_counter
            full_content = match.group(0)
            placeholder = f"__SCRIPT_SETUP_BLOCK_{script_setup_counter}__"
            script_setup_blocks[placeholder] = full_content
            script_setup_counter += 1
            return placeholder
        
        # Match <script setup>, <script setup lang="ts">, etc
        script_setup_pattern = r'<script\s+setup[^>]*>.*?</script>'
        blade_code = re.sub(script_setup_pattern, protect_script_setup_block, blade_code, flags=re.DOTALL | re.IGNORECASE)
        
        # ========================================================================
        # Escape backticks in blade content ONLY (not in script blocks)
        # ========================================================================
        # This needs to be done AFTER protecting @register and <script setup> blocks
        escape_str = '@@@@@@@@@@@@@@@@@@@@@@@--------------------------------$$$$$$$$$$$$$$$$$$$$$$$$$$'
        blade_code = blade_code.replace('\\`', escape_str)  # Protect already escaped backticks
        blade_code = blade_code.replace('`', '\\`')  # Escape all backticks
        blade_code = blade_code.replace(escape_str, '\\`')  # Restore protected backticks
        
        # Restore <script setup> blocks (with original unescaped content)
        for placeholder, original_content in script_setup_blocks.items():
            blade_code = blade_code.replace(placeholder, original_content)
        
        # Reset parser states to avoid data leakage between views
        if hasattr(self.register_parser, 'reset'):
            self.register_parser.reset()
        # Note: wrapper_parser không reset vì dữ liệu từ wraper.js không thay đổi
        
        # Parse wrapper content
        wrapper_function_content, wrapper_config_content = self.wrapper_parser.parse_wrapper_file()
        
        # Initialize update_functions list for storing update$stateKey functions
        self.update_functions = []
        
        # Remove Blade comments
        blade_code = re.sub(r'{{--.*?--}}', '', blade_code, flags=re.DOTALL)
        
        # Check for directives - support both @await and @await(...)
        has_await = bool('@await' in blade_code and (
            '@await(' in blade_code or  # @await with params
            re.search(r'@await\s*$', blade_code, re.MULTILINE) or  # @await at end of line
            re.search(r'@await\s+', blade_code)  # @await followed by space/newline
        ))
        has_fetch = '@fetch(' in blade_code
        has_subscribe = ('@subscribe(' in blade_code) or re.search(r'@dontsubscribe\b', blade_code, flags=re.IGNORECASE)
        
        # Parse register data EARLY to detect TypeScript
        # Extract unescaped content from @register blocks BEFORE restoring to blade_code
        register_content_unescaped = None
        for placeholder, original_content in register_blocks.items():
            # Extract just the content inside @register...@endregister (without the directives)
            content_match = re.search(r'@(?:register|setup|script)\s*(?:\([^)]*\))?\s*(.*?)\s*@end(?:register|setup|script)', original_content, re.DOTALL | re.IGNORECASE)
            if content_match:
                if register_content_unescaped is None:
                    register_content_unescaped = content_match.group(1)
                else:
                    # If multiple @register blocks, concatenate them
                    register_content_unescaped += "\n" + content_match.group(1)
        
        # Restore @register blocks to blade_code for removal from template
        for placeholder, original_content in register_blocks.items():
            blade_code = blade_code.replace(placeholder, original_content)
        
        # Parse @register directive from blade_code (for removal from template)
        register_content = self.parsers.parse_register(blade_code)
        
        # Parse register data
        register_data = None
        if register_content_unescaped:
            register_data = self.register_parser.parse_register_content(register_content_unescaped, view_name)
        elif register_content:
            register_data = self.register_parser.parse_register_content(register_content, view_name)
        
        # If no @register directive, check for <script setup> tags
        if not register_data:
            setup_match = re.search(r'<script\s+setup[^>]*>(.*?)</script>', blade_code, re.DOTALL | re.IGNORECASE)
            if setup_match:
                full_script_tag = setup_match.group(0)
                register_data = self.register_parser.parse_register_content(full_script_tag, view_name)
        
        # Set TypeScript flag based on register_data
        setup_lang = register_data.get('setupLang') if register_data else None
        self._is_typescript = (setup_lang == 'typescript')
        
        # Update TemplateProcessor with TypeScript flag after we know the language
        self.template_processor._is_typescript = self._is_typescript
        self.template_processor.conditional_handlers._is_typescript = self._is_typescript
        self.template_processor.echo_processor._is_typescript = self._is_typescript
        self.template_processor.loop_handlers._is_typescript = self._is_typescript
        self.function_generators._is_typescript = self._is_typescript
        
        # NEW: Use DeclarationTracker to parse all declarations in order
        all_declarations = self.declaration_tracker.parse_all_declarations(blade_code)
        
        # Generate wrapper declarations from tracked declarations
        wrapper_declarations_code, variable_list, state_declarations = self._generate_wrapper_declarations(all_declarations)
        
        # Parse main components (keep for compatibility, but we'll use DeclarationTracker results)
        extended_view, extends_expression, extends_data = self.parsers.parse_extends(blade_code)
        vars_declaration = self.parsers.parse_vars(blade_code)
        let_declarations = self.parsers.parse_let_directives(blade_code)
        const_declarations = self.parsers.parse_const_directives(blade_code)
        usestate_declarations = self.parsers.parse_usestate_directives(blade_code)
        
        # Extract usestate_variables for event processor
        usestate_variables = self._extract_usestate_variables(usestate_declarations, all_declarations)
        
        # Update template_processor with usestate_variables
        self.template_processor.state_variables = usestate_variables
        self.template_processor.conditional_handlers.state_variables = usestate_variables
        self.template_processor.loop_handlers.state_variables = usestate_variables
        self.template_processor.event_processor.state_variables = usestate_variables
        self.template_processor.echo_processor.state_variables = usestate_variables
        self.template_processor.class_binding_handler.state_variables = usestate_variables
        
        # Parse block directives
        blade_code = self.parsers.parse_block_directives(blade_code)
        blade_code = self.parsers.parse_endblock_directives(blade_code)
        blade_code = self.parsers.parse_useblock_directives(blade_code)
        blade_code = self.parsers.parse_onblock_directives(blade_code)
        
        fetch_config = self.parsers.parse_fetch(blade_code) if has_fetch else None
        subscribe_config = None  # Deprecated: @subscribe directive removed, use @view/:subscribe instead
        init_functions, css_content = self.parsers.parse_init(blade_code)
        view_type_data = self.parsers.parse_view_type(blade_code)
        
        # Note: register_data was already parsed early (before wrapper generation)
        # Here we just need to parse_register for removing it from blade_code
        register_content = self.parsers.parse_register(blade_code)
        
        # Remove script setup/import/imports/scope content from blade_code before processing
        # These should only be used for import statements, not for render function content
        script_types = ['setup', 'import', 'imports', 'scope', 'scoped']
        for script_type in script_types:
            pattern = rf'<script\s+{script_type}[^>]*>.*?</script>'
            blade_code = re.sub(pattern, '', blade_code, flags=re.DOTALL | re.IGNORECASE)
        
        # Remove @viewType directive after parsing
        blade_code = re.sub(r'@viewtype\s*\([^)]*\)', '', blade_code, flags=re.IGNORECASE)
        
        # Remove @await and @fetch directives (they are only flags for compiler, not for output)
        # Support: @await, @await(...), @fetch(...)
        blade_code = re.sub(r'@await\s*(?:\([^)]*\))?\s*', '', blade_code, flags=re.IGNORECASE)
        blade_code = re.sub(r'@fetch\s*\([^)]*\)\s*', '', blade_code, flags=re.IGNORECASE)
        
        # Then remove script setup/import/imports/scope content from register_content for template processing
        if register_content:
            for script_type in script_types:
                pattern = rf'<script\s+{script_type}[^>]*>.*?</script>'
                register_content = re.sub(pattern, '', register_content, flags=re.DOTALL | re.IGNORECASE)
        
        # Remove <blade> wrapper tags (they are only used as container in .sao files)
        blade_code = re.sub(r'<blade\b[^>]*>', '', blade_code, flags=re.IGNORECASE)
        blade_code = re.sub(r'</blade>', '', blade_code, flags=re.IGNORECASE)
        
        # Process template content
        # NOTE: verbatim blocks are already protected as placeholders, so they won't be processed
        template_content, sections = self.template_processor.process_template(blade_code)
        
        # ========================================================================
        # Restore @verbatim blocks - escape backticks for template string safety
        # ========================================================================
        # Verbatim content is restored but backticks must be escaped because
        # template_content will be inserted into a JavaScript template string (backticks)
        # Example: __outputRenderedContent__ = `{template_content}`
        # If verbatim content has backticks, they will break the template string syntax
        # So we escape: ` → \` and \` → \\`
        for placeholder, content in verbatim_blocks.items():
            # Escape backticks and ${} in verbatim content for safe insertion into template string
            # Verbatim content will be inserted into: __outputRenderedContent__ = `{content}`
            # So we need to escape:
            # 1. Backticks: ` → \` (to prevent breaking template string)
            # 2. ${...}: ${ → \${ (to prevent JavaScript interpolation)
            # 3. Already escaped: \` → \\` and \${ → \\${
            
            # Step 1: Protect already escaped sequences
            protected_content = content.replace('\\`', '__ESCAPED_BACKTICK__')
            protected_content = protected_content.replace('\\${', '__ESCAPED_DOLLAR_BRACE__')
            
            # Step 2: Escape unescaped backticks and ${ sequences
            protected_content = protected_content.replace('`', '\\`')
            protected_content = protected_content.replace('${', '\\${')
            
            # Step 3: Restore protected sequences (now double-escaped)
            protected_content = protected_content.replace('__ESCAPED_BACKTICK__', '\\\\`')
            protected_content = protected_content.replace('__ESCAPED_DOLLAR_BRACE__', '\\\\${')
            
            # Replace placeholder with escaped content
            template_content = template_content.replace(placeholder, protected_content)
        
        # Extract wrapper config from template content
        wrapper_config = self._extract_wrapper_config(template_content)
        
        # If wrapper exists, extract inner and outer content (separate before/after)
        outer_before = ''
        outer_after = ''
        if wrapper_config:
            inner_content, outer_before_raw, outer_after_raw = self._extract_wrapper_inner_content(template_content)
            template_content = inner_content
            
            # Filter outer content to only include directives (remove pure HTML)
            outer_before = self._filter_directives_only(outer_before_raw) if outer_before_raw else ''
            outer_after = self._filter_directives_only(outer_after_raw) if outer_after_raw else ''
        
        # Remove __WRAPPER_CONFIG__ and __WRAPPER_END__ from template content
        if wrapper_config:
            # Remove __WRAPPER_END__ marker first
            template_content = re.sub(r'__WRAPPER_END__\s*', '', template_content)
            
            # Use same logic as extraction to handle nested braces for config
            match = re.search(r'__WRAPPER_CONFIG__\s*=\s*', template_content)
            if match:
                start_pos = match.start()
                end_pos = match.end()
                
                # Find the end of the config object
                if end_pos < len(template_content) and template_content[end_pos] == '{':
                    brace_count = 0
                    in_string = False
                    string_char = None
                    
                    for i in range(end_pos, len(template_content)):
                        char = template_content[i]
                        
                        if char in ['"', "'"] and (i == 0 or template_content[i-1] != '\\'):
                            if not in_string:
                                in_string = True
                                string_char = char
                            elif char == string_char:
                                in_string = False
                                string_char = None
                        
                        if not in_string:
                            if char == '{':
                                brace_count += 1
                            elif char == '}':
                                brace_count -= 1
                                if brace_count == 0:
                                    config_end = i + 1
                                    # Include semicolon if present
                                    if config_end < len(template_content) and template_content[config_end] == ';':
                                        config_end += 1
                                    # Remove including trailing whitespace/newline
                                    while config_end < len(template_content) and template_content[config_end] in [' ', '\n', '\r', '\t']:
                                        config_end += 1
                                    template_content = template_content[:start_pos] + template_content[config_end:]
                                    break
        
        # Generate sections info
        sections_info = self.template_analyzer.analyze_sections_info(sections, vars_declaration, has_await, has_fetch)
        
        # Integrate register_data vào sections_info
        if register_data and register_data.get('sections'):
            for section_name, script_obj in register_data['sections'].items():
                # Tìm section trong sections_info list
                section_found = False
                for section_info in sections_info:
                    if section_info.get('name') == section_name:
                        section_info['script'] = script_obj
                        section_found = True
                        break
                
                # Nếu không tìm thấy, thêm section mới
                if not section_found:
                    sections_info.append({
                        'name': section_name,
                        'type': 'short',
                        'preloader': False,
                        'useVars': False,
                        'script': script_obj
                    })
        
        # Analyze conditional structures outside sections
        conditional_content = self.template_analyzer.analyze_conditional_structures(template_content, vars_declaration, has_await, has_fetch)
        
        # Generate components
        vars_line = "    " + vars_declaration + "\n" if vars_declaration else ""
        
        # Combine all directive declarations
        # Collect declarations for render function - chỉ @let và @const, không bao gồm @useState
        all_declarations = []
        if let_declarations:
            all_declarations.append(let_declarations)
        if const_declarations:
            all_declarations.append(const_declarations)
        # Thêm usestate_declarations vào render function để xử lý updateStateByKey
        if usestate_declarations:
            all_declarations.append(usestate_declarations)
        
        directives_line = ""
        if all_declarations:
            # Replace useState declarations with updateRealState calls
            processed_declarations = []
            has_usestate_declarations = False
            for declaration in all_declarations:
                if declaration.strip():
                    # Process each line separately to handle multi-line declarations
                    for line in declaration.split('\n'):
                        if line.strip():
                            # Check if this line contains @useState directive
                            if line.strip().startswith('@useState'):
                                # Handle @useState($value, $stateKey, $setStateKey) format
                                match = re.search(r'@useState\s*\(\s*\$?(\w+)\s*,\s*\$?(\w+)\s*,\s*\$?(\w+)\s*\)', line)
                                if match:
                                    value = match.group(1).strip()
                                    state_key = match.group(2).strip()
                                    set_state_key = match.group(3).strip()
                                    # Remove $ prefix if present
                                    if state_key.startswith('$'):
                                        state_key = state_key[1:]
                                    if set_state_key.startswith('$'):
                                        set_state_key = set_state_key[1:]
                                    if value.startswith('$'):
                                        value = value[1:]
                                    
                                    # Only process valid state keys
                                    if state_key and state_key.isalnum():
                                        # Store update function for later (outside render)
                                        self.update_functions.append(f"    const update${state_key} = (value) => {{")
                                        self.update_functions.append(f"        if(__STATE__.__.canUpdateStateByKey){{")
                                        self.update_functions.append(f"            updateStateByKey('{state_key}', value);")
                                        self.update_functions.append(f"            {state_key} = value;")
                                        self.update_functions.append(f"        }}")
                                        self.update_functions.append(f"    }};")
                                        # Add state initialization (inside render)
                                        processed_declarations.append(f"    update${state_key}({value});")
                                        has_usestate_declarations = True
                                        # Don't add the original line since we already have updateStateByKey
                                    else:
                                        processed_declarations.append(line)
                                else:
                                    # Handle @useState($value) format (simple)
                                    match_simple = re.search(r'@useState\s*\(\s*\$?(\w+)\s*\)', line)
                                    if match_simple:
                                        value = match_simple.group(1).strip()
                                        # Remove $ prefix if present
                                        if value.startswith('$'):
                                            value = value[1:]
                                        # For simple format, we'll skip it as it's just a value without state key
                                        # Don't add the original line since we don't process it
                                    else:
                                        processed_declarations.append(line)
                            else:
                                # Check if this line contains useState destructuring
                                match = re.search(r'\[([^,]+),\s*[^]]+\]\s*=\s*useState\([^)]+\)', line)
                                if match:
                                    state_key = match.group(1).strip()
                                    # Remove $ prefix if present
                                    if state_key.startswith('$'):
                                        state_key = state_key[1:]
                                    # Only process valid state keys
                                    if state_key and state_key.isalnum():
                                        # Extract value from useState(value)
                                        value_match = re.search(r'useState\(([^)]+)\)', line)
                                        value = value_match.group(1).strip() if value_match else 'null'
                                        # Store update function for later (outside render)
                                        self.update_functions.append(f"    const update${state_key} = (value) => {{")
                                        self.update_functions.append(f"        if(__STATE__.__.canUpdateStateByKey){{")
                                        self.update_functions.append(f"            updateStateByKey('{state_key}', value);")
                                        self.update_functions.append(f"            {state_key} = value;")
                                        self.update_functions.append(f"        }}")
                                        self.update_functions.append(f"    }};")
                                        # Add state initialization (inside render)
                                        processed_declarations.append(f"    update${state_key}({value});")
                                        has_usestate_declarations = True
                                        # Don't add the original line since we already have updateStateByKey
                                else:
                                    processed_declarations.append(line)
            
            directives_line = "    " + "\n    ".join(processed_declarations) + "\n"
            
            # Add lockUpdateRealState if there are useState declarations
            if has_usestate_declarations:
                directives_line += "    lockUpdateRealState();\n"
        
        # Process init_functions để tạo updateStateByKey calls từ @onInit
        init_update_functions = []
        init_state_initializations = []
        
        if init_functions:
            for init_code in init_functions:
                for line in init_code.split('\n'):
                    if line.strip():
                        # Check if this line contains @useState directive
                        if line.strip().startswith('@useState'):
                            # Handle @useState($value, $stateKey, $setStateKey) format
                            match = re.search(r'@useState\s*\(\s*\$?(\w+)\s*,\s*\$?(\w+)\s*,\s*\$?(\w+)\s*\)', line)
                            if match:
                                value = match.group(1).strip()
                                state_key = match.group(2).strip()
                                set_state_key = match.group(3).strip()
                                # Remove $ prefix if present
                                if state_key.startswith('$'):
                                    state_key = state_key[1:]
                                if set_state_key.startswith('$'):
                                    set_state_key = set_state_key[1:]
                                if value.startswith('$'):
                                    value = value[1:]
                                
                                # Only process valid state keys
                                if state_key and state_key.isalnum():
                                    # Store update function for later (outside render)
                                    self.update_functions.append(f"    const update${state_key} = (value) => {{")
                                    self.update_functions.append(f"        if(__STATE__.__.canUpdateStateByKey){{")
                                    self.update_functions.append(f"            updateStateByKey('{state_key}', value);")
                                    self.update_functions.append(f"            {state_key} = value;")
                                    self.update_functions.append(f"        }}")
                                    self.update_functions.append(f"    }};")
                                    # Add state initialization (inside render)
                                    init_state_initializations.append(f"    update${state_key}({value});")
                            else:
                                # Handle @useState($value) format (simple)
                                match_simple = re.search(r'@useState\s*\(\s*\$?(\w+)\s*\)', line)
                                if match_simple:
                                    value = match_simple.group(1).strip()
                                    # Remove $ prefix if present
                                    if value.startswith('$'):
                                        value = value[1:]
                                    # For simple format, we'll skip it as it's just a value without state key
                                    # Don't add the original line since we don't process it
                        else:
                            # Pattern 1: [stateKey, setState] = useState(value) - destructuring
                            match = re.search(r'\[([^,]+),\s*[^]]+\]\s*=\s*useState\([^)]+\)', line)
                            if match:
                                state_key = match.group(1).strip()
                                # Remove $ prefix if present
                                if state_key.startswith('$'):
                                    state_key = state_key[1:]
                                # Only process valid state keys
                                if state_key and state_key.isalnum():
                                    # Extract value from useState(value)
                                    value_match = re.search(r'useState\(([^)]+)\)', line)
                                    value = value_match.group(1).strip() if value_match else 'null'
                                    # Store update function for later (outside render)
                                    self.update_functions.append(f"    const update${state_key} = (value) => {{")
                                    self.update_functions.append(f"        if(__STATE__.__.canUpdateStateByKey){{")
                                    self.update_functions.append(f"            updateStateByKey('{state_key}', value);")
                                    self.update_functions.append(f"            {state_key} = value;")
                                    self.update_functions.append(f"        }}")
                                    self.update_functions.append(f"    }};")
                                    # Add state initialization (inside render)
                                    init_state_initializations.append(f"    update${state_key}({value});")
                            else:
                                # Pattern 2: const [stateKey] = useState(value) - simple destructuring
                                match_simple = re.search(r'const\s+\[([^\]]+)\]\s*=\s*useState\(([^)]+)\)', line)
                                if match_simple:
                                    state_key = match_simple.group(1).strip()
                                    value = match_simple.group(2).strip()
                                    if state_key.startswith('$'):
                                        state_key = state_key[1:]
                                    if state_key and state_key.isalnum():
                                        # Store update function for later (outside render)
                                        self.update_functions.append(f"    const update${state_key} = (value) => {{")
                                        self.update_functions.append(f"        if(__STATE__.__.canUpdateStateByKey){{")
                                        self.update_functions.append(f"            {state_key} = value;")
                                        self.update_functions.append(f"        }}")
                                        self.update_functions.append(f"        return updateStateByKey('{state_key}', value);")
                                        self.update_functions.append(f"    }};")
                                        # Add state initialization (inside render)
                                        init_state_initializations.append(f"    update${state_key}({value});")
        
        # Add init state initializations to directives_line (inside render)
        if init_state_initializations:
            if directives_line.strip():
                directives_line += "    " + "\n    ".join(init_state_initializations) + "\n"
            else:
                directives_line = "    " + "\n    ".join(init_state_initializations) + "\n"
            
            # Add lockUpdateRealState if there are useState declarations from init
            if init_state_initializations:
                directives_line += "    lockUpdateRealState();\n"
        
        view_id_line = "    \n"
        
        # Calculate has_prerender - complex logic
        has_prerender = self._calculate_prerender_need(
            has_await, has_fetch, vars_declaration, 
            sections_info, template_content, 
            usestate_declarations, let_declarations, const_declarations
        )
        
        # Process binding directives (@val and @bind)
        template_content = self.binding_directive_service.process_all_binding_directives(template_content)
        
        # Update style and show handlers with state variables
        self.style_directive_handler.state_variables = usestate_variables
        self.show_directive_handler.state_variables = usestate_variables
        
        # Process @style directive
        template_content = self.style_directive_handler.process_style_directive(template_content)
        
        # Process @show directive  
        template_content = self.show_directive_handler.process_show_directive(template_content)
        
        # Generate render function (setup script will be added to view function instead)
        render_function = self.function_generators.generate_render_function(template_content, vars_declaration, extended_view, extends_expression, extends_data, sections_info, has_prerender, "", directives_line, outer_before, outer_after)
        
        # Generate init function
        init_code = '\n    '.join(init_functions) if init_functions else ''
        init_function = "function() { " + init_code + " }"
        
        # Generate prerender function (chỉ sử dụng vars_declaration thuần túy, không có directives)
        # Fix double braces issue by using string formatting instead of f-string
        if vars_declaration:
            prerender_vars_line = "    " + vars_declaration + "\n"
        else:
            prerender_vars_line = ""
        
        
        prerender_func = self.function_generators.generate_prerender_function(has_await, has_fetch, prerender_vars_line, view_id_line, template_content, extended_view, extends_expression, extends_data, sections_info, conditional_content, has_prerender)
        
        # Generate loadServerData function - empty function (logic removed)
        load_server_data_func = self.function_generators.generate_load_server_data_function()
        
        # CSS functions - combine CSS từ @onInit và @register
        combined_css_content = css_content.copy() if css_content else []
        
        # Thêm CSS từ @register
        if register_data and register_data.get('css'):
            css_data = register_data['css']
            
            # Thêm inline CSS
            if css_data.get('inline'):
                combined_css_content.append(css_data['inline'])
            
            # Thêm external CSS links
            if css_data.get('external'):
                for css_url in css_data['external']:
                    combined_css_content.append(f'/* External CSS: {css_url} */')
        
        # CSS functions removed - using scripts/styles arrays instead
        
        # Process sections_info to handle script objects properly
        sections_js_object = {}
        for section in sections_info:
            section_name = section.get('name', '')
            section_config = {
                'type': section.get('type', 'short'),
                'preloader': section.get('preloader', False),
                'useVars': section.get('useVars', False)
            }
            
            # Handle script object - keep as JavaScript object, not JSON string
            if 'script' in section and section['script']:
                section_config['script'] = section['script']  # Keep as JavaScript object string
            else:
                section_config['script'] = '{}'
            
            sections_js_object[section_name] = section_config
        
        # Create JavaScript object string instead of array with proper formatting
        sections_parts = []
        for name, config in sections_js_object.items():
            section_parts = [
                f'"type":"{config["type"]}"',
                f'"preloader":{str(config["preloader"]).lower()}',
                f'"useVars":{str(config["useVars"]).lower()}',
                f'"script":{config["script"]}'
            ]
            section_content = ",\n            ".join(section_parts)
            sections_parts.append(f'        "{name}":{{\n            {section_content}\n        }}')
        
        if sections_parts:
            sections_json = '{\n' + ',\n'.join(sections_parts) + '\n    }'
        else:
            sections_json = '{}'
        
        # Collect long section names for renderLongSections
        render_long_sections = []
        for section in sections_info:
            section_type = section.get('type', 'short')
            if section_type == 'long':
                section_name = section.get('name', '')
                if section_name:
                    render_long_sections.append(f'"{section_name}"')
        
        render_long_sections_json = '[' + ','.join(render_long_sections) + ']'
        
        # Analyze sections in render and prerender functions
        render_sections = self._extract_sections_from_template(template_content, sections_info)
        prerender_sections = self._extract_sections_from_prerender(has_prerender, has_await, has_fetch, sections_info)
        
        render_sections_json = '[' + ','.join([f'"{section}"' for section in render_sections]) + ']'
        prerender_sections_json = '[' + ','.join([f'"{section}"' for section in prerender_sections]) + ']'
        
        if extended_view:
            # Static view name
            super_view_config = "'" + extended_view + "'"
            has_super_view = "true"
        elif extends_expression:
            # Dynamic expression - add to config
            super_view_config = extends_expression
            has_super_view = "true"
        else:
            super_view_config = "null"
            has_super_view = "false"
        
        # Determine view type
        view_type = "view"  # default
        if view_type_data and view_type_data.get('viewType'):
            view_type = view_type_data['viewType']
        
        # Prepare wrapperConfig value for view config (from @wrap directive)
        wrapper_config_value = ""
        if wrapper_config:
            # From @wrap directive - use directly
            wrapper_config_value = wrapper_config
        else:
            # Default config
            wrapper_config_value = "{ enable: false, tag: null, follow: true, attributes: {} }"

        # Normalize legacy `follow` -> `subscribe` in wrapper_config_value.
        # If `subscribe` already present, remove `follow`. Otherwise replace `follow` key with `subscribe`.
        try:
            import re as _re_norm
            if wrapper_config_value and _re_norm.search(r'\bfollow\s*:', wrapper_config_value):
                # If subscribe exists, remove follow occurrence
                if _re_norm.search(r'\bsubscribe\s*:', wrapper_config_value):
                    # Remove follow: ... (handle commas and spacing)
                    wrapper_config_value = _re_norm.sub(r',?\s*follow\s*:\s*(true|false|\[[^\]]*\]|"[^"]*"|\'[^\']*\'|[^,\}]+)\s*,?', wrapper_config_value)
                    # Normalize commas
                    wrapper_config_value = _re_norm.sub(r',\s*,', ',', wrapper_config_value)
                    wrapper_config_value = _re_norm.sub(r',\s*}', '}', wrapper_config_value)
                else:
                    # Replace follow: VALUE with subscribe: VALUE
                    def _replace_follow(m):
                        val = m.group(1)
                        return f'subscribe: {val}'
                    wrapper_config_value = _re_norm.sub(r'\bfollow\s*:\s*(true|false|\[[^\]]*\]|"[^"]*"|\'[^\']*\'|[^,\}]+)', _replace_follow, wrapper_config_value)
        except Exception:
            pass
 
        # Prepare WRAPPER_CONFIG properties to add to view config (from wraper.js)
        # These are separate properties, NOT nested in wrapperConfig
        wrapper_props_line = ""
        if wrapper_config_content:
            config_content = wrapper_config_content.strip()
            # Remove trailing comma if exists
            if config_content.endswith(','):
                config_content = config_content[:-1]
            # Add comma at the end
            wrapper_props_line = "\n        " + config_content.replace('\n', '\n        ') + ","
        # Detect state keys for registration - chỉ từ @let và @const, không từ @useState
        state_keys = self._detect_state_keys(blade_code, let_declarations, const_declarations, "")
        
        # Add wrapper function content to view function
        # Combine wrapper content and declarations first, then add indentation
        combined_content = ""
        if wrapper_function_content:
            combined_content = wrapper_function_content + "\n"
        if wrapper_declarations_code:
            combined_content = combined_content + wrapper_declarations_code + "\n"
        
        wrapper_function_line = self._add_wrapper_content(combined_content)
        
        # Add setup script to top of file (before export function)
        # Collect setup content ONLY from setup scripts (with setup/view/component attributes)
        # Regular scripts should NOT be here - they are in scripts array and will be inserted into DOM when mounted
        setup_script_line = ""
        setup_scripts = []
        
        # Add setup content from setup scripts (imports + remaining code)
        # NOTE: Only scripts with setup/view/component attributes are in setupContent
        # Regular scripts (without these attributes) are in scripts array and should NOT be here
        if register_data and register_data.get('setupContent'):
            setup_content = register_data['setupContent']
            if setup_content and setup_content.strip():
                setup_scripts.append(setup_content)
        
        # ❌ REMOVED: Regular scripts should NOT be added to setup_scripts
        # They are already in scripts array and will be inserted into DOM when mounted
        # Adding them here causes duplicate execution (file-level + DOM insertion)
        
        # Generate View.registerScript() calls for inline scripts
        # This ensures scripts are registered and can be retrieved by view name and key
        script_registrations_line = ""
        script_registrations = []
        script_function_map = {}  # Map script index to function name
        
        if register_data and register_data.get('scripts'):
            scripts_data = register_data['scripts']
            for index, script in enumerate(scripts_data):
                if script['type'] == 'code' and script.get('content', '').strip():
                    # Generate unique function name/key for script wrapper
                    script_function_name = f"__script_{view_name.replace('.', '_')}_{index}"
                    script_function_map[index] = script_function_name
                    
                    # Wrap script content in View.registerScript() call
                    script_content = script['content']
                    # Indent script content for better readability
                    indented_content = '\n'.join('    ' + line if line.strip() else line 
                                                 for line in script_content.split('\n'))
                    
                    script_registration = f"""View.registerScript('{view_name}', '{script_function_name}', function () {{
{indented_content}
}});"""
                    script_registrations.append(script_registration)
        
        if script_registrations:
            script_registrations_line = '\n\n'.join(script_registrations) + "\n\n"
        
        if setup_scripts:
            setup_script_line = '\n\n'.join(setup_scripts) + "\n\n"
        
        # Add lifecycle script to userDefined section (inside function)
        lifecycle_script_line = ""
        if register_data and register_data.get('lifecycle'):
            lifecycle_script = register_data['lifecycle']
            if lifecycle_script and lifecycle_script.strip():
                # Lifecycle script is raw object content - use directly
                lifecycle_script_line = lifecycle_script
        
        # NOTE: State registration is now handled by DeclarationTracker in wrapper_declarations_code
        # No need to add state registration here anymore
        # if state_keys:
        #     # Add useState declarations with registration
        #     usestate_declarations_js = self._generate_usestate_declarations(state_keys)
        #     wrapper_function_line = wrapper_function_line + usestate_declarations_js
        
        # NOTE: Update functions are now handled by DeclarationTracker
        # No need to add update functions here anymore
        # if self.update_functions:
        #     update_functions_js = "\n".join(self.update_functions) + "\n"
        #     wrapper_function_line = wrapper_function_line + update_functions_js
        
        # Prepare userDefined properties từ register_data (extract từ object)
        user_defined_properties = ""
        
        # No TypeScript stripping - if user writes TS without lang="typescript", 
        # that's a user error. We keep code as-is for both .js and .ts output.
        
        if lifecycle_script_line:
            # lifecycle_script_line is already the object content (e.g., "init() {...}, mounted() {...}")
            # Remove outer braces if present and extract content
            content = lifecycle_script_line.strip()
            if content.startswith('{') and content.endswith('}'):
                # Extract content between braces
                user_defined_properties = content[1:-1].strip()
            else:
                # Already extracted content
                user_defined_properties = content
        
        # Setup imports will be handled in build.py, not here
        
        # Add resources array từ register_data
        resources_line = ""
        if register_data and register_data.get('resources'):
            resources_data = register_data['resources']
            resources_json_parts = []
            
            for resource in resources_data:
                # Process Blade syntax in attrs
                processed_attrs = {}
                has_template_strings = False
                
                for key, value in resource["attrs"].items():
                    if isinstance(value, str) and '{{' in value and '}}' in value:
                        # Convert Blade syntax to template string
                        processed_value = self._convert_blade_to_template_string(value)
                        processed_attrs[key] = f'`{processed_value}`'
                        has_template_strings = True
                    else:
                        processed_attrs[key] = value
                
                # Format resource object
                if has_template_strings:
                    # Build manually to handle template strings
                    attrs_parts = []
                    for key, value in processed_attrs.items():
                        if isinstance(value, str) and value.startswith('`') and value.endswith('`'):
                            # Template string - don't quote
                            attrs_parts.append(f'"{key}":{value}')
                        else:
                            # Regular value - use JSON format
                            attrs_parts.append(f'"{key}":"{value}"')
                    
                    attrs_str = '{' + ','.join(attrs_parts) + '}'
                    resource_parts = [
                        f'"tag":"{resource["tag"]}"',
                        f'"uuid":"{resource["uuid"]}"',
                        f'"attrs":{attrs_str}'
                    ]
                    resources_json_parts.append('{' + ','.join(resource_parts) + '}')
                else:
                    # No template strings - use regular JSON format
                    resource_parts = [
                        f'"tag":"{resource["tag"]}"',
                        f'"uuid":"{resource["uuid"]}"',
                        f'"attrs":{self.compiler_utils.format_attrs(resource["attrs"])}'
                    ]
                    resources_json_parts.append('{' + ','.join(resource_parts) + '}')
            
            # Handle template strings in final output
            if any('`' in part for part in resources_json_parts):
                resources_line = "\n        resources: [" + ','.join(resources_json_parts) + "]"
            else:
                resources_json = '[' + ','.join(resources_json_parts) + ']'
                resources_line = "\n        resources: " + resources_json
        else:
            resources_line = "\n        resources: []"
        
        # Add scripts array từ register_data
        # Use function wrapper for inline scripts to avoid JSON escaping issues
        scripts_line = ""
        if register_data and register_data.get('scripts'):
            scripts_data = register_data['scripts']
            scripts_json_parts = []
            
            for index, script in enumerate(scripts_data):
                script_parts = [f'"type":"{script["type"]}"']
                
                if script['type'] == 'code':
                    # Use function wrapper instead of content string
                    if index in script_function_map:
                        script_function_name = script_function_map[index]
                        script_parts.append(f'"function":"{script_function_name}"')
                    else:
                        # Fallback: use content if function not generated
                        content_escaped = script["content"].replace('"', '\\"').replace('\n', '\\n')
                        script_parts.append(f'"content":"{content_escaped}"')
                elif script['type'] == 'src':
                    # Process Blade syntax in src
                    src_value = script["src"]
                    if '{{' in src_value and '}}' in src_value:
                        # Convert Blade syntax to template string
                        processed_src = self._convert_blade_to_template_string(src_value)
                        script_parts.append(f'"src":`{processed_src}`')
                    else:
                        # Regular string
                        script_parts.append(f'"src":"{src_value}"')
                
                # Add id, className, attributes
                if script.get('id'):
                    script_parts.append(f'"id":"{script["id"]}"')
                if script.get('className'):
                    script_parts.append(f'"className":"{script["className"]}"')
                if script.get('attributes'):
                    attrs_json = self.compiler_utils.format_attributes_to_json(script['attributes'])
                    script_parts.append(f'"attributes":{attrs_json}')
                
                scripts_json_parts.append('{' + ','.join(script_parts) + '}')
            
            # Use special handling for template strings - don't put in JSON string
            if any('`' in part for part in scripts_json_parts):
                # Build JavaScript array manually to handle template strings
                scripts_line = "\n        scripts: [" + ','.join(scripts_json_parts) + "]"
            else:
                scripts_json = '[' + ','.join(scripts_json_parts) + ']'
                scripts_line = "\n        scripts: " + scripts_json
        else:
            scripts_line = "\n        scripts: []"
        
        # Add styles array từ register_data  
        styles_line = ""
        if register_data and register_data.get('styles'):
            styles_data = register_data['styles']
            styles_json_parts = []
            
            for style in styles_data:
                style_parts = [f'"type":"{style["type"]}"']
                
                if style['type'] == 'code':
                    content_escaped = style["content"].replace('"', '\\"').replace('\n', '\\n')
                    style_parts.append(f'"content":"{content_escaped}"')
                elif style['type'] == 'href':
                    # Process Blade syntax in href
                    href_value = style["href"]
                    if '{{' in href_value and '}}' in href_value:
                        # Convert Blade syntax to template string
                        processed_href = self._convert_blade_to_template_string(href_value)
                        style_parts.append(f'"href":`{processed_href}`')
                    else:
                        # Regular string
                        style_parts.append(f'"href":"{href_value}"')
                
                # Add id, className, attributes
                if style.get('id'):
                    style_parts.append(f'"id":"{style["id"]}"')
                if style.get('className'):
                    style_parts.append(f'"className":"{style["className"]}"')
                if style.get('attributes'):
                    attrs_json = self.compiler_utils.format_attributes_to_json(style['attributes'])
                    style_parts.append(f'"attributes":{attrs_json}')
                
                styles_json_parts.append('{' + ','.join(style_parts) + '}')
            
            # Use special handling for template strings - don't put in JSON string
            if any('`' in part for part in styles_json_parts):
                # Build JavaScript array manually to handle template strings
                styles_line = "\n        styles: [" + ','.join(styles_json_parts) + "]"
            else:
                styles_json = '[' + ','.join(styles_json_parts) + ']'
                styles_line = "\n        styles: " + styles_json
        else:
            styles_line = "\n        styles: []"
        
        # If wrapperConfig contains a `subscribe` property and no explicit subscribe_config
        # was provided via other directives, read it but keep the property inside
        # `wrapper_config_value` (do not remove it). This preserves subscribe inside
        # wrapperConfig while still allowing top-level `subscribe` detection when needed.
        if subscribe_config is None and wrapper_config_value:
            try:
                import re as _re_sub
                m = _re_sub.search(r'\bsubscribe\s*:\s*(true|false|\[[^\]]*\])', wrapper_config_value)
                if m:
                    wrapper_subscribe_val = m.group(1)
                    # Set subscribe_config from wrapper but do NOT remove it from wrapper_config_value
                    if wrapper_subscribe_val in ('true', 'false'):
                        subscribe_config = (wrapper_subscribe_val == 'true')
                    else:
                        inner = wrapper_subscribe_val.strip()[1:-1].strip()
                        if inner:
                            parts = [p.strip().strip('"\'') for p in inner.split(',') if p.strip()]
                            subscribe_config = parts
                        else:
                            subscribe_config = []
            except Exception:
                pass

        # If wrapper exists and no explicit subscribe/dontsubscribe directive was provided,
        # default to enabling subscribe and ensure wrapper_config_value contains it.
        if wrapper_config and subscribe_config is None and wrapper_config_value:
            try:
                import re as _re_ins
                if not _re_ins.search(r'\bsubscribe\s*:', wrapper_config_value):
                    # Insert subscribe: true right after opening brace
                    wrapper_config_value = _re_ins.sub(r'^\s*\{', '{ subscribe: true,', wrapper_config_value, count=1)
                    # Also set top-level subscribe_config to True for consistency
                    subscribe_config = True
            except Exception:
                pass

        # Build the return string carefully to avoid syntax errors
        # Build subscribe config JS value
        # Optimization: If no vars/useState and no explicit subscribe config, default to false
        if subscribe_config is None:
            # Check if view uses vars or state
            has_vars = bool(vars_declaration and vars_declaration.strip())
            has_state = bool(state_keys)  # state_keys detected from @let/@const/@useState
            
            # If no state/vars used and no explicit subscribe directive, default to false
            # Reasoning: without state/vars, re-rendering won't change anything
            if not has_vars and not has_state:
                subscribe_js = 'false'
            else:
                subscribe_js = 'true'
        elif isinstance(subscribe_config, bool):
            subscribe_js = 'true' if subscribe_config else 'false'
        else:
            subscribe_js = json.dumps(subscribe_config, ensure_ascii=False)

        # If an explicit subscribe/dontsubscribe directive was provided,
        # ensure wrapperConfig.subscribe mirrors that value so runtime
        # logic (which reads wrapperConfig) remains consistent.
        if wrapper_config_value and subscribe_config is not None:
            try:
                import re as _re_fix
                if _re_fix.search(r'\bsubscribe\s*:', wrapper_config_value):
                    wrapper_config_value = _re_fix.sub(r'subscribe\s*:\s*[^,}]+', f'subscribe: {subscribe_js}', wrapper_config_value, count=1)
                else:
                    wrapper_config_value = _re_fix.sub(r'^\s*\{', '{ subscribe: ' + subscribe_js + ',', wrapper_config_value, count=1)
            except Exception:
                pass

        # Always add imports from saola (not onelaraveljs)
        # Calculate __VIEW_NAMESPACE__ (view path without filename)
        view_parts = view_name.split('.')
        view_namespace = '.'.join(view_parts[:-1]) + '.' if len(view_parts) > 1 else ''
        
        # Class name is function_name + "View"
        class_name = function_name + "View"
        
        # Use template if available, otherwise fallback to hardcoded template
        if self.view_template:
            # Read template and replace placeholders
            return_template = self.view_template
            
            # Replace [COMPONENT_NAME]
            return_template = return_template.replace('[COMPONENT_NAME]', function_name)
            
            # Replace [FACTORY_FUNCTION_NAME]
            return_template = return_template.replace('[FACTORY_FUNCTION_NAME]', factory_function_name)
            
            # Replace view constants
            return_template = return_template.replace("const __VIEW_PATH__ = 'admin.pages.users';", 
                                                     f"const __VIEW_PATH__ = '{view_name}';")
            return_template = return_template.replace("const __VIEW_NAMESPACE__ = 'admin.pages.';",
                                                     f"const __VIEW_NAMESPACE__ = '{view_namespace}';")
            return_template = return_template.replace("const __VIEW_TYPE__ = 'view';",
                                                     f"const __VIEW_TYPE__ = '{view_type}';")
            
            # Build __VIEW_CONFIG__ object
            view_config_content = """hasSuperView: """ + has_super_view + """,
    viewType: '""" + view_type + """',
    sections: """ + sections_json + """,
    wrapperConfig: """ + wrapper_config_value + """,""" + wrapper_props_line + """
    hasAwaitData: """ + str(has_await).lower() + """,
    hasFetchData: """ + str(has_fetch).lower() + """,
    usesVars: """ + str(bool(vars_declaration)).lower() + """,
    hasSections: """ + str(bool(sections)).lower() + """,
    hasSectionPreload: """ + str(any(section.get('preloader', False) for section in sections_info)).lower() + """,
    hasPrerender: """ + str(has_prerender).lower() + """,
    renderLongSections: """ + render_long_sections_json + """,
    renderSections: """ + render_sections_json + """,
    prerenderSections: """ + prerender_sections_json
            
            # Replace [VIEW_CONFIG_PLACEHOLDER]
            return_template = return_template.replace('[VIEW_CONFIG_PLACEHOLDER]', view_config_content)
            
            # Build wrapper content WITHOUT extra indentation (template has correct structure)
            # Combine wrapper_function_content and wrapper_declarations_code
            raw_wrapper_content = ""
            if wrapper_function_content:
                raw_wrapper_content = wrapper_function_content + "\n"
            if wrapper_declarations_code:
                raw_wrapper_content = raw_wrapper_content + wrapper_declarations_code + "\n"
            
            # Build setup config content (tất cả options cho setup)
            # Check if TypeScript for type annotations
            setup_lang = register_data.get('setupLang') if register_data else None
            is_typescript = (setup_lang == 'typescript')
            
            # Add types for TypeScript
            data_param = 'data: any' if is_typescript else 'data'
            key_value_params = 'key: string, value: any' if is_typescript else 'key, value'
            
            setup_config_content = """superView: """ + super_view_config + """,
        subscribe: """ + subscribe_js + """,
        fetch: """ + (self.compiler_utils.format_fetch_config(fetch_config) if fetch_config else 'null') + """,
        data: __data__,
        viewId: __VIEW_ID__,
        path: __VIEW_PATH__,""" + scripts_line + """,""" + styles_line + """,""" + resources_line + """,
        commitConstructorData: function() {
            // Then update states from data
            """ + self._generate_state_updates(state_declarations) + """
            // Finally lock state updates
            """ + ("lockUpdateRealState();" if state_declarations else "") + """
        },
        updateVariableData: function(""" + data_param + """) {
            // Update all variables first
            for (const key in data) {
                if (data.hasOwnProperty(key)) {
                    // Call updateVariableItemData directly from config
                    if (typeof this.config.updateVariableItemData === 'function') {
                        this.config.updateVariableItemData.call(this, key, data[key]);
                    }
                }
            }
            // Then update states from data
            """ + self._generate_state_updates(state_declarations) + """
            // Finally lock state updates
            """ + ("lockUpdateRealState();" if state_declarations else "") + """
        },
        updateVariableItemData: function(""" + key_value_params + """) {
            this.data[key] = value;
            if (typeof __UPDATE_DATA_TRAIT__[key] === "function") {
                __UPDATE_DATA_TRAIT__[key](value);
            }
        },
        prerender: """ + prerender_func + """,
        render: """ + render_function + """"""
            
            # Build wrapper content with raw_wrapper_content only
            wrapper_content = raw_wrapper_content
            
            # wrapper_content needs proper 8-space indentation for $__setup__ content
            # wrapper_declarations_code comes with 4-space indent, need to add 4 more spaces
            if wrapper_content.strip():
                lines = wrapper_content.split('\n')
                indented_lines = []
                for line in lines:
                    if line.strip():
                        # Add 4 more spaces to existing indent (4 -> 8)
                        indented_lines.append('    ' + line)
                    else:
                        indented_lines.append('')
                wrapper_content = '\n'.join(indented_lines)
            
            # user_defined_properties: content already has 4-space indent from register_parser
            # Need to change from 4 spaces to 12 spaces (for object properties inside setUserDefined)
            # Also preserve extra indent for nested code (e.g., function bodies)
            if user_defined_properties.strip():
                lines = user_defined_properties.split('\n')
                adjusted_lines = []
                for line in lines:
                    if line.strip():
                        # Count leading spaces
                        leading_spaces = len(line) - len(line.lstrip())
                        # Content has 4-space base indent, need to change to 12
                        # If line has 4 spaces, convert to 12
                        # If line has 8 spaces (4 base + 4 nested), convert to 16 (12 base + 4 nested)
                        if leading_spaces >= 4:
                            extra_spaces = leading_spaces - 4
                            adjusted_lines.append('            ' + (' ' * extra_spaces) + line.lstrip())
                        else:
                            # Less than 4 spaces (shouldn't happen but handle it)
                            adjusted_lines.append('            ' + line.lstrip())
                    else:
                        adjusted_lines.append('')
                user_defined_properties = '\n'.join(adjusted_lines)
            
            # setup_config_content: has 8-space indent, change to 12 spaces
            # Preserves internal relative indent (e.g., function body with 12 becomes 16)
            lines = setup_config_content.split('\n')
            adjusted_lines = []
            for line in lines:
                if line.strip():
                    # Count leading spaces
                    leading_spaces = len(line) - len(line.lstrip())
                    # Replace first 8 spaces with 12 spaces, keep the rest
                    if leading_spaces >= 8:
                        extra_spaces = leading_spaces - 8
                        adjusted_lines.append('            ' + (' ' * extra_spaces) + line.lstrip())
                    else:
                        # Less than 8 spaces, just add 12
                        adjusted_lines.append('            ' + line.lstrip())
                else:
                    adjusted_lines.append('')
            setup_config_content = '\n'.join(adjusted_lines)
            
            # Replace placeholders in template
            # The template already has the correct structure, just fill in the placeholders
            return_template = return_template.replace('[COMPONENT_DECLARE_VARIABLES_AND_STATES]', wrapper_content)
            return_template = return_template.replace('[USER_DEFINED_PROPERTIES_PLACEHOLDER]', user_defined_properties)
            return_template = return_template.replace('[VIEW_SETUP_CONFIG_PLACEHOLDER]', setup_config_content)
            
            # Parse setup_script_line to extract imports and remaining code
            script_imports = ""
            script_contents = ""
            
            if setup_script_line and setup_script_line.strip():
                # Keep ALL content as-is for both .ts and .js output
                # If user writes TypeScript without lang="typescript", that's their error
                # We don't try to strip types - too risky and error-prone
                
                # Just separate imports from other code
                lines = setup_script_line.split('\n')
                import_lines = []
                other_lines = []
                i = 0
                
                while i < len(lines):
                    line = lines[i]
                    stripped = line.strip()
                    
                    # Skip export default block (already extracted to userDefined)
                    if stripped.startswith('export default'):
                        # Find the entire export default block
                        if '{' in line:
                            brace_count = line.count('{') - line.count('}')
                            i += 1
                            
                            # Skip until we find the closing brace
                            while i < len(lines) and brace_count > 0:
                                block_line = lines[i]
                                brace_count += block_line.count('{') - block_line.count('}')
                                i += 1
                            continue
                        else:
                            # export default someVar; (single line)
                            i += 1
                            continue
                    
                    # Check if this is a multi-line interface or type declaration
                    elif (stripped.startswith('export interface ') or 
                        stripped.startswith('interface ') or
                        stripped.startswith('export type ') or
                        (stripped.startswith('type ') and '=' in stripped)):
                        
                        # Collect the entire block until closing brace
                        import_lines.append(line)
                        
                        # If line doesn't end with }, need to collect more lines
                        if '{' in line and not stripped.endswith('}'):
                            brace_count = line.count('{') - line.count('}')
                            i += 1
                            
                            while i < len(lines) and brace_count > 0:
                                block_line = lines[i]
                                import_lines.append(block_line)
                                brace_count += block_line.count('{') - block_line.count('}')
                                i += 1
                            continue
                        elif not '{' in line:
                            # Single line type alias: type X = Y;
                            i += 1
                            continue
                    
                    # Regular import statements
                    elif stripped.startswith('import '):
                        import_lines.append(line)
                    
                    # Other exports (not default)
                    elif stripped.startswith('export ') and not stripped.startswith('export default'):
                        import_lines.append(line)
                    
                    # Empty lines
                    elif stripped == '':
                        # Empty lines follow the section they're in
                        if import_lines and not other_lines:
                            import_lines.append(line)
                        else:
                            other_lines.append(line)
                    
                    # Other code
                    else:
                        other_lines.append(line)
                    
                    i += 1
                
                if import_lines:
                    script_imports = '\n'.join(import_lines)
                if other_lines:
                    script_contents = '\n'.join(other_lines)
            
            # Replace [COMPONENT_IMPORTS] - if empty, remove the placeholder line
            if script_imports.strip():
                return_template = return_template.replace('[COMPONENT_IMPORTS]', script_imports)
            else:
                # Remove placeholder and its newline
                return_template = return_template.replace('[COMPONENT_IMPORTS]\n', '')
                return_template = return_template.replace('[COMPONENT_IMPORTS]', '')
            
            # Replace [COMPONENT_SCRIPT_CONTENTS] - if empty, remove the placeholder line
            if script_contents.strip():
                return_template = return_template.replace('[COMPONENT_SCRIPT_CONTENTS]', script_contents)
            else:
                # Remove placeholder and its newline
                return_template = return_template.replace('[COMPONENT_SCRIPT_CONTENTS]\n', '')
                return_template = return_template.replace('[COMPONENT_SCRIPT_CONTENTS]', '')
            
            # Add script_registrations_line at the beginning (not setup_script_line anymore)
            return_template = script_registrations_line + return_template
            
        else:
            # Fallback to old hardcoded template
            return_template = setup_script_line + script_registrations_line + """import { View } from 'saola';
import { app } from 'saola';

// nều có code trước export default trong script setup thì thêm vào đây

const __VIEW_PATH__ = '""" + view_name + """';
const __VIEW_NAMESPACE__ = '""" + view_namespace + """';
const __VIEW_TYPE__ = '""" + view_type + """';

class """ + class_name + """ extends View {
    $__config__ = {};
    constructor(App, systemData) {
        super(__VIEW_PATH__, __VIEW_TYPE__);
        this.__ctrl__.setApp(App);
    }

    $__setup__(__data__, systemData) {
        const App = this.__ctrl__.App;
        const __STATE__ = this.__ctrl__.states;
        const {__base__, __layout__, __page__, __component__, __template__, __context__, __partial__, __system__, __env = {}, __helper = {}} = systemData;
        const __VIEW_ID__ = __data__.__SSR_VIEW_ID__ || """ + JS_FUNCTION_PREFIX + """.generateViewId();
        """ + wrapper_function_line + """
    this.__ctrl__.setUserDefined({
        """ + user_defined_properties + """
    });
    this.__ctrl__.setup({
        superView: """ + super_view_config + """,
        hasSuperView: """ + has_super_view + """,
        viewType: '""" + view_type + """',
        sections: """ + sections_json + """,
        wrapperConfig: """ + wrapper_config_value + """,""" + wrapper_props_line + """
        hasAwaitData: """ + str(has_await).lower() + """,
        hasFetchData: """ + str(has_fetch).lower() + """,
        subscribe: """ + subscribe_js + """,
        fetch: """ + (self.compiler_utils.format_fetch_config(fetch_config) if fetch_config else 'null') + """,
        data: __data__,
        viewId: __VIEW_ID__,
        path: __VIEW_PATH__,
        usesVars: """ + str(bool(vars_declaration)).lower() + """,
        hasSections: """ + str(bool(sections)).lower() + """,
        hasSectionPreload: """ + str(any(section.get('preloader', False) for section in sections_info)).lower() + """,
        hasPrerender: """ + str(has_prerender).lower() + """,
        renderLongSections: """ + render_long_sections_json + """,
        renderSections: """ + render_sections_json + """,
        prerenderSections: """ + prerender_sections_json + """,""" + scripts_line + """,""" + styles_line + """,""" + resources_line + """,
        commitConstructorData: function() {
            // Then update states from data
            """ + self._generate_state_updates(state_declarations) + """
            // Finally lock state updates
            """ + ("lockUpdateRealState();" if state_declarations else "") + """
        },
        updateVariableData: function(data) {
            // Update all variables first
            for (const key in data) {
                if (data.hasOwnProperty(key)) {
                    // Call updateVariableItemData directly from config
                    if (typeof this.config.updateVariableItemData === 'function') {
                        this.config.updateVariableItemData.call(this, key, data[key]);
                    }
                }
            }
            // Then update states from data
            """ + self._generate_state_updates(state_declarations) + """
            // Finally lock state updates
            """ + ("lockUpdateRealState();" if state_declarations else "") + """
        },
        updateVariableItemData: function(key, value) {
            this.data[key] = value;
            if (typeof __UPDATE_DATA_TRAIT__[key] === "function") {
                __UPDATE_DATA_TRAIT__[key](value);
            }
        },
        prerender: """ + prerender_func + """,
        render: """ + render_function + """
    });
    return self;
    }
}

// Export factory function
export function """ + function_name + """(__data__ = {}, systemData = {}) {
    const App = app.make("App");
    const view = new """ + class_name + """(App, systemData);
    view.$__setup__(__data__, systemData);
    return view;
}"""
        
        # Restore verbatim blocks in final return_template (in case any were in scripts/styles)
        # For scripts/styles, we also need to escape backticks and ${} if they're in template strings
        # Escape to prevent breaking template string syntax
        for placeholder, content in verbatim_blocks.items():
            # Escape backticks and ${} in verbatim content for safe insertion into template strings
            # Step 1: Protect already escaped sequences
            protected_content = content.replace('\\`', '__ESCAPED_BACKTICK__')
            protected_content = protected_content.replace('\\${', '__ESCAPED_DOLLAR_BRACE__')
            
            # Step 2: Escape unescaped backticks and ${ sequences
            protected_content = protected_content.replace('`', '\\`')
            protected_content = protected_content.replace('${', '\\${')
            
            # Step 3: Restore protected sequences (now double-escaped)
            protected_content = protected_content.replace('__ESCAPED_BACKTICK__', '\\\\`')
            protected_content = protected_content.replace('__ESCAPED_DOLLAR_BRACE__', '\\\\${')
            
            return_template = return_template.replace(placeholder, protected_content)
        
        # Handle type markers based on language
        setup_lang = register_data.get('setupLang') if register_data else None
        if setup_lang == 'typescript':
            # TypeScript: Convert markers to actual types
            return_template = self._process_type_markers_for_typescript(return_template)
            # NOTE: No longer need regex post-processing for types since they're added during generation
            # return_template = self._add_typescript_annotations(return_template, class_name, function_name)
        else:
            # JavaScript: Remove all type markers
            return_template = self._remove_type_markers_for_javascript(return_template)
        
        return return_template
    
    def _typed(self, plain, typed=None):
        """Helper method to add TypeScript types"""
        if not self._is_typescript:
            return plain
        # If typed version provided, use it; otherwise add ': any' to parameters
        if typed:
            return typed
        # Auto-add ': any' to simple parameters
        params = [p.strip() for p in plain.split(',')]
        typed_params = [f"{p}: any" if p else p for p in params]
        return ', '.join(typed_params)
    
    def _add_typescript_annotations(self, code, class_name, function_name):
        """Add TypeScript type annotations to generated code
        
        DEPRECATED: This function is no longer used. Types are now added during code generation
        using conditional logic based on self._is_typescript flag. Keeping this for reference only.
        """
        
        # Add type to ViewController constructor
        code = re.sub(
            r'constructor\(view\)',
            r'constructor(view: any)',
            code
        )
        
        # Add types to View class constructor  
        code = re.sub(
            r'constructor\(App, systemData\)',
            r'constructor(App: any, systemData: any)',
            code
        )
        
        # Add types to $__setup__ method definition (not calls)
        # Match only when after whitespace or at start of line (method definition context)
        code = re.sub(
            r'(\s+)\$__setup__\(__data__, systemData\)',
            r'\1$__setup__(__data__: any, systemData: any)',
            code
        )
        
        # Add types to helper arrow functions in $__setup__
        code = re.sub(
            r'const useState = \(value\)',
            r'const useState = (value: any)',
            code
        )
        
        code = re.sub(
            r'const updateRealState = \(state\)',
            r'const updateRealState = (state: any)',
            code
        )
        
        code = re.sub(
            r'const updateStateByKey = \(key, state\)',
            r'const updateStateByKey = (key: string, state: any)',
            code
        )
        
        # Add types to state setter functions (setCount, setTodos, etc.)
        code = re.sub(
            r'(const set\w+ = )\(state\)( =>)',
            r'\1(state: any)\2',
            code
        )
        
        # Add types to state update functions (update$count, update$todos, etc.)
        code = re.sub(
            r'(const update\$\w+ = )\(value\)( =>)',
            r'\1(value: any)\2',
            code
        )
        
        # Add types to wrapper trait objects
        code = re.sub(
            r'const __UPDATE_DATA_TRAIT__ = \{\}',
            r'const __UPDATE_DATA_TRAIT__: any = {}',
            code
        )
        
        # Match __VARIABLE_LIST__ with any content (empty or with items)
        code = re.sub(
            r'const __VARIABLE_LIST__ = \[([^\]]*)\]',
            r'const __VARIABLE_LIST__: any = [\1]',
            code
        )
        
        # Add types to updateVariableData function
        code = re.sub(
            r'updateVariableData: function\(data\)',
            r'updateVariableData: function(data: any)',
            code
        )
        
        # Add types to updateVariableItemData function
        code = re.sub(
            r'updateVariableItemData: function\(key, value\)',
            r'updateVariableItemData: function(key: string, value: any)',
            code
        )
        
        # Add types to factory function
        code = re.sub(
            r'(export function ' + re.escape(function_name) + r'\()__data__ = \{\}, systemData = \{\}(\))',
            r'\1__data__: any = {}, systemData: any = {}\2',
            code
        )
        
        return code
    
    def _process_type_markers_for_typescript(self, code):
        """Convert type markers to actual TypeScript types
        :[TYPE:type] -> : type (with space)
        as [TYPE:type] -> as type
        """
        # Replace :[TYPE:xxx] with : xxx (with space for proper TypeScript formatting)
        code = re.sub(r':\[TYPE:([^\]]+)\]', r': \1', code)
        
        # Replace as [TYPE:xxx] with as xxx
        code = re.sub(r'as \[TYPE:([^\]]+)\]', r'as \1', code)
        
        return code
    
    def _remove_type_markers_for_javascript(self, code):
        """Remove all type markers for JavaScript
        :[TYPE:type] -> (remove completely including :)
        as [TYPE:type] -> (remove completely including as)
        """
        # Remove :[TYPE:xxx] (including the colon)
        code = re.sub(r':\[TYPE:[^\]]+\]', '', code)
        
        # Remove as [TYPE:xxx] (including as keyword)
        code = re.sub(r'\s+as \[TYPE:[^\]]+\]', '', code)
        
        return code
    
    def _add_wrapper_content(self, wrapper_function_content):
        """Add wrapper content to view function with proper indentation"""
        if wrapper_function_content:
            # Wrapper content already has 4 spaces, add 4 more to get 8 total
            lines = wrapper_function_content.split('\n')
            indented_lines = ['    ' + line if line.strip() else line for line in lines]
            return '\n'.join(indented_lines) + "\n"
        return ""
    
    def _extract_sections_from_template(self, template_content, sections_info):
        """Extract section names that are used in the template content"""
        sections_used = []
        if not template_content or not sections_info:
            return sections_used
        
        for section in sections_info:
            section_name = section.get('name', '')
            if section_name:
                # Check if section is used in template content
                if f"App.View.section('{section_name}'" in template_content:
                    sections_used.append(section_name)
        
        return sections_used
    
    def _extract_sections_from_prerender(self, has_prerender, has_await, has_fetch, sections_info):
        """Extract section names that are used in prerender function"""
        sections_used = []
        
        if not has_prerender:
            return sections_used
        
        # If has prerender, check which sections are used
        # For now, we'll include sections that have preloader or are used with await/fetch
        for section in sections_info:
            section_name = section.get('name', '')
            if section_name:
                # Include sections that have preloader
                if section.get('preloader', False):
                    sections_used.append(section_name)
                # Include sections used with await/fetch (these typically need prerender)
                elif (has_await or has_fetch) and section.get('useVars', False):
                    sections_used.append(section_name)
        
        return sections_used
    
    def _extract_usestate_variables(self, usestate_declarations, all_declarations):
        """
        Extract usestate variable names from declarations
        Returns a set of variable names that have useState declarations
        Includes both state variable names and setter names
        """
        usestate_variables = set()
        
        # Extract from usestate_declarations string
        if usestate_declarations:
            # Pattern: const [stateKey, setStateKey] = useState(...)
            matches = re.findall(r'const\s+\[([^,]+),\s*([^\]]+)\]', usestate_declarations)
            for match in matches:
                state_var = match[0].strip()
                setter_var = match[1].strip()
                if state_var and state_var.isalnum():
                    usestate_variables.add(state_var)
                if setter_var and setter_var.isalnum():
                    usestate_variables.add(setter_var)
        
        # Extract from all_declarations (from DeclarationTracker)
        for decl in all_declarations:
            if decl.get('type') in ['useState', 'const', 'let']:
                variables = decl.get('variables', [])
                for var in variables:
                    if var.get('isUseState'):
                        names = var.get('names', [])
                        if names and len(names) > 0:
                            # First name is the state variable
                            state_var = names[0]
                            if state_var and state_var.isalnum():
                                usestate_variables.add(state_var)
                            # Second name is the setter
                            if len(names) > 1:
                                setter_var = names[1]
                                if setter_var and setter_var.isalnum():
                                    usestate_variables.add(setter_var)
        
        return usestate_variables
    
    def _detect_state_keys(self, blade_code, let_declarations, const_declarations, usestate_declarations):
        """Detect state keys from directives that use useState or destructuring"""
        state_keys = set()
        
        # Check @useState directives
        if usestate_declarations:
            for declaration in usestate_declarations.split('\n'):
                if declaration.strip():
                    # Extract state key from @useState(value, stateKey)
                    # Pattern: const [stateKey] = useState(value);
                    match = re.search(r'const\s+\[([^,]+),\s*[^]]+\]\s*=\s*useState\([^)]+\);', declaration)
                    if match:
                        state_key = match.group(1).strip()
                        state_keys.add(state_key)
        
        # Check @let and @const directives for destructuring patterns
        all_declarations = []
        if let_declarations:
            all_declarations.extend(let_declarations.split('\n'))
        if const_declarations:
            all_declarations.extend(const_declarations.split('\n'))
        
        for declaration in all_declarations:
            if declaration.strip():
                # Check for [stateKey] = useState(...) pattern
                match = re.search(r'\[([^,]+),\s*[^]]+\]\s*=\s*useState\(', declaration)
                if match:
                    state_key = match.group(1).strip()
                    # Remove $ prefix if present
                    if state_key.startswith('$'):
                        state_key = state_key[1:]
                    # Only add valid state keys (not empty or special characters)
                    if state_key and state_key.isalnum():
                        state_keys.add(state_key)
        
        return list(state_keys)
    
    def _generate_state_registration(self, state_keys):
        """Generate state registration code"""
        if not state_keys:
            return ""
        
        registration_lines = []
        for state_key in state_keys:
            registration_lines.append(f"    __STATE__.__.register('{state_key}');")
        
        registration_lines.append("    __STATE__.__.lockRegister();")
        
        return '\n'.join(registration_lines) + '\n'
    
    def _generate_usestate_declarations(self, state_keys):
        """Generate useState declarations with custom setters"""
        if not state_keys:
            return ""
        
        declaration_lines = []
        for state_key in state_keys:
            # Convert stateKey to set$stateKey format (e.g., todos -> set$todos)
            set_state_key = f'set${state_key}'
            declaration_lines.append(f"    const {set_state_key} = __STATE__.__.register('{state_key}');")
            declaration_lines.append(f"    let {state_key} = null;")
            declaration_lines.append(f"    const {set_state_key} = (state) => {{")
            declaration_lines.append(f"        {state_key} = state;")
            declaration_lines.append(f"        {set_state_key}(state);")
            declaration_lines.append(f"    }};")
        
        # Don't add lockRegister - not needed anymore
        
        return '\n'.join(declaration_lines) + '\n'
    
    def _calculate_prerender_need(self, has_await, has_fetch, vars_declaration, sections_info, template_content, usestate_declarations, let_declarations, const_declarations):
        """
        Calculate if prerender is needed based on complex logic:
        - Need (has_await OR has_fetch) AND vars_declaration AND (sections use vars OR useState/let/const with vars)
        """
        # Must have await/fetch AND vars declaration
        if not (has_await or has_fetch) or not vars_declaration:
            return False
        
        # Extract variable names from @vars declaration
        vars_names = self._extract_vars_names(vars_declaration)
        if not vars_names:
            return False
        
        # Check if any section uses vars
        has_sections_with_vars = any(section.get('useVars', False) for section in sections_info)
        
        # Check if template content uses vars
        has_template_with_vars = self._template_uses_vars(template_content, vars_names)
        
        # Check if useState/let/const declarations use vars
        has_declarations_with_vars = self._declarations_use_vars(
            usestate_declarations, let_declarations, const_declarations, vars_names
        )
        
        # Need prerender if any of these conditions are true
        return has_sections_with_vars or has_template_with_vars or has_declarations_with_vars

    def _extract_vars_names(self, vars_declaration):
        """Extract variable names from @vars declaration"""
        if not vars_declaration:
            return []
        
        # Extract variable names from {var1, var2} pattern (without let)
        import re
        pattern = r'\{([^}]+)\}'
        match = re.search(pattern, vars_declaration)
        if match:
            vars_str = match.group(1)
            # Split by comma and clean up
            return [var.strip().replace('$', '') for var in vars_str.split(',') if var.strip()]
        
        return []

    def _template_uses_vars(self, template_content, vars_names):
        """Check if template content uses any of the vars"""
        if not template_content or not vars_names:
            return False
        
        # Check if any var name appears in template content
        for var_name in vars_names:
            if f'${{{var_name}}}' in template_content or f'${{' + var_name + '}}' in template_content:
                return True
        
        return False

    def _declarations_use_vars(self, usestate_declarations, let_declarations, const_declarations, vars_names):
        """Check if useState/let/const declarations use any of the vars"""
        if not vars_names:
            return False
        
        all_declarations = []
        if usestate_declarations:
            all_declarations.append(usestate_declarations)
        if let_declarations:
            all_declarations.append(let_declarations)
        if const_declarations:
            all_declarations.append(const_declarations)
        
        # Check if any declaration uses vars
        for declaration in all_declarations:
            for var_name in vars_names:
                if var_name in declaration:
                    return True
        
        return False
    
    def _filter_directives_only(self, content):
        """Filter content to only include directives (@if/@section/@block), remove pure HTML
        Returns filtered content or empty string if no directives found
        """
        import re
        
        if not content or not content.strip():
            return ''
        
        # Extract only directive expressions (${...})
        # This regex finds ${...} patterns with proper nesting
        directive_parts = []
        
        i = 0
        while i < len(content):
            # Find next ${ 
            match = re.search(r'\$\{(?:App\.Helper\.execute|App\.View\.section|this\.__section|this\.__useBlock|App\.View\.useBlock|this\.__subscribeBlock)\(', content[i:])
            
            if not match:
                break
            
            # Found a directive start
            start_pos = i + match.start()
            
            # Find matching closing }
            depth = 0
            pos = start_pos + 2  # Skip ${
            in_string = False
            string_char = None
            
            while pos < len(content):
                char = content[pos]
                
                # Handle string escaping
                if char == '\\' and in_string:
                    pos += 2
                    continue
                
                # Handle string delimiters
                if char in ['"', "'", '`'] and (pos == 0 or content[pos-1] != '\\'):
                    if not in_string:
                        in_string = True
                        string_char = char
                    elif char == string_char:
                        in_string = False
                        string_char = None
                
                if not in_string:
                    if char == '{':
                        depth += 1
                    elif char == '}':
                        if depth == 0:
                            # Found the closing }
                            directive_parts.append(content[start_pos:pos+1])
                            i = pos + 1
                            break
                        else:
                            depth -= 1
                
                pos += 1
            else:
                # Didn't find closing brace, skip this one
                i = start_pos + 1
                continue
        
        # If no directives found, return empty
        if not directive_parts:
            return ''
        
        # Join all directive parts with newlines
        result = '\n'.join(directive_parts)
        
        return result
    
    def _extract_wrapper_inner_content(self, template_content):
        """Extract inner and outer content from wrapper directive
        Returns: (inner_content, outer_before, outer_after)
        """
        import re
        
        # Find __WRAPPER_CONFIG__ position (marks the start of wrapper)
        config_match = re.search(r'__WRAPPER_CONFIG__\s*=\s*', template_content)
        
        if not config_match:
            return template_content, '', ''
        
        # Content before wrapper
        outer_before = template_content[:config_match.start()].strip()
        
        # Find where config ends (after the semicolon)
        config_end = config_match.end()
        
        # Parse to find end of config object
        if config_end < len(template_content) and template_content[config_end] == '{':
            brace_count = 0
            in_string = False
            string_char = None
            
            for i in range(config_end, len(template_content)):
                char = template_content[i]
                
                if char in ['"', "'"] and (i == 0 or template_content[i-1] != '\\'):
                    if not in_string:
                        in_string = True
                        string_char = char
                    elif char == string_char:
                        in_string = False
                        string_char = None
                
                if not in_string:
                    if char == '{':
                        brace_count += 1
                    elif char == '}':
                        brace_count -= 1
                        if brace_count == 0:
                            config_end = i + 1
                            if config_end < len(template_content) and template_content[config_end] == ';':
                                config_end += 1
                            break
        
        # Skip whitespace/newlines after config
        while config_end < len(template_content) and template_content[config_end] in [' ', '\n', '\r', '\t']:
            config_end += 1
        
        # Find __WRAPPER_END__ marker
        end_match = re.search(r'__WRAPPER_END__', template_content[config_end:])
        
        if end_match:
            # Extract content between config and end marker
            inner_end = config_end + end_match.start()
            inner_content = template_content[config_end:inner_end]
            
            # Content after wrapper
            outer_after_start = config_end + end_match.end()
            outer_after = template_content[outer_after_start:].strip()
        else:
            # No end marker found, take all content after config (fallback)
            inner_content = template_content[config_end:]
            outer_after = ''
        
        # Return separate before and after
        return inner_content, outer_before, outer_after
    
    def _extract_wrapper_config(self, template_content):
        """Extract wrapper config from template content and return both config and cleaned template"""
        import re
        
        # Find __WRAPPER_CONFIG__ = position
        match = re.search(r'__WRAPPER_CONFIG__\s*=\s*', template_content)
        
        if not match:
            return None
        
        # Start parsing from the opening brace
        start_pos = match.end()
        if start_pos >= len(template_content) or template_content[start_pos] != '{':
            return None
        
        # Parse balanced braces
        brace_count = 0
        in_string = False
        string_char = None
        end_pos = start_pos
        
        for i in range(start_pos, len(template_content)):
            char = template_content[i]
            
            # Handle strings
            if char in ['"', "'"] and (i == 0 or template_content[i-1] != '\\'):
                if not in_string:
                    in_string = True
                    string_char = char
                elif char == string_char:
                    in_string = False
                    string_char = None
            
            # Handle braces (only when not in string)
            if not in_string:
                if char == '{':
                    brace_count += 1
                elif char == '}':
                    brace_count -= 1
                    if brace_count == 0:
                        # Found matching closing brace
                        end_pos = i + 1
                        # Look for semicolon
                        if end_pos < len(template_content) and template_content[end_pos] == ';':
                            end_pos += 1
                        config_str = template_content[start_pos:end_pos-1]  # Exclude semicolon
                        return config_str
        
        return None
    
    def _generate_wrapper_declarations(self, declarations):
        """
        Generate wrapper function declarations from DeclarationTracker
        Returns: (wrapper_code, variable_list, state_declarations)
        """

        wrapper_lines = []
        variable_list = []
        update_trait_items = []
        state_declarations = []  # Store useState declarations separately
        
        # First, generate __UPDATE_DATA_TRAIT__ with conditional type annotation
        if self._is_typescript:
            wrapper_lines.append("    const __UPDATE_DATA_TRAIT__: any = {};")
        else:
            wrapper_lines.append("    const __UPDATE_DATA_TRAIT__ = {};")
        
        for decl in declarations:
            decl_type = decl['type']
            variables = decl['variables']

            
            if decl_type == 'vars':
                # Process @vars variables -> destructure from __data__
                # Build destructuring parts with defaults when provided
                destructure_parts = []
                for var in variables:
                    var_name = var['name']
                    if var['hasDefault']:
                        destructure_parts.append(f"{var_name} = {var['value']}")
                    else:
                        destructure_parts.append(f"{var_name}")
                    # Add to __UPDATE_DATA_TRAIT__ and variable list
                    # Add type annotation for TypeScript
                    if self._is_typescript:
                        update_trait_items.append(f"    __UPDATE_DATA_TRAIT__.{var_name} = (value: any) => {var_name} = value;")
                    else:
                        update_trait_items.append(f"    __UPDATE_DATA_TRAIT__.{var_name} = value => {var_name} = value;")
                    variable_list.append(var_name)
                # Emit single destructuring statement
                if destructure_parts:
                    wrapper_lines.append(f"    let {{{', '.join(destructure_parts)}}} = __data__;")
            
            elif decl_type == 'let':
                # Process @let variables
                for var in variables:

                    if var.get('isDestructuring'):
                        # Handle destructuring
                        if var.get('isUseState'):
                            # [$stateKey, $setter] = useState($value)
                            # Keep OLD state registration style, not React style!
                            names = var['names']
                            value = var['value']
                            
                            # Extract state key and setter name
                            if len(names) >= 2:
                                state_key = names[0]
                                setter_name_raw = names[1]
                                
                                # Keep user-declared setter name as is
                                setter_name = setter_name_raw
                                
                                # Extract initial value from useState(value)
                                import re
                                match = re.search(r'useState\s*\(\s*(.+)\s*\)', value)
                                initial_value = match.group(1) if match else 'null'
                                
                                # Generate OLD style state registration
                                state_declarations.append({
                                    'stateKey': state_key,
                                    'setterName': setter_name,
                                    'initialValue': initial_value
                                })
                            # Don't add to variable_list or update_trait (useState variables)
                        else:
                            # Regular destructuring: [$a, $b] = [1, 2]
                            names = var['names']
                            value = var['value']
                            bracket_type = '[' if var['destructuringType'] == 'array' else '{'
                            close_bracket = ']' if var['destructuringType'] == 'array' else '}'
                            wrapper_lines.append(f"    let {bracket_type}{', '.join(names)}{close_bracket} = {value};")
                            
                            # Add each destructured variable to update trait and variable list
                            for name in names:
                                update_trait_items.append(f"    __UPDATE_DATA_TRAIT__.{name} = value => {name} = value;")
                                variable_list.append(name)
                    else:
                        # Regular variable
                        var_name = var['name']
                        if var['hasDefault']:
                            wrapper_lines.append(f"    let {var_name} = {var['value']};")
                        else:
                            wrapper_lines.append(f"    let {var_name};")
                        
                        # Add to update trait and variable list
                        # Add type annotation for TypeScript
                        if self._is_typescript:
                            update_trait_items.append(f"    __UPDATE_DATA_TRAIT__.{var_name} = (value: any) => {var_name} = value;")
                        else:
                            update_trait_items.append(f"    __UPDATE_DATA_TRAIT__.{var_name} = value => {var_name} = value;")
                        variable_list.append(var_name)
            
            elif decl_type == 'const':
                # Process @const variables
                for var in variables:
                    if var.get('isDestructuring'):
                        # Handle destructuring
                        if var.get('isUseState'):
                            # [$stateKey, $setter] = useState($value)
                            # Keep OLD state registration style!
                            names = var['names']
                            value = var['value']
                            
                            # Extract state key and setter name
                            if len(names) >= 2:
                                state_key = names[0]
                                setter_name_raw = names[1]
                                
                                # Keep user-declared setter name as is
                                setter_name = setter_name_raw
                                
                                # Extract initial value from useState(value)
                                import re
                                match = re.search(r'useState\s*\(\s*(.+)\s*\)', value)
                                initial_value = match.group(1) if match else 'null'
                                
                                # Generate OLD style state registration
                                state_declarations.append({
                                    'stateKey': state_key,
                                    'setterName': setter_name,
                                    'initialValue': initial_value
                                })
                            # Don't add to variable_list or update_trait (useState variables)
                        else:
                            # Regular const destructuring
                            names = var['names']
                            value = var['value']
                            bracket_type = '[' if var['destructuringType'] == 'array' else '{'
                            close_bracket = ']' if var['destructuringType'] == 'array' else '}'
                            wrapper_lines.append(f"    const {bracket_type}{', '.join(names)}{close_bracket} = {value};")
                            # Const variables are not mutable, don't add to update_trait or variable_list
                    else:
                        # Regular const
                        var_name = var['name']
                        if var['hasDefault']:
                            wrapper_lines.append(f"    const {var_name} = {var['value']};")
                        # Const variables are not mutable, don't add to update_trait or variable_list
            
            elif decl_type == 'useState':
                # Process @useState directives - same as @let/@const with useState
                for var in variables:
                    if var.get('isDestructuring') and var.get('isUseState'):
                        # [@useState($value, $varName, $setVarName)] becomes [$varName, $setVarName] = useState($value)
                        names = var['names']
                        value = var['value']
                        
                        # Extract state key and setter name
                        if len(names) >= 2:
                            state_key = names[0]
                            setter_name_raw = names[1]
                            
                            # Keep user-declared setter name as is
                            setter_name = setter_name_raw
                            
                            # Extract initial value from useState(value)
                            import re
                            match = re.search(r'useState\s*\(\s*(.+)\s*\)', value)
                            initial_value = match.group(1) if match else 'null'
                            
                            # Generate OLD style state registration
                            state_declarations.append({
                                'stateKey': state_key,
                                'setterName': setter_name,
                                'initialValue': initial_value
                            })
        
        # Add update trait assignments after all variable declarations
        wrapper_lines.extend(update_trait_items)
        
        # Generate __VARIABLE_LIST__ with conditional type annotation
        variable_list_str = ', '.join([f'"{v}"' for v in variable_list])
        if self._is_typescript:
            wrapper_lines.append(f"    const __VARIABLE_LIST__: any = [{variable_list_str}];")
        else:
            wrapper_lines.append(f"    const __VARIABLE_LIST__ = [{variable_list_str}];")
        
        # Generate OLD style state registrations
        for state in state_declarations:
            state_key = state['stateKey']
            setter_name = state['setterName']  # Keep user-declared name as is
            initial_value = state['initialValue']
            
            # Convert setStateKey to set$stateKey format for register name only  
            register_setter_name = setter_name
            if setter_name.startswith('set') and len(setter_name) > 3:
                # setTodos -> set$todos (convert first char after 'set' to lowercase)
                state_part = setter_name[3:]  # Remove 'set'
                if state_part:
                    state_part_lower = state_part[0].lower() + state_part[1:] if len(state_part) > 1 else state_part.lower()
                    register_setter_name = f'set${state_part_lower}'
            
            internal_register = f"set${state_key}"  # __set$todosRegister
            wrapper_lines.append(f"    const {internal_register} = __STATE__.__.register('{state_key}');")
            # Add type annotation for state variable in TypeScript
            if self._is_typescript:
                wrapper_lines.append(f"    let {state_key}: any = null;")
            else:
                wrapper_lines.append(f"    let {state_key} = null;")
            # Add type annotation for TypeScript
            if self._is_typescript:
                wrapper_lines.append(f"    const {setter_name} = (state: any) => {{")
            else:
                wrapper_lines.append(f"    const {setter_name} = (state) => {{")
            wrapper_lines.append(f"        {state_key} = state;")
            wrapper_lines.append(f"        {internal_register}(state);")
            wrapper_lines.append(f"    }};")
            wrapper_lines.append(f"    __STATE__.__.setters.{setter_name} = {setter_name};")
            wrapper_lines.append(f"    __STATE__.__.setters.{state_key} = {setter_name};")
            # Add update$stateKey function - use state_key as is  
            update_func_name = f"update${state_key}"
            # Add type annotation for TypeScript
            if self._is_typescript:
                wrapper_lines.append(f"    const {update_func_name} = (value: any) => {{")
            else:
                wrapper_lines.append(f"    const {update_func_name} = (value) => {{")
            wrapper_lines.append(f"        if(__STATE__.__.canUpdateStateByKey){{")
            wrapper_lines.append(f"            updateStateByKey('{state_key}', value);")
            wrapper_lines.append(f"            {state_key} = value;")
            wrapper_lines.append(f"        }}")
            wrapper_lines.append(f"    }};")
        
        wrapper_code = '\n'.join(wrapper_lines)
        
        return wrapper_code, variable_list, state_declarations
    
    def _convert_blade_to_template_string(self, blade_expression):
        """Convert Blade expressions like {{ asset('css/file.css') }} to template string format"""
        # Convert {{ expression }} to ${APP_HELPER_NAMESPACE.escString(APP_HELPER_NAMESPACE.expression)}
        def replace_blade_expression(match):
            expression = match.group(1).strip()
            
            # Handle common Laravel helpers - all use APP_HELPER_NAMESPACE
            if expression.startswith('asset('):
                return f"${{{APP_HELPER_NAMESPACE}.escString({APP_HELPER_NAMESPACE}.{expression})}}"
            elif expression.startswith('route('):
                return f"${{{APP_HELPER_NAMESPACE}.escString({APP_HELPER_NAMESPACE}.{expression})}}"
            elif expression.startswith('config('):
                return f"${{{APP_HELPER_NAMESPACE}.escString({APP_HELPER_NAMESPACE}.{expression})}}"
            elif expression.startswith('date('):
                return f"${{{APP_HELPER_NAMESPACE}.escString({APP_HELPER_NAMESPACE}.{expression})}}"
            else:
                # Generic expression
                return f"${{{APP_HELPER_NAMESPACE}.escString({expression})}}"
        
        # Replace all {{ expression }} with template string format
        result = re.sub(r'\{\{\s*([^}]+)\s*\}\}', replace_blade_expression, blade_expression)
        return result

    def _generate_state_updates(self, state_declarations):
        """Generate state update calls for updateVariableData function"""
        if not state_declarations:
            return ""
        
        update_lines = []
        for state in state_declarations:
            state_key = state['stateKey']
            setter_name = state['setterName']
            initial_value = state['initialValue']
            # Generate: update$userState(user) - use update function instead of setter
            update_func_name = f"update${state_key}"
            update_lines.append(f"{update_func_name}({initial_value});")
        
        return "\n            ".join(update_lines)
    