"""
Processors cho template lines và các directives khác
"""

from config import JS_FUNCTION_PREFIX, SPA_YIELD_ATTR_PREFIX, SPA_YIELD_SUBSCRIBE_KEY_PREFIX, SPA_YIELD_SUBSCRIBE_TARGET_PREFIX, SPA_YIELD_SUBSCRIBE_ATTR_PREFIX, SPA_YIELD_CONTENT_PREFIX, SPA_YIELD_CHILDREN_PREFIX, SPA_STATECHANGE_PREFIX, APP_VIEW_NAMESPACE, APP_HELPER_NAMESPACE
from php_converter import php_to_js, convert_php_array_to_json
from directive_processors import DirectiveProcessor
from utils import extract_balanced_parentheses
import re
import json

class TemplateProcessors:
    def __init__(self):
        pass

    def _split_top_level(self, s, delimiter):
        """
        Split string `s` by `delimiter` at top-level only (ignore delimiter inside (), [], {}, and quotes).
        """
        parts = []
        buf = ''
        depth_par = 0
        depth_br = 0
        depth_cur = 0
        in_single = False
        in_double = False
        i = 0
        dlen = len(delimiter)
        while i < len(s):
            ch = s[i]
            if ch == '\\':
                if i + 1 < len(s):
                    buf += ch + s[i+1]
                    i += 2
                    continue
                buf += ch
                i += 1
                continue
            if in_single:
                buf += ch
                if ch == "'":
                    in_single = False
                i += 1
                continue
            if in_double:
                buf += ch
                if ch == '"':
                    in_double = False
                i += 1
                continue
            if ch == "'":
                in_single = True
                buf += ch
                i += 1
                continue
            if ch == '"':
                in_double = True
                buf += ch
                i += 1
                continue
            if ch == '(':
                depth_par += 1
                buf += ch
                i += 1
                continue
            if ch == ')':
                depth_par = max(0, depth_par - 1)
                buf += ch
                i += 1
                continue
            if ch == '[':
                depth_br += 1
                buf += ch
                i += 1
                continue
            if ch == ']':
                depth_br = max(0, depth_br - 1)
                buf += ch
                i += 1
                continue
            if ch == '{':
                depth_cur += 1
                buf += ch
                i += 1
                continue
            if ch == '}':
                depth_cur = max(0, depth_cur - 1)
                buf += ch
                i += 1
                continue
            # delimiter match at top level
            if depth_par == 0 and depth_br == 0 and depth_cur == 0 and s[i:i+dlen] == delimiter:
                parts.append(buf)
                buf = ''
                i += dlen
                continue
            buf += ch
            i += 1
        if buf != '':
            parts.append(buf)
        return parts

    def _extract_vars_from_expr(self, expr):
        """Extract top-level PHP variable base names from expr (ignore $ inside single-quoted strings)"""
        vars_set = []
        in_single = False
        in_double = False
        escape = False
        i = 0
        while i < len(expr):
            ch = expr[i]
            if escape:
                escape = False
                i += 1
                continue
            if ch == '\\':
                escape = True
                i += 1
                continue
            if in_single:
                if ch == "'":
                    in_single = False
                i += 1
                continue
            if in_double:
                if ch == '"':
                    in_double = False
                    i += 1
                    continue
                if ch == '$':
                    j = i + 1
                    if j < len(expr) and re.match(r'[a-zA-Z_]', expr[j]):
                        start = j
                        j += 1
                        while j < len(expr) and re.match(r'[a-zA-Z0-9_]', expr[j]):
                            j += 1
                        name = expr[start:j]
                        if name not in vars_set:
                            vars_set.append(name)
                        i = j
                        continue
                i += 1
                continue
            if ch == "'":
                in_single = True
                i += 1
                continue
            if ch == '"':
                in_double = True
                i += 1
                continue
            if ch == '$':
                j = i + 1
                if j < len(expr) and re.match(r'[a-zA-Z_]', expr[j]):
                    start = j
                    j += 1
                    while j < len(expr) and re.match(r'[a-zA-Z0-9_]', expr[j]):
                        j += 1
                    name = expr[start:j]
                    if name not in vars_set:
                        vars_set.append(name)
                    i = j
                    continue
            i += 1
        return vars_set

    def _process_attr_directive(self, inner_expr):
        """
        Build JS string for @attr inner expression.
        Returns string like ${this.__attr({...})}
        """
        if not inner_expr:
            return '${this.__attr({})}'

        attrs = {}
        global_states = []

        # determine if array form
        s = inner_expr.strip()
        if s and s[0] == '[' and s[-1] == ']':
            inner = s[1:-1].strip()
            pairs = self._split_top_level(inner, ',')
            for pair in pairs:
                if '=>' not in pair:
                    continue
                kv = self._split_top_level(pair, '=>')
                if len(kv) < 2:
                    continue
                key_raw = kv[0].strip()
                val_raw = '=>'.join(kv[1:]).strip()
                # strip quotes from key
                if (len(key_raw) >= 2) and ((key_raw[0] == '"' and key_raw[-1] == '"') or (key_raw[0] == "'" and key_raw[-1] == "'")):
                    key = key_raw[1:-1]
                else:
                    key = key_raw
                val = val_raw
                # compute states and render
                states = self._extract_vars_from_expr(val)
                # collect global states - keep top-level variable names only
                for st in states:
                    if st not in global_states:
                        global_states.append(st)
                # convert php expression to js
                try:
                    js_expr = php_to_js(val)
                except Exception:
                    # fallback: simple replacements
                    js_expr = val.replace('.', ' + ').replace('$', '')
                # Post-process js_expr for this val: restore string literals and fix common mis-conversions
                # collect literals from val (per-attribute) to map __STR_LIT_n__ correctly
                try:
                    literals = []
                    for m in re.finditer(r"'([^']*)'|\"([^\"]*)\"", val):
                        lit = m.group(1) if m.group(1) is not None else m.group(2)
                        literals.append(lit)
                except Exception:
                    literals = []

                def _restore_placeholders_local(txt):
                    def _r(m):
                        idx = int(m.group(1))
                        if idx < len(literals):
                            return "'" + literals[idx].replace("'", "\\'") + "'"
                        return "''"
                    return re.sub(r"__STR_LIT_(\d+)__", _r, txt)

                js_expr = _restore_placeholders_local(js_expr)
                js_expr = re.sub(r"(\b[a-zA-Z_][a-zA-Z0-9_]*)\s*\+\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(", r"\1.\2(", js_expr)
                js_expr = re.sub(r"(\b[a-zA-Z_][a-zA-Z0-9_]*)\s*\+\s*\[\s*(['\"])", r"\1[\2", js_expr)

                attrs[key] = {
                    'states': states,
                    'render': js_expr
                }
        else:
            # single form: @attr('name', expr)
            m = re.match(r'^(["\'])(.+?)\1\s*,\s*(.+)$', s, flags=re.DOTALL)
            if not m:
                return None
            key = m.group(2)
            val = m.group(3).strip()
            states = self._extract_vars_from_expr(val)
            for st in states:
                if st not in global_states:
                    global_states.append(st)
            try:
                js_expr = php_to_js(val)
            except Exception:
                js_expr = val.replace('.', ' + ').replace('$', '')
            # Per-attribute post-processing (restore literals and fix concatenation/method access)
            try:
                literals = []
                for m in re.finditer(r"'([^']*)'|\"([^\"]*)\"", val):
                    lit = m.group(1) if m.group(1) is not None else m.group(2)
                    literals.append(lit)
            except Exception:
                literals = []

            def _restore_placeholders_local(txt):
                def _r(m):
                    idx = int(m.group(1))
                    if idx < len(literals):
                        return "'" + literals[idx].replace("'", "\\'") + "'"
                    return "''"
                return re.sub(r"__STR_LIT_(\d+)__", _r, txt)

            js_expr = _restore_placeholders_local(js_expr)
            js_expr = re.sub(r"(\b[a-zA-Z_][a-zA-Z0-9_]*)\s*\+\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\(", r"\1.\2(", js_expr)
            js_expr = re.sub(r"(\b[a-zA-Z_][a-zA-Z0-9_]*)\s*\+\s*\[\s*(['\"])", r"\1[\2", js_expr)

            attrs[key] = {
                'states': states,
                'render': js_expr
            }

        # Build JS object string
        # global states
        import json
        global_states_js = json.dumps(global_states)

        # build attrs object string, keeping render as raw JS arrow function
        parts = []
        for k, v in attrs.items():
            states_js = json.dumps(v['states'])
            render_js = v['render']
            # ensure concatenation uses + (php_to_js should handle concat)
            parts.append(f'"{k}": {{"states": {states_js}, "render": () => {render_js}}}')

        attrs_js = '{' + ', '.join(parts) + '}'

        # Return only the attrs object as requested by runtime API
        # e.g. {"data-test": {"states": [...], "render": () => ...}, ...}
        obj_js = attrs_js
        return '${' + 'this.__attr(' + obj_js + ')}'
    
    def process_template_line(self, line):
        """Process a regular template line"""
        processed_line = line

        # Warn when attribute-like directives are used outside HTML tag attributes.
        try:
            # directives that should appear inside tag attributes
            attr_directives = ['attr', 'bind', 'val', 'class']
            # any event-like directive: @word(...)
            event_names = set(['click','change','input','submit','mouseover','mouseenter','mouseleave','keydown','keyup','focus','blur'])
            for m in re.finditer(r'@([a-zA-Z_][a-zA-Z0-9_]*)\s*\(', processed_line):
                name = m.group(1)
                pos = m.start()
                # find containing tag if any
                inside_tag = False
                for tm in re.finditer(r'<\s*[a-zA-Z][^>]*>', processed_line):
                    if tm.start() <= pos < tm.end():
                        inside_tag = True
                        break
                # If it's an attribute directive or a known event directive and not inside tag, warn
                if (name in attr_directives or name in event_names):
                    if not inside_tag:
                        print(f"Warning: directive '@{name}()' appears outside an HTML tag attribute. Move it inside the tag attributes (e.g. <tag @{name}(... ) ...>) for correct behavior.")
        except Exception:
            pass
        
        # Handle @yield directive in HTML content
        def replace_yield_directive(match):
            yield_content = match.group(1).strip()
            dollar_char = '$'
            yield_content_js = php_to_js(yield_content) if yield_content.startswith(dollar_char) else yield_content
            return "${" + JS_FUNCTION_PREFIX + ".yield(" + yield_content_js + ")}"
        
        processed_line = re.sub(r'@yield\s*\(\s*(.*?)\s*\)', replace_yield_directive, processed_line)

        # Handle inline @out(...) occurrences (supports nested/complex expressions)
        try:
            dp = DirectiveProcessor()
            while True:
                m = re.search(r'@out\s*\(', processed_line)
                if not m:
                    break
                start = m.start()
                # extract balanced parentheses from start of '('
                content, end_pos = extract_balanced_parentheses(processed_line, m.end() - 1)
                if content is None:
                    break
                full = processed_line[start:end_pos]
                replacement = dp.process_out_directive(full)
                if replacement is None:
                    break
                processed_line = processed_line[:start] + replacement + processed_line[end_pos:]
        except Exception:
            # Fail gracefully and leave line unchanged
            pass
        
        # Handle @include directive
        def replace_include_directive(match):
            view_name = match.group(1).strip()
            variables = match.group(2).strip() if match.group(2) else '{}'
            variables_js = convert_php_array_to_json(variables)
            # Remove $ prefix from variables
            variables_js = re.sub(r'\$(\w+)', r'\1', variables_js)
            return "${" + APP_VIEW_NAMESPACE + ".renderView(this.__include('" + view_name + "', " + variables_js + "))}"
        
        # Handle @include directive with PHP expressions (like $temp.'.ga-js')
        def replace_include_php_directive(match):
            view_expr = match.group(1).strip()
            variables = match.group(2).strip() if match.group(2) else '{}'
            variables_js = convert_php_array_to_json(variables)
            # Remove $ prefix from variables
            variables_js = re.sub(r'\$(\w+)', r'\1', variables_js)
            # Convert PHP expression to JavaScript
            view_expr_js = php_to_js(view_expr)
            return "${" + APP_VIEW_NAMESPACE + ".renderView(this.__include(" + view_expr_js + ", " + variables_js + "))}"
        
        # Handle @include directive with PHP expressions and variables (improved for multiline)
        processed_line = re.sub(r'@include\s*\(\s*([^,\'"][^)]*?)\s*,\s*(\[[^\]]*\]|\{[^\}]*\}|[^)]*)\s*\)', replace_include_php_directive, processed_line, flags=re.DOTALL)
        
        # Handle @include directive with string literals and variables (improved for multiline arrays/objects)
        processed_line = re.sub(r'@include\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*(\[[^\]]*\]|\{[^\}]*\}|[^)]*)\s*\)', replace_include_directive, processed_line, flags=re.DOTALL)
        
        # Handle @include directive with PHP expressions without variables
        def replace_include_php_no_vars_directive(match):
            view_expr = match.group(1).strip()
            view_expr_js = php_to_js(view_expr)
            return "${" + APP_VIEW_NAMESPACE + ".renderView(this.__include(" + view_expr_js + "))}"
        
        processed_line = re.sub(r'@include\s*\(\s*([^,\'"][^)]*?)\s*\)', replace_include_php_no_vars_directive, processed_line)
        
        # Handle @include directive with string literals without variables
        processed_line = re.sub(r'@include\s*\(\s*[\'"]([^\'"]*)[\'"]\s*\)', r'${' + APP_VIEW_NAMESPACE + r'.renderView(this.__include("\1", {}))}', processed_line)
        
        # Handle @includeif/@includeFf directive with path and data (2 parameters)
        def replace_includeif_2params_directive(match):
            view_path = match.group(1).strip()
            data = match.group(2).strip()
            
            # Convert view path to JavaScript
            if view_path.startswith('"') and view_path.endswith('"'):
                view_path_js = view_path
            elif view_path.startswith("'") and view_path.endswith("'"):
                view_path_js = f'"{view_path[1:-1]}"'
            else:
                view_path_js = php_to_js(view_path)
            
            # Convert data to JavaScript
            data_js = convert_php_array_to_json(data)
            data_js = re.sub(r'\$(\w+)', r'\1', data_js)
            
            return "${" + APP_VIEW_NAMESPACE + ".renderView(this.__includeif(" + view_path_js + ", " + data_js + "))}"
        
        # Handle @includeif directive with variables
        def replace_includeif_directive(match):
            view_name = match.group(1).strip()
            variables = match.group(2).strip() if match.group(2) else '{}'
            variables_js = convert_php_array_to_json(variables)
            # Remove $ prefix from variables
            variables_js = re.sub(r'\$(\w+)', r'\1', variables_js)
            return "${" + APP_VIEW_NAMESPACE + ".renderView(this.__includeif('" + view_name + "', " + variables_js + "))}"
        
        # Handle @includeif with PHP expressions (must be before string literal patterns)
        processed_line = re.sub(r'@includeif\s*\(\s*([^,]+?)\s*,\s*(\[.*?\])\s*\)', replace_includeif_2params_directive, processed_line, flags=re.IGNORECASE)
        
        processed_line = re.sub(r'@includeif\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*(.*?)\s*\)', replace_includeif_directive, processed_line, flags=re.DOTALL | re.IGNORECASE)
        
        # Handle @includeIf directive without variables (case insensitive)
        processed_line = re.sub(r'@includeif\s*\(\s*[\'"]([^\'"]*)[\'"]\s*\)', r'${' + APP_VIEW_NAMESPACE + r'.renderView(this.__includeif("\1", {}))}', processed_line, flags=re.IGNORECASE)
        
        # Handle @includeWhen/@includewhen directive with condition, path, and data
        def replace_includewhen_directive(match):
            condition = match.group(1).strip()
            view_path = match.group(2).strip()
            data = match.group(3).strip() if match.group(3) else '{}'
            
            # Convert condition to JavaScript
            condition_js = php_to_js(condition)
            
            # Convert view path to JavaScript
            if view_path.startswith('"') and view_path.endswith('"'):
                view_path_js = view_path
            elif view_path.startswith("'") and view_path.endswith("'"):
                view_path_js = f'"{view_path[1:-1]}"'
            else:
                view_path_js = php_to_js(view_path)
            
            # Convert data to JavaScript
            data_js = convert_php_array_to_json(data)
            data_js = re.sub(r'\$(\w+)', r'\1', data_js)
            
            return "${" + APP_VIEW_NAMESPACE + ".renderView(this.__includewhen(" + condition_js + ", " + view_path_js + ", " + data_js + "))}"
        
        # Handle @includeWhen/@includewhen with 3 parameters
        processed_line = re.sub(r'@includewhen\s*\(\s*([^,]+?)\s*,\s*([^,]+?)\s*,\s*([^)]+?)\s*\)', replace_includewhen_directive, processed_line, flags=re.IGNORECASE)
        
        # Handle @template/@view directive - alias of @wrap with enhanced parameter syntax
        # Process this FIRST before @wrap/@wrapper to support template-style syntax
        def replace_template_directive(match):
            expression = match.group(1).strip() if match.group(1) else ''
            
            if not expression:
                return '__WRAPPER_CONFIG__ = { enable: true };'
            
            # Check if it's template-style syntax (contains : or =>)
            if ':' in expression or '=>' in expression:
                # Parse template parameters and convert to wrapper format
                attributes = self._parse_template_parameters(expression)
                tag = attributes.pop('tag', None)
                
                # Process subscribe parameter specially
                if 'subscribe' in attributes:
                    subscribe_value = attributes['subscribe']
                    attributes['subscribe'] = self._process_subscribe_value(subscribe_value)
                
                return self._generate_wrapper_config(attributes, tag)
            else:
                # Simple wrap-style syntax - parse as wrap directive
                if expression.startswith('[') and expression.endswith(']'):
                    # Case 3: @view($attributes)
                    attributes = self._parse_wrap_attributes(expression)
                    return self._generate_wrapper_config(attributes)
                else:
                    # Case 2: @view($tag, $attributes)
                    parts = self._parse_wrap_expression(expression)
                    tag = parts['tag']
                    attributes = parts['attributes']
                    return self._generate_wrapper_config(attributes, tag)
        
        # Handle @template/@view with parameters (multiline support)
        # Note: Process this BEFORE @wrap/@wrapper pattern
        processed_line = re.sub(r'@(?:template|view)\s*\(([^)]*)\)', replace_template_directive, processed_line, flags=re.IGNORECASE | re.DOTALL)
        
        # Handle @template/@view without parameters
        processed_line = re.sub(r'@(?:template|view)(?:\s*\(\s*\))?\s*$', '__WRAPPER_CONFIG__ = { enable: true };', processed_line, flags=re.IGNORECASE)
        
        # Handle @wrap/@wrapper directive (NOT @view - that's handled above)
        def replace_wrap_directive(match):
            expression = match.group(1).strip() if match.group(1) else ''
            
            # Case 1: @wrap() or @wrap (no parameters)
            if not expression:
                return '__WRAPPER_CONFIG__ = { enable: true };'
            
            # Parse expression to determine case
            if expression.startswith('[') and expression.endswith(']'):
                # Case 3: @wrap($attributes)
                attributes = self._parse_wrap_attributes(expression)
                return self._generate_wrapper_config(attributes)
            else:
                # Case 2: @wrap($tag, $attributes)
                parts = self._parse_wrap_expression(expression)
                tag = parts['tag']
                attributes = parts['attributes']
                return self._generate_wrapper_config(attributes, tag)
        
        # Handle @wrap/@wrapper with parameters (NOT @view)
        processed_line = re.sub(r'@(?:wrap|wrapper)\s*\(\s*([^)]*?)\s*\)', replace_wrap_directive, processed_line, flags=re.IGNORECASE)
        
        # Handle @wrap/@wrapper without parameters (NOT @view)
        processed_line = re.sub(r'@(?:wrap|wrapper)(?:\s*\(\s*\))?\s*$', '__WRAPPER_CONFIG__ = { enable: true };', processed_line, flags=re.IGNORECASE)
        
        # Handle @endWrap/@endWrapper/@endView/@endTemplate - keep as marker
        processed_line = re.sub(r'@end(?:wrap|wrapper|view|template)(?:\s*\(\s*\))?\s*$', '__WRAPPER_END__', processed_line, flags=re.IGNORECASE)
        
        # Handle @yieldAttr directive - improved to group multiple directives
        def process_multiple_yieldattr(line):
            """Process multiple @yieldattr directives and group them into single on-subscribe-attr"""
            import re
            
            # Find all on-yield-attr attributes
            yieldattr_pattern = r'@yieldattr\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*(?:,\s*[\'"]([^\'"]*)[\'"])?\s*\)'
            matches = list(re.finditer(yieldattr_pattern, line, re.IGNORECASE))
            
            if not matches:
                return line
            
            # Collect all attributes and subscribe mappings
            attributes = []
            subscribe_attrs = []
            
            for match in matches:
                attr_key = match.group(1).strip().strip("'\"")
                yield_key = match.group(2).strip().strip("'\"")
                default_value = match.group(3).strip() if match.group(3) else 'null'
                
                if default_value != 'null':
                    default_value = default_value.strip("'\"")
                    default_value = f"'{default_value}'"
                
                # Add attribute
                attributes.append(f'{attr_key}="${{{JS_FUNCTION_PREFIX}.yieldContent(\'{yield_key}\', {default_value})}}"')
                # Add to subscribe mapping
                subscribe_attrs.append(f'{attr_key}:{yield_key}')
            
            # Replace all @yieldattr with combined result
            result = line
            for match in reversed(matches):  # Process in reverse order to maintain positions
                result = result[:match.start()] + '' + result[match.end():]
            
            # Add all attributes and single subscribe attribute
            attributes_str = ' '.join(attributes)
            subscribe_str = f'{SPA_YIELD_SUBSCRIBE_ATTR_PREFIX}="{",".join(subscribe_attrs)}"'
            
            # Find the position to insert (after the last attribute)
            insert_pos = result.find('>')
            if insert_pos != -1:
                result = result[:insert_pos] + ' ' + attributes_str + ' ' + subscribe_str + result[insert_pos:]
            
            return result
        
        # Handle @yieldon/@onyield/@yieldListen/@yieldWatch directive with array syntax
        def replace_yieldon_array_directive(match):
            array_content = match.group(1).strip()
            # Parse array content: ['attrKey' => 'yieldKey', '#key' => 'yieldKey', ...]
            result = []
            subscribe_attrs = []
            
            # Split by comma but respect quotes and brackets
            items = []
            current_item = ""
            in_quotes = False
            quote_char = ""
            paren_count = 0
            
            for char in array_content:
                if (char == '"' or char == "'") and not in_quotes:
                    in_quotes = True
                    quote_char = char
                elif char == quote_char and in_quotes:
                    in_quotes = False
                    quote_char = ""
                elif not in_quotes:
                    if char == '[':
                        paren_count += 1
                    elif char == ']':
                        paren_count -= 1
                    elif char == ',' and paren_count == 0:
                        items.append(current_item.strip())
                        current_item = ""
                        continue
                
                current_item += char
            
            if current_item.strip():
                items.append(current_item.strip())
            
            # Process each item
            for item in items:
                if '=>' in item:
                    key, value = item.split('=>', 1)
                    key = key.strip().strip("'\"")
                    value = value.strip().strip("'\"")
                    
                    # Remove $ prefix from value (state variable)
                    if value.startswith('$'):
                        value = value[1:]
                    
                    if key == '#content':
                        # Special key #content
                        result.append(f'{SPA_YIELD_CONTENT_PREFIX}="{value}"')
                    elif key == '#children':
                        # Special key #children
                        result.append(f'{SPA_YIELD_CHILDREN_PREFIX}="{value}"')
                    else:
                        # Regular attribute - create attribute with yieldContent
                        result.append(f'{key}="${{{JS_FUNCTION_PREFIX}.yieldContent(\'{value}\', null)}}"')
                        subscribe_attrs.append(f'{key}:{value}')
            
            # Add subscribe attribute if there are regular attributes
            if subscribe_attrs:
                result.append(f'{SPA_YIELD_SUBSCRIBE_ATTR_PREFIX}="{",".join(subscribe_attrs)}"')
            
            return ' '.join(result)
        
        # Handle @yieldon/@onyield/@yieldListen/@yieldWatch directive with array syntax
        # Handle @yieldon/@onyield/@yieldListen/@yieldWatch directive with array syntax - more specific regex to avoid conflicts
        processed_line = re.sub(r'@(?:yieldon|onyield|yieldlisten|yieldwatch)\s*\(\s*\[([^\[\]]*(?:\[[^\[\]]*\][^\[\]]*)*)\]\s*\)', replace_yieldon_array_directive, processed_line, flags=re.DOTALL | re.IGNORECASE)
        
        # Handle @yieldon/@onyield/@yieldListen/@yieldWatch directive with simple syntax
        def replace_yieldon_directive(match):
            attr_key = match.group(1).strip()
            yield_key = match.group(2).strip()
            default_value = match.group(3).strip() if match.group(3) else 'null'
            # Remove quotes from parameters
            attr_key = attr_key.strip("'\"")
            yield_key = yield_key.strip("'\"")
            if default_value != 'null':
                default_value = default_value.strip("'\"")
                default_value = f"'{default_value}'"
            
            # Create attribute with yieldContent
            result = f'{attr_key}="${{{JS_FUNCTION_PREFIX}.yieldContent(\'{yield_key}\', {default_value})}}"'
            # Add subscribe attribute
            result += f' {SPA_YIELD_SUBSCRIBE_ATTR_PREFIX}="{attr_key}:{yield_key}"'
            return result
        
        processed_line = re.sub(r'@(?:yieldon|onyield|yieldlisten|yieldwatch)\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*(?:,\s*[\'"]([^\'"]*)[\'"])?\s*\)', replace_yieldon_directive, processed_line, flags=re.IGNORECASE)
        
        # Handle @yieldAttr directive - process after @yieldon to avoid conflicts
        processed_line = process_multiple_yieldattr(processed_line)

        # Handle @attr directive per HTML tag (skip @attr inside event directive params)
        try:
            while True:
                m = re.search(r'@attr\s*\(', processed_line)
                if not m:
                    break
                attr_pos = m.start()

                # Find the HTML tag that contains this position (if any)
                tag_match = None
                for tm in re.finditer(r'<\s*[a-zA-Z][^>]*>', processed_line):
                    if tm.start() <= attr_pos < tm.end():
                        tag_match = tm
                        break

                if not tag_match:
                    # Not inside a tag — process normally
                    content, end_pos = extract_balanced_parentheses(processed_line, m.end() - 1)
                    if content is None:
                        break
                    replacement = self._process_attr_directive(content.strip())
                    if replacement is None:
                        break
                    processed_line = processed_line[:m.start()] + replacement + processed_line[end_pos:]
                    continue

                tag_start = tag_match.start()
                tag_end = tag_match.end()
                tag_text = processed_line[tag_start:tag_end]

                # Find event directive ranges inside this tag to avoid processing @attr inside them
                event_ranges = []
                for em in re.finditer(r'@[a-zA-Z_][a-zA-Z0-9_]*\s*\(', tag_text):
                    em_abs_start = tag_start + em.start()
                    # find balanced parentheses globally
                    content_ev, ev_end = extract_balanced_parentheses(processed_line, tag_start + em.end() - 1)
                    if content_ev is None:
                        continue
                    event_ranges.append((em_abs_start, ev_end))

                # Collect all @attr occurrences inside this tag that are NOT inside event ranges
                attrs = []
                for am in re.finditer(r'@attr\s*\(', tag_text):
                    am_abs_start = tag_start + am.start()
                    content_attr, attr_end = extract_balanced_parentheses(processed_line, tag_start + am.end() - 1)
                    if content_attr is None:
                        continue
                    # Check whether this attr is inside any event range
                    inside_event = False
                    for er in event_ranges:
                        if am_abs_start >= er[0] and am_abs_start < er[1]:
                            inside_event = True
                            break
                    if not inside_event:
                        attrs.append((am_abs_start, attr_end, content_attr))

                if not attrs:
                    # No non-event @attr in this tag — skip this occurrence
                    # Move past this @attr and continue
                    search_pos = m.end()
                    next_m = re.search(r'@attr\s*\(', processed_line[search_pos:])
                    if not next_m:
                        break
                    # adjust processed_line search by slicing
                    # continue loop to handle next occurrence
                    # Recompute global search by updating processed_line in next iteration
                    # To avoid infinite loop, remove this occurrence from consideration by replacing temporarily
                    # We'll just skip by slicing the string beyond this occurrence
                    processed_line = processed_line[:m.end()] + processed_line[m.end():]
                    break

                # Enforce only one @attr per tag — process the first non-event occurrence
                first_attr = attrs[0]
                a_start, a_end, a_content = first_attr
                replacement = self._process_attr_directive(a_content.strip())
                if replacement is None:
                    # nothing to replace
                    break
                processed_line = processed_line[:a_start] + replacement + processed_line[a_end:]

                # If there are more non-event @attr in same tag, warn and leave them untouched
                if len(attrs) > 1:
                    print(f"Warning: multiple @attr found on single tag at position {tag_start}. Only the first was applied.")

                # Restart processing from beginning because string changed
                continue
        except Exception:
            pass
        # Fallback: if any @attr(...) remain (edge cases where tag-scoped processing missed them),
        # process them globally.
        try:
            while True:
                m = re.search(r'@attr\s*\(', processed_line)
                if not m:
                    break
                content, end_pos = extract_balanced_parentheses(processed_line, m.end() - 1)
                if content is None:
                    break
                replacement = self._process_attr_directive(content.strip())
                if replacement is None:
                    break
                processed_line = processed_line[:m.start()] + replacement + processed_line[end_pos:]
        except Exception:
            pass
        
        # Handle @subscribe directive with array syntax
        def replace_subscribe_array_directive(match):
            array_content = match.group(1).strip()
            # Parse array content: ['attrkey' => $stateKey, '#children' => $childrenState, '#content' => $contentState]
            result = []
            
            # Split by comma but respect quotes and brackets
            items = []
            current_item = ""
            in_quotes = False
            quote_char = ""
            paren_count = 0
            
            for char in array_content:
                if (char == '"' or char == "'") and not in_quotes:
                    in_quotes = True
                    quote_char = char
                elif char == quote_char and in_quotes:
                    in_quotes = False
                    quote_char = ""
                elif not in_quotes:
                    if char == '[':
                        paren_count += 1
                    elif char == ']':
                        paren_count -= 1
                    elif char == ',' and paren_count == 0:
                        items.append(current_item.strip())
                        current_item = ""
                        continue
                
                current_item += char
            
            if current_item.strip():
                items.append(current_item.strip())
            
            # Process each item
            for item in items:
                if '=>' in item:
                    key, value = item.split('=>', 1)
                    key = key.strip().strip("'\"")
                    value = value.strip().strip("'\"")
                    
                    # Remove $ prefix from state variable
                    if value.startswith('$'):
                        state_key = value[1:]
                    else:
                        state_key = value
                    
                    if key == '#children':
                        # Special key #children
                        result.append(f'{SPA_STATECHANGE_PREFIX}{state_key}="#children"')
                    elif key == '#content':
                        # Special key #content
                        result.append(f'{SPA_STATECHANGE_PREFIX}{state_key}="#content"')
                    else:
                        # Regular attribute
                        result.append(f'{SPA_STATECHANGE_PREFIX}{state_key}="{key}"')
            
            return ' '.join(result)
        
        # Handle @subscribe directive with new approach
        def replace_subscribe_directive(match):
            full_match = match.group(0)
            # Extract the content inside parentheses
            paren_match = re.search(r'@subscribe\s*\((.*)\)', full_match, re.IGNORECASE)
            if not paren_match:
                return full_match
            
            content = paren_match.group(1).strip()
            
            # Case 1: Single parameter - @subscribe($stateKey)
            single_match = re.match(r'^\$?(\w+)$', content)
            if single_match:
                state_key = single_match.group(1)
                return f'${{this.__subscribe({{\"#all\": [\"{state_key}\"]}})}}'
            
            # Case 2: Two parameters - @subscribe($stateKey, 'attrKey') or @subscribe($stateKey, "#children")
            two_params_match = re.match(r'^\$?(\w+)\s*,\s*[\'"]([^\'"]*)[\'"]$', content)
            if two_params_match:
                state_key = two_params_match.group(1)
                attr_key = two_params_match.group(2)
                return f'${{this.__subscribe({{\"{attr_key}\": [\"{state_key}\"]}})}}'
            
            # Case 3: Array of state variables - @subscribe([$stateKey, $contentState])
            if content.startswith('[') and content.endswith(']'):
                array_content = content[1:-1].strip()
                # Check if it contains => (key-value pairs) or just state variables
                if '=>' in array_content:
                    # Case 4: Array with key-value pairs - @subscribe(['attrKey' => $stateKey, ...])
                    return process_subscribe_array_keyvalue(array_content)
                else:
                    # Case 3: Array of state variables - @subscribe([$stateKey, $contentState])
                    state_keys = parse_state_array(array_content)
                    return f'${{this.__subscribe({{\"#all\": {json.dumps(state_keys)}}})}}'
            
            # Case 5: Array with second parameter - @subscribe([$stateKey, $contentState], "#children")
            array_with_attr_match = re.match(r'^\[([^\]]+)\]\s*,\s*[\'"]([^\'"]*)[\'"]$', content)
            if array_with_attr_match:
                array_content = array_with_attr_match.group(1).strip()
                attr_key = array_with_attr_match.group(2)
                state_keys = parse_state_array(array_content)
                return f'${{this.__subscribe({{\"{attr_key}\": {json.dumps(state_keys)}}})}}'
            
            return full_match
        
        def parse_state_array(array_content):
            """Parse array content and extract state keys"""
            state_keys = []
            items = []
            current_item = ""
            in_quotes = False
            quote_char = ""
            paren_count = 0
            
            for char in array_content:
                if (char == '"' or char == "'") and not in_quotes:
                    in_quotes = True
                    quote_char = char
                    current_item += char
                elif char == quote_char and in_quotes:
                    in_quotes = False
                    quote_char = ""
                    current_item += char
                elif char == '[' and not in_quotes:
                    paren_count += 1
                    current_item += char
                elif char == ']' and not in_quotes:
                    paren_count -= 1
                    current_item += char
                elif char == ',' and not in_quotes and paren_count == 0:
                    if current_item.strip():
                        items.append(current_item.strip())
                    current_item = ""
                else:
                    current_item += char
            
            if current_item.strip():
                items.append(current_item.strip())
            
            # Process each item
            for item in items:
                item = item.strip()
                # Remove $ prefix from state variable
                if item.startswith('$'):
                    state_key = item[1:]
                else:
                    state_key = item
                state_keys.append(state_key)
            
            return state_keys
        
        def process_subscribe_array_keyvalue(array_content):
            """Process array with key-value pairs"""
            result = {}
            
            # Split by comma but respect quotes and brackets
            items = []
            current_item = ""
            in_quotes = False
            quote_char = ""
            paren_count = 0
            
            for char in array_content:
                if (char == '"' or char == "'") and not in_quotes:
                    in_quotes = True
                    quote_char = char
                    current_item += char
                elif char == quote_char and in_quotes:
                    in_quotes = False
                    quote_char = ""
                    current_item += char
                elif char == '[' and not in_quotes:
                    paren_count += 1
                    current_item += char
                elif char == ']' and not in_quotes:
                    paren_count -= 1
                    current_item += char
                elif char == ',' and not in_quotes and paren_count == 0:
                    if current_item.strip():
                        items.append(current_item.strip())
                    current_item = ""
                else:
                    current_item += char
            
            if current_item.strip():
                items.append(current_item.strip())
            
            # Process each key-value pair
            for item in items:
                item = item.strip()
                if '=>' in item:
                    key, value = item.split('=>', 1)
                    key = key.strip().strip('"\'')
                    value = value.strip()
                    
                    # Check if value is an array
                    if value.startswith('[') and value.endswith(']'):
                        # Array of state variables
                        array_content = value[1:-1].strip()
                        state_keys = parse_state_array(array_content)
                        result[key] = state_keys
                    else:
                        # Single state variable
                        if value.startswith('$'):
                            state_key = value[1:]
                        else:
                            state_key = value
                        result[key] = [state_key]
            
            return f'${{this.__subscribe({json.dumps(result)})}}'
        
        # Apply the new subscribe directive processing
        processed_line = re.sub(r'@subscribe\s*\([^)]*\)', replace_subscribe_directive, processed_line, flags=re.IGNORECASE)
        
        # Handle @wrap/@wrapAttr/@wrapattr directive
        def replace_wrap_directive(match):
            return '${this.wrapattr()}'

        # Only match @wrap directives that are in HTML tag attributes (not in text content)
        # Look for patterns like: <tag @wrap class="..."> or <tag @wrap>
        processed_line = re.sub(r'<([^>]*?)\s@(?:wrap|wrapAttr|wrapattr)\s*(?:\([^)]*\))?\s*([^>]*?)>', r'<\1 \2 ${this.wrapattr()}>', processed_line, flags=re.IGNORECASE)

        # Safety: some earlier/legacy passes may leave a stray 'Attr()' token
        # immediately before the inserted ${this.wrapattr()} (e.g. "Attr() ${this.wrapattr()}").
        # Remove leftover standalone "Attr()" occurrences to avoid duplicated output.
        try:
            processed_line = re.sub(r"\bAttr\(\)\s*", '', processed_line)
        except Exception:
            pass
        
        # Merge multiple on-yield-attr attributes into one
        def merge_yield_attr_attributes(line):
            """Merge multiple on-yield-attr attributes into a single one"""
            import re
            
            # Find all on-yield-attr attributes
            yield_attr_pattern = r'on-yield-attr="([^"]*)"'
            matches = list(re.finditer(yield_attr_pattern, line))
            
            if len(matches) <= 1:
                return line
            
            # Collect all attribute mappings
            all_attrs = []
            for match in matches:
                attrs = match.group(1).split(',')
                all_attrs.extend([attr.strip() for attr in attrs if attr.strip()])
            
            # Remove duplicates while preserving order
            seen = set()
            unique_attrs = []
            for attr in all_attrs:
                if attr not in seen:
                    seen.add(attr)
                    unique_attrs.append(attr)
            
            # Replace all on-yield-attr with single one
            result = line
            for match in reversed(matches):  # Process in reverse order
                result = result[:match.start()] + '' + result[match.end():]
            
            # Add single merged on-yield-attr
            merged_attr = f'on-yield-attr="{",".join(unique_attrs)}"'
            insert_pos = result.find('>')
            if insert_pos != -1:
                result = result[:insert_pos] + ' ' + merged_attr + result[insert_pos:]
            
            return result
        
        processed_line = merge_yield_attr_attributes(processed_line)
        
        # Handle @viewId directive
        processed_line = re.sub(r'@viewId', "${" + JS_FUNCTION_PREFIX + ".generateViewId()}", processed_line)
        
        # Handle {!! ... !!} (unescaped output)
        def replace_unescaped(match):
            expr = match.group(1).strip()
            js_expr = php_to_js(expr)
            
            # Check if we're inside an HTML tag (not inside attribute value quotes)
            # Look for pattern: < ... {!! ... !!} ... > where {!! !!} is not inside "..." or '...'
            full_line = processed_line
            pos = match.start()
            
            # Find nearest < before pos
            tag_start = full_line.rfind('<', 0, pos)
            if tag_start != -1:
                # Find nearest > after pos  
                tag_end = full_line.find('>', pos)
                if tag_end != -1:
                    # Check if there's a closing > between tag_start and pos
                    intermediate_close = full_line.rfind('>', tag_start, pos)
                    if intermediate_close == -1:
                        # We might be inside a tag - check if we're inside quotes
                        tag_content = full_line[tag_start:pos]
                        # Count unescaped quotes
                        in_double = tag_content.count('"') % 2 == 1
                        in_single = tag_content.count("'") % 2 == 1
                        
                        if not in_double and not in_single:
                            # Inside tag, outside quotes - use simple interpolation
                            return '${' + js_expr + '}'
            
            return '${' + js_expr + '}'
        processed_line = re.sub(r'{\!!\s*(.*?)\s*!!}', replace_unescaped, processed_line)
        
        # Handle {{ ... }} (escaped output)
        def replace_echo(match):
            expr = match.group(1).strip()
            js_expr = php_to_js(expr)
            
            # Check if we're inside an HTML tag (not inside attribute value quotes)
            full_line = processed_line
            pos = match.start()
            
            # Find nearest < before pos
            tag_start = full_line.rfind('<', 0, pos)
            if tag_start != -1:
                # Find nearest > after pos
                tag_end = full_line.find('>', pos)
                if tag_end != -1:
                    # Check if there's a closing > between tag_start and pos
                    intermediate_close = full_line.rfind('>', tag_start, pos)
                    if intermediate_close == -1:
                        # We might be inside a tag - check if we're inside quotes
                        tag_content = full_line[tag_start:pos]
                        # Count unescaped quotes to determine if we're in a string
                        in_double = tag_content.count('"') % 2 == 1
                        in_single = tag_content.count("'") % 2 == 1
                        
                        if not in_double and not in_single:
                            # Inside tag, outside attribute value quotes
                            # Use simple escaped interpolation
                            return "${" + APP_VIEW_NAMESPACE + ".escString(" + js_expr + ")}"
            
            # Check if this is a complex structure (array/object) that shouldn't be escaped
            if self._is_complex_structure(js_expr):
                return "${" + js_expr + "}"
            else:
                return "${" + JS_FUNCTION_PREFIX + ".escString(" + js_expr + ")}"
        processed_line = re.sub(r'{{\s*(.*?)\s*}}', replace_echo, processed_line)
        
        # Handle { ... } (simple variable output)
        def replace_simple_var(match):
            expr = match.group(1).strip()
            js_expr = php_to_js(expr)
            return "${" + js_expr + "}"
        processed_line = re.sub(r'{\s*\$(\w+)\s*}', replace_simple_var, processed_line)
        
        # Handle {{ $var }} syntax - convert to ${APP_HELPER_NAMESPACE.escString(var)}
        def replace_php_variable(match):
            var_name = match.group(1).strip()
            # Remove $ prefix if present
            if var_name.startswith('$'):
                var_name = var_name[1:]
            return f'${{{APP_HELPER_NAMESPACE}.escString({var_name})}}'
        
        processed_line = re.sub(r'\{\{\s*\$(\w+)\s*\}\}', replace_php_variable, processed_line)
        
        # Handle @useState directive - remove from template (already processed in main_compiler.py)
        processed_line = re.sub(r'@useState\s*\([^)]*\)', '', processed_line, flags=re.IGNORECASE)
        
        return processed_line
    
    def _parse_wrap_expression(self, expression):
        """Parse @wrap($tag, $attributes) expression"""
        # Find comma separator
        comma_pos = -1
        in_quote = False
        quote_char = None
        
        for i in range(len(expression)):
            char = expression[i]
            
            if (char == '"' or char == "'") and (i == 0 or expression[i-1] != '\\'):
                if not in_quote:
                    in_quote = True
                    quote_char = char
                elif char == quote_char:
                    in_quote = False
                    quote_char = None
            
            if not in_quote and char == ',':
                comma_pos = i
                break
        
        if comma_pos == -1:
            # Only tag, no attributes
            tag = expression.strip().strip('\'"')
            return {'tag': tag, 'attributes': {}}
        
        # Both tag and attributes
        tag_part = expression[:comma_pos].strip().strip('\'"')
        attributes_part = expression[comma_pos + 1:].strip()
        
        return {'tag': tag_part, 'attributes': self._parse_wrap_attributes(attributes_part)}
    
    def _parse_wrap_attributes(self, attributes_str):
        """Parse attributes array from string"""
        attributes_str = attributes_str.strip()
        
        # Remove brackets
        if attributes_str.startswith('[') and attributes_str.endswith(']'):
            attributes_str = attributes_str[1:-1]
        
        if not attributes_str:
            return {}
        
        # Use regex to parse key-value pairs
        attributes = {}
        
        # Pattern to match 'key' => 'value' or 'key' => value
        pattern = r"['\"]?([^'\"]+)['\"]?\s*=>\s*(.*?)(?=,\s*['\"]?[^'\"]+['\"]?\s*=>|$)"
        matches = re.findall(pattern, attributes_str)
        
        for key, value in matches:
            key = key.strip()
            value = value.strip().strip('\'"')
            
            # Handle follow parameter specially
            if key == 'follow':
                if value == 'false':
                    attributes[key] = False
                elif value.startswith('[') and value.endswith(']'):
                    # Array of variables
                    array_content = value[1:-1]
                    variables = [v.strip().strip('\'"') for v in array_content.split(',')]
                    attributes[key] = variables
                else:
                    # Single variable
                    attributes[key] = value
            elif key == 'subscribe':
                # Handle subscribe parameter similar to follow, with boolean support
                if value == 'false':
                    attributes[key] = False
                elif value == 'true':
                    attributes[key] = True
                elif value.startswith('[') and value.endswith(']'):
                    array_content = value[1:-1]
                    variables = [v.strip().strip('\'"') for v in array_content.split(',')]
                    attributes[key] = variables
                else:
                    attributes[key] = value
            else:
                attributes[key] = value
        
        return attributes
    
    def _process_subscribe_value(self, subscribe_str):
        """Process subscribe value to extract variable names
        Input: "[$statekey]" or "[$user, $posts]" or "false" or "$key"
        Output: ["statekey"] or ["user", "posts"] or False or ["key"]
        """
        subscribe_str = str(subscribe_str).strip()
        
        # Strip outer quotes if present
        if (subscribe_str.startswith('"') and subscribe_str.endswith('"')) or \
           (subscribe_str.startswith("'") and subscribe_str.endswith("'")):
            subscribe_str = subscribe_str[1:-1].strip()
        
        # Handle boolean values
        if subscribe_str.lower() == 'false' or subscribe_str == 'False':
            return False
        if subscribe_str.lower() == 'true' or subscribe_str == 'True':
            return True
        
        # Handle array syntax: [$var1, $var2, ...] or ["var1", "var2", ...]
        if subscribe_str.startswith('[') and subscribe_str.endswith(']'):
            # Remove brackets
            inner = subscribe_str[1:-1].strip()
            if not inner:
                return []
            
            # Split by comma and extract variable names
            variables = []
            for var in inner.split(','):
                var = var.strip()
                # Remove quotes if present
                if (var.startswith('"') and var.endswith('"')) or \
                   (var.startswith("'") and var.endswith("'")):
                    var = var[1:-1].strip()
                # Remove $ if present
                var = var.lstrip('$')
                if var:
                    variables.append(var)
            return variables
        
        # Handle single variable: $var
        if subscribe_str.startswith('$'):
            return [subscribe_str[1:]]
        
        # Already processed or literal
        return subscribe_str
    
    def _parse_template_parameters(self, params_str):
        """Parse template parameters from various formats:
        - Positional: $tag = '...', $subscribe = [...], $attr1 = '...', ...
        - Named: tag: '...', subscribe: [...], attr1: '...', ...
        - Array: ['tag' => '...', 'subscribe' => [...], ...]
        - First param as tag: 'section', $subscribe = [...]
        """
        params_str = params_str.strip()
        
        # Check if it's array syntax
        if params_str.startswith('[') and params_str.endswith(']'):
            return self._parse_wrap_attributes(params_str)
        
        # Check if it's named parameter syntax (contains colons)
        if self._is_named_parameter_syntax(params_str):
            return self._parse_named_parameters(params_str)
        
        # Parse as positional parameters with defaults
        return self._parse_positional_parameters(params_str)
    
    def _is_named_parameter_syntax(self, params_str):
        """Check if expression uses named parameter syntax (key: value)"""
        in_quote = False
        quote_char = None
        bracket_depth = 0
        
        for i, char in enumerate(params_str):
            # Handle quotes
            if char in ['"', "'"] and (i == 0 or params_str[i-1] != '\\'):
                if not in_quote:
                    in_quote = True
                    quote_char = char
                elif char == quote_char:
                    in_quote = False
                    quote_char = None
            
            # Handle brackets
            if not in_quote:
                if char == '[':
                    bracket_depth += 1
                elif char == ']':
                    bracket_depth -= 1
            
            # Check for colon (not inside quotes or brackets, not part of ::)
            if not in_quote and bracket_depth == 0 and char == ':':
                if (i + 1 >= len(params_str) or params_str[i + 1] != ':') and \
                   (i == 0 or params_str[i - 1] != ':'):
                    return True
        
        return False
    
    def _parse_named_parameters(self, params_str):
        """Parse named parameters: key: value, key2: value2, ..."""
        return self._parse_key_value_pairs(params_str, ':')
    
    def _parse_positional_parameters(self, params_str):
        """Parse positional parameters: $tag = '...', $subscribe = [...], ..."""
        attributes = {}
        parts = self._split_params_by_comma(params_str)
        
        for part in parts:
            part = part.strip()
            
            # Check if it's an assignment: $varName = value or varName = value
            match = re.match(r'^\s*\$?(\w+)\s*=\s*(.+)$', part, re.DOTALL)
            if match:
                key = match.group(1)
                value = match.group(2).strip()
                attributes[key] = value
            else:
                # If no assignment, treat as 'tag' parameter
                if 'tag' not in attributes:
                    # Remove quotes if present
                    attributes['tag'] = part.strip('\'"')
        
        return attributes
    
    def _parse_key_value_pairs(self, params_str, separator):
        """Parse key-value pairs with given separator (=> or :)"""
        attributes = {}
        parts = self._split_params_by_comma(params_str)
        
        for part in parts:
            part = part.strip()
            
            # Find separator position (not inside quotes or brackets)
            sep_pos = self._find_separator_position(part, separator)
            
            if sep_pos is not None:
                key = part[:sep_pos].strip().strip('\'"').lstrip('$')
                value = part[sep_pos + len(separator):].strip()
                attributes[key] = value
        
        return attributes
    
    def _find_separator_position(self, expression, separator):
        """Find separator position outside quotes and brackets"""
        in_quote = False
        quote_char = None
        bracket_depth = 0
        sep_len = len(separator)
        
        for i, char in enumerate(expression):
            # Handle quotes
            if char in ['"', "'"] and (i == 0 or expression[i-1] != '\\'):
                if not in_quote:
                    in_quote = True
                    quote_char = char
                elif char == quote_char:
                    in_quote = False
                    quote_char = None
            
            # Handle brackets
            if not in_quote:
                if char == '[':
                    bracket_depth += 1
                elif char == ']':
                    bracket_depth -= 1
            
            # Check for separator
            if not in_quote and bracket_depth == 0:
                if expression[i:i+sep_len] == separator:
                    # For ':', make sure it's not '::'
                    if separator == ':':
                        not_double_colon = (i + 1 >= len(expression) or expression[i + 1] != ':') and \
                                          (i == 0 or expression[i - 1] != ':')
                        if not_double_colon:
                            return i
                    else:
                        return i
        
        return None
    
    def _split_params_by_comma(self, expression):
        """Split expression by comma (respecting quotes, brackets, and parentheses)"""
        parts = []
        current = ''
        in_quote = False
        quote_char = None
        bracket_depth = 0
        paren_depth = 0
        
        for i, char in enumerate(expression):
            # Handle quotes
            if char in ['"', "'"] and (i == 0 or expression[i-1] != '\\'):
                if not in_quote:
                    in_quote = True
                    quote_char = char
                elif char == quote_char:
                    in_quote = False
                    quote_char = None
            
            # Handle brackets and parentheses
            if not in_quote:
                if char == '[':
                    bracket_depth += 1
                elif char == ']':
                    bracket_depth -= 1
                elif char == '(':
                    paren_depth += 1
                elif char == ')':
                    paren_depth -= 1
            
            # Split on comma only if not inside quotes, brackets, or parentheses
            if not in_quote and bracket_depth == 0 and paren_depth == 0 and char == ',':
                if current.strip():
                    parts.append(current)
                current = ''
            else:
                current += char
        
        # Add the last part
        if current.strip():
            parts.append(current)
        
        return parts
    
    def _generate_wrapper_config(self, attributes, tag=None):
        """Generate wrapperConfig object"""
        config_parts = ['enable: true']
        
        # Always add tag field (null if not provided)
        if tag:
            # Strip outer quotes from tag if present
            tag_value = tag.strip().strip('\'"')
            config_parts.append(f'tag: "{tag_value}"')
        else:
            config_parts.append('tag: null')
        
        # Handle follow/subscribe parameter (treat 'subscribe' as alias of 'follow')
        # Prefer explicit 'follow' if present; otherwise use 'subscribe'. If both present and are lists, merge unique.
        follow_val = None
        raw_follow = attributes.pop('follow', None)
        raw_subscribe = attributes.pop('subscribe', None)

        if raw_follow is not None and raw_subscribe is not None:
            # Both provided: normalize and merge if lists
            if isinstance(raw_follow, list) and isinstance(raw_subscribe, list):
                merged = []
                for item in raw_follow + raw_subscribe:
                    if isinstance(item, str) and item.startswith('$'):
                        key = item[1:]
                    else:
                        key = str(item)
                    if key not in merged:
                        merged.append(key)
                follow_val = merged
            else:
                # If either is boolean false, prefer false; else prefer raw_follow
                if raw_follow == 'false' or raw_follow is False or raw_subscribe == 'false' or raw_subscribe is False:
                    follow_val = False
                else:
                    follow_val = raw_follow
        elif raw_follow is not None:
            follow_val = raw_follow
        elif raw_subscribe is not None:
            follow_val = raw_subscribe

        if follow_val is not None:
            # Only emit `subscribe` in output; keep backward-compatible interpretation
            if follow_val == 'false' or follow_val is False:
                config_parts.append('subscribe: false')
            elif follow_val == 'true' or follow_val is True:
                config_parts.append('subscribe: true')
            elif isinstance(follow_val, str):
                # Single variable
                if follow_val.startswith('$'):
                    follow_key = follow_val[1:]
                else:
                    follow_key = follow_val
                config_parts.append(f'subscribe: ["{follow_key}"]')
            elif isinstance(follow_val, list):
                # Array of variables
                processed_follow = []
                for item in follow_val:
                    if isinstance(item, str) and item.startswith('$'):
                        processed_follow.append(f'"{item[1:]}"')
                    else:
                        processed_follow.append(f'"{item}"')
                config_parts.append(f'subscribe: [{", ".join(processed_follow)}]')
        
        # Handle other attributes
        if attributes:
            # Strip quotes from attribute values for proper JSON conversion
            cleaned_attrs = {}
            for key, value in attributes.items():
                value_str = str(value).strip()
                # If value is a simple quoted string, strip the quotes
                if (value_str.startswith('"') and value_str.endswith('"')) or \
                   (value_str.startswith("'") and value_str.endswith("'")):
                    cleaned_attrs[key] = value_str[1:-1]
                else:
                    cleaned_attrs[key] = value
            
            attrs_js = convert_php_array_to_json(str(cleaned_attrs))
            attrs_js = re.sub(r'\$(\w+)', r'\1', attrs_js)
            config_parts.append(f'attributes: {attrs_js}')
        else:
            config_parts.append('attributes: {}')
        
        return f'__WRAPPER_CONFIG__ = {{ {", ".join(config_parts)} }};'
    
    def _is_complex_structure(self, expr):
        """Check if expression is a complex structure (array/object) that shouldn't be escaped"""
        expr = expr.strip()
        
        # Check for array syntax
        if expr.startswith('[') and expr.endswith(']'):
            return True
            
        # Check for object syntax
        if expr.startswith('{') and expr.endswith('}'):
            return True
            
        # Check for nested structures (more sophisticated)
        if '[' in expr and ']' in expr and '=>' in expr:
            return True
            
        if '{' in expr and '}' in expr and ':' in expr:
            return True
            
        return False
    
    def process_serverside_directive(self, line):
        """Process @serverside/@serverSide directive and aliases"""
        serverside_aliases = [
            '@serverside', '@serverSide', '@ssr', '@SSR', '@useSSR', '@useSsr'
        ]
        
        if any(line.startswith(alias) for alias in serverside_aliases):
            return 'skip_until_@endserverside'
        return False
    
    def process_clientside_directive(self, line):
        """Process @clientside/@endclientside directive and aliases"""
        clientside_aliases = [
            '@clientside', '@clientSide', '@csr', '@CSR', '@useCSR', '@useCsr'
        ]
        
        if any(line.startswith(alias) for alias in clientside_aliases):
            return 'remove_directive_markers_until_@endclientside'
        return False
