"""
Blade Template Compiler - Biên dịch .sao files sang Blade PHP templates
Thêm reactive directives (@startReactive/@endReactive) cho SSR support
"""

import re
import sys
import os

# Ensure parent directory is in path for common package
_parent_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _parent_dir not in sys.path:
    sys.path.insert(0, _parent_dir)
_current_dir = os.path.dirname(os.path.abspath(__file__))
if _current_dir not in sys.path:
    sys.path.insert(0, _current_dir)

from common.declaration_tracker import DeclarationTracker
from common.utils import extract_balanced_parentheses
from common.import_parser import ImportParser
from common.import_tag_resolver import ImportTagResolver
from reactive_wrapper import ReactiveWrapper
from hydrate_processor import BladeHydrateProcessor


class BladeTemplateCompiler:
    """
    Compiler chuyển .sao template sang Blade PHP với reactive markers.
    
    Flow:
    1. Parse declarations (@useState, @const, @let, @vars) để xác định state variables
    2. Tách template content (trong <blade> hoặc <template>)
    3. Wrap reactive directives với @startReactive/@endReactive
    4. Output blade.php file
    """
    
    def __init__(self):
        self.declaration_tracker = DeclarationTracker()
        self.view_template = self._load_view_template()
    
    def _load_view_template(self):
        """Load view.blade.php template from compiler/templates/"""
        try:
            current_dir = os.path.dirname(os.path.abspath(__file__))
            template_path = os.path.join(current_dir, '..', '..', 'templates', 'view.blade.php')
            template_path = os.path.normpath(template_path)
            with open(template_path, 'r', encoding='utf-8') as f:
                return f.read()
        except Exception as e:
            print(f"Warning: Could not load view.blade.php template: {e}")
            return None
    
    def compile(self, one_content, declarations=None, ssr_content=None, template_content=None):
        """
        Compile .sao content sang Blade PHP với reactive markers.
        
        Args:
            one_content: Full .sao file content (fallback nếu không truyền parts)
            declarations: List of declaration strings (từ Node.js parser) 
            ssr_content: SSR content string (từ Node.js parser)
            template_content: Template/blade content string (từ Node.js parser)
        
        Returns:
            Processed blade content string
        """
        # If parts are provided (from Node.js), use them directly
        if template_content is not None:
            blade_content = template_content
            decl_list = declarations or []
        else:
            # Parse from raw .sao content
            decl_list, blade_content, ssr_content = self._parse_one_content(one_content)
        
        # Step 0: Parse @import directives and resolve custom tags
        import_parser = ImportParser()
        component_imports = import_parser.parse_imports(one_content)
        
        # Remove @import directives from blade content
        blade_content = import_parser.remove_imports(blade_content)
        
        # Also remove @import from declarations list
        decl_list = [d for d in decl_list if not d.strip().startswith('@import')]
        
        # Auto-inject $__ONE_CHILDREN_CONTENT__ declaration if @children is used
        has_children_directive = bool(re.search(r'@children\b', blade_content, re.IGNORECASE)) or \
                                 bool(re.search(r'@children\b', one_content, re.IGNORECASE))
        if has_children_directive:
            # Check if already present in any declaration
            has_children_var = any('__ONE_CHILDREN_CONTENT__' in d for d in decl_list)
            if not has_children_var:
                # Try to merge into existing @vars or @props declaration
                merged = False
                for i, d in enumerate(decl_list):
                    if d.strip().startswith('@vars('):
                        # Append to existing @vars: @vars($existing) → @vars($__ONE_CHILDREN_CONTENT__ = '', $existing)
                        inner = d.strip()[6:-1]  # Remove @vars( and )
                        decl_list[i] = f"@vars($__ONE_CHILDREN_CONTENT__ = '', {inner})"
                        merged = True
                        break
                    elif d.strip().startswith('@props('):
                        # For @props, add __ONE_CHILDREN_CONTENT__ as a separate @vars
                        # since @props has different Blade output format
                        pass
                if not merged:
                    decl_list.insert(0, "@vars($__ONE_CHILDREN_CONTENT__ = '')")
            # Replace @children directive with Blade echo
            # Use [^\S\n]* instead of \s* to avoid consuming newlines
            blade_content = re.sub(
                r'@children[^\S\n]*(?:\([^\S\n]*\))?',
                "{!! $__ONE_CHILDREN_CONTENT__ ?? '' !!}",
                blade_content,
                flags=re.IGNORECASE
            )
        
        # Resolve custom tags to @include directives (blade target)
        if component_imports:
            tag_resolver = ImportTagResolver(imports=component_imports, target='blade')
            blade_content = tag_resolver.resolve_tags(blade_content)
            # Resolve @importInclude...@endImportInclude blocks for blade output
            blade_content = self._resolve_import_includes_blade(blade_content)
        
        # Step 1: Parse declarations to find state variables
        state_variables = self._extract_state_variables(one_content, decl_list)
        
        # Step 2: Detect if view uses @extends
        has_extends = bool(re.search(r'@extends\s*\(', one_content))
        
        # Step 3: If no state variables, still add hydrate IDs but skip reactive wrapping
        if not state_variables:
            # Add hydrate IDs without reactive markers
            hydrate_proc = BladeHydrateProcessor(state_variables=set())
            processed_template = hydrate_proc.process(blade_content, has_extends=has_extends)
            return self._assemble_output(decl_list, ssr_content, processed_template, component_imports, has_extends=has_extends)
        
        # Step 4: Add hydrate IDs and @startMarker/@endMarker (replaces old ReactiveWrapper)
        hydrate_proc = BladeHydrateProcessor(state_variables=state_variables)
        processed_template = hydrate_proc.process(blade_content, has_extends=has_extends)
        
        # Step 5: Assemble final output
        return self._assemble_output(decl_list, ssr_content, processed_template, component_imports, has_extends=has_extends)
    
    def _parse_one_content(self, content):
        """
        Parse raw .sao file content to extract declarations, template, SSR.
        Mirrors the logic in Node.js index.js parseOneFile().
        """
        declarations = []
        ssr_content = ''
        template_content = ''
        
        # Keep @ssr/@endssr directives in content — hydrate processor will handle them
        # (skip ID generation for SSR content, strip directives from output)
        ssr_content = ''  # No longer extracted separately
        content_ssr_inline = content  # Keep @ssr directives for hydrate processor
        
        # Remove SSR blocks from content (for declaration extraction consistency)
        ssr_block_pattern = r'@(?:serverside|serverSide|ssr|SSR|useSSR|useSsr)\b[\s\S]*?@end(?:serverside|serverSide|ServerSide|SSR|Ssr|ssr|useSSR|useSsr)\b'
        content_no_ssr = re.sub(ssr_block_pattern, '', content, flags=re.IGNORECASE)
        
        # Extract declarations
        declaration_types = ['useState', 'states', 'const', 'let', 'var', 'vars', 'props']
        found_declarations = []
        
        for dtype in declaration_types:
            pattern = re.compile(r'@' + dtype + r'\s*\(', re.IGNORECASE)
            for match in pattern.finditer(content_no_ssr):
                paren_start = match.end() - 1
                inner, end_pos = extract_balanced_parentheses(content_no_ssr, paren_start)
                if inner is not None:
                    full_decl = content_no_ssr[match.start():end_pos]
                    found_declarations.append({
                        'text': full_decl,
                        'index': match.start()
                    })
        
        found_declarations.sort(key=lambda x: x['index'])
        declarations = [d['text'] for d in found_declarations]
        
        # Extract template content (with SSR content kept in-place)
        # Check for <blade> or <template> wrappers
        template_content = self._extract_template(content_ssr_inline, declarations)
        
        return declarations, template_content, ssr_content
    
    def _extract_template(self, content, declarations):
        """Extract template content from <blade>/<template> wrapper or full content"""
        # Try <blade> first
        blade_match = re.search(r'<blade>([\s\S]*?)</blade>', content)
        if blade_match:
            return blade_match.group(1).strip()
        
        # Try <template>
        template_match = re.search(r'<template>([\s\S]*?)</template>', content)
        if template_match:
            return template_match.group(1).strip()
        
        # No wrapper - extract content minus script/style/declarations
        temp = content
        temp = re.sub(r'<script[\s\S]*?</script>', '', temp, flags=re.IGNORECASE)
        temp = re.sub(r'<style[\s\S]*?</style>', '', temp, flags=re.IGNORECASE)
        
        for decl in declarations:
            temp = temp.replace(decl, '')
        
        return temp.strip()
    
    def _extract_state_variables(self, one_content, declarations):
        """
        Extract state variable names from declarations.
        Returns a set of variable names (without $ prefix).
        """
        state_variables = set()
        
        # Use DeclarationTracker to parse declarations properly
        all_decls = self.declaration_tracker.parse_all_declarations(one_content)
        
        for decl in all_decls:
            if decl.get('type') in ('useState', 'states'):
                variables = decl.get('variables', [])
                for var in variables:
                    if var.get('isUseState'):
                        names = var.get('names', [])
                        for name in names:
                            if name and name.replace('_', '').isalnum():
                                state_variables.add(name)
                    elif var.get('name'):
                        # Simple @useState($varName, value) format
                        state_variables.add(var['name'])
            
            # Also check @const and @let that use useState
            elif decl.get('type') in ['const', 'let']:
                variables = decl.get('variables', [])
                for var in variables:
                    if var.get('isUseState'):
                        names = var.get('names', [])
                        for name in names:
                            if name and name.replace('_', '').isalnum():
                                state_variables.add(name)
        
        return state_variables
    
    def _assemble_output(self, declarations, ssr_content, template_content, component_imports=None, has_extends=False):
        """Assemble the final blade.php output using view.blade.php template"""
        
        # Build component registry from imports
        registry = self._build_component_registry(component_imports)
        
        # Convert @props declarations to PHP isset/default format
        declarations = self._convert_props_declarations(declarations)
        
        # Wrap template in @wrapper/@endWrapper when no @extends
        if not has_extends:
            # Check if @wrapper/@endWrapper already exists
            has_wrapper = bool(re.search(r'@wrapper\b', template_content, re.IGNORECASE))
            if not has_wrapper:
                # Check for @pageStart/@pageEnd
                page_start_match = re.search(r'^(\s*@pageStart\b.*)', template_content, re.MULTILINE | re.IGNORECASE)
                page_end_match = re.search(r'^(\s*@pageEnd\b.*)', template_content, re.MULTILINE | re.IGNORECASE)
                if page_start_match and page_end_match:
                    # Insert @wrapper after @pageStart and @endWrapper before @pageEnd
                    ps_end = page_start_match.end()
                    pe_start = page_end_match.start()
                    template_content = (
                        template_content[:ps_end] + '\n@wrapper'
                        + template_content[ps_end:pe_start].rstrip()
                        + '\n@endWrapper\n'
                        + template_content[pe_start:]
                    )
                else:
                    # No @pageStart/@pageEnd — wrap entire template
                    template_content = '@wrapper\n' + template_content + '\n@endWrapper'
            else:
                # @wrapper exists — verify position relative to @pageStart/@pageEnd
                page_start_match = re.search(r'^(\s*@pageStart\b.*)', template_content, re.MULTILINE | re.IGNORECASE)
                page_end_match = re.search(r'^(\s*@pageEnd\b.*)', template_content, re.MULTILINE | re.IGNORECASE)
                wrapper_match = re.search(r'^(\s*@wrapper\b)', template_content, re.MULTILINE | re.IGNORECASE)
                end_wrapper_match = re.search(r'^(\s*@endWrapper\b)', template_content, re.MULTILINE | re.IGNORECASE)
                if page_start_match and page_end_match and wrapper_match and end_wrapper_match:
                    # Ensure @wrapper is AFTER @pageStart and @endWrapper is BEFORE @pageEnd
                    if wrapper_match.start() < page_start_match.start():
                        # @wrapper is before @pageStart — need to fix
                        # Remove existing @wrapper and @endWrapper
                        template_content = re.sub(r'^\s*@wrapper\b\s*\n?', '', template_content, count=1, flags=re.MULTILINE | re.IGNORECASE)
                        template_content = re.sub(r'^\s*@endWrapper\b\s*\n?', '', template_content, count=1, flags=re.MULTILINE | re.IGNORECASE)
                        # Re-find positions after removal
                        page_start_match = re.search(r'^(\s*@pageStart\b.*)', template_content, re.MULTILINE | re.IGNORECASE)
                        page_end_match = re.search(r'^(\s*@pageEnd\b.*)', template_content, re.MULTILINE | re.IGNORECASE)
                        if page_start_match and page_end_match:
                            ps_end = page_start_match.end()
                            pe_start = page_end_match.start()
                            template_content = (
                                template_content[:ps_end] + '\n@wrapper'
                                + template_content[ps_end:pe_start].rstrip()
                                + '\n@endWrapper\n'
                                + template_content[pe_start:]
                            )
        
        if self.view_template:
            output = self.view_template
            
            # Replace component registry placeholder
            output = output.replace('[ONE_COMPONENT_REGISTRY]', registry)
            
            # Build declarations block
            decl_block = '\n'.join(declarations) + '\n' if declarations else ''
            output = output.replace('[BLADE_DECLARATIONS]\n', decl_block)
            
            # Build SSR block (no longer separate — SSR content is inline in template)
            output = output.replace('[BLADE_SSR_CONTENT]\n', '')
            
            # Build template block
            output = output.replace('[BLADE_TEMPLATE_CONTENT]', template_content)
            
            return output
        
        # Fallback: simple concat (no template available)
        parts = []
        if declarations:
            parts.append('\n'.join(declarations))
            parts.append('')
        parts.append(template_content)
        return '\n'.join(parts)
    
    def _convert_props_declarations(self, declarations):
        """Convert @props and @states directives to their Blade equivalents.
        
        @props → <?php if(!isset($var) || ...) $var = default; ?>
        @states → @useState (multiple individual @useState directives)
        """
        result = []
        for decl in declarations:
            stripped = decl.strip()
            if stripped.startswith('@props(') and stripped.endswith(')'):
                blade_php = self._props_to_blade_php(stripped)
                if blade_php:
                    result.append(blade_php)
                # If no blade_php (no defaults), skip entirely
            elif stripped.startswith('@states('):
                # Convert @states to individual @useState directives
                usestate_directives = self._states_to_usestate(stripped)
                result.extend(usestate_directives)
            else:
                result.append(decl)
        return result
    
    def _states_to_usestate(self, states_decl):
        """Convert @states(...) to list of @useState($key, value) directives.
        
        @states(['count' => 0, 'e' => $b]) → [@useState($count, 0), @useState($e, $b)]
        @states($count = 0, $e = $b) → [@useState($count, 0), @useState($e, $b)]
        """
        # Find the content inside balanced parentheses
        paren_start = states_decl.index('(')
        inner = states_decl[paren_start + 1:-1].strip()
        
        directives = []
        
        if inner.startswith('[') and inner.endswith(']'):
            # Array format
            pairs = self._parse_props_array_blade(inner[1:-1].strip())
            for var_name, default_value in pairs:
                if default_value is not None:
                    directives.append(f"@useState(${var_name}, {default_value})")
                else:
                    directives.append(f"@useState(${var_name}, null)")
        else:
            # Standard format
            pairs = self._parse_props_standard_blade(inner)
            for var_name, default_value in pairs:
                if default_value is not None:
                    directives.append(f"@useState(${var_name}, {default_value})")
                else:
                    directives.append(f"@useState(${var_name}, null)")
        
        return directives
    
    def _props_to_blade_php(self, props_decl):
        """Convert a single @props(...) declaration to PHP isset code.
        
        Returns: PHP code string or empty string if no props with defaults
        """
        # Extract content inside @props(...)
        inner = props_decl[7:-1].strip()  # Remove @props( and )
        
        # Determine format: array [...] or standard $var, $var = default
        if inner.startswith('[') and inner.endswith(']'):
            pairs = self._parse_props_array_blade(inner[1:-1].strip())
        else:
            pairs = self._parse_props_standard_blade(inner)
        
        if not pairs:
            return ''
        
        # Build PHP isset/default statements
        stmts = []
        for var_name, default_value in pairs:
            if default_value is not None:
                stmts.append(
                    f"if(!isset(${var_name}) || (!${var_name} && ${var_name} !== false)) ${var_name} = {default_value};"
                )
            # Props without defaults: no PHP code needed (variable comes from @include data)
        
        if not stmts:
            return ''
        
        return '<?php ' + ' '.join(stmts) + ' ?>'
    
    def _parse_props_array_blade(self, inner):
        """Parse array format: 'key1' => value1, 'key2' => value2
        Returns list of (var_name, default_value_or_None)
        """
        pairs = []
        parts = self._split_by_comma_balanced(inner)
        for part in parts:
            part = part.strip()
            if '=>' in part:
                arrow_pos = part.find('=>')
                key = part[:arrow_pos].strip().strip("'\"").lstrip('$')
                value = part[arrow_pos + 2:].strip()
                pairs.append((key, value))
            else:
                # Just a key name without default
                var_name = part.strip().strip("'\"").lstrip('$')
                if var_name:
                    pairs.append((var_name, None))
        return pairs
    
    def _parse_props_standard_blade(self, inner):
        """Parse standard format: $a = 0, $c = 0, $d
        Returns list of (var_name, default_value_or_None)
        """
        pairs = []
        parts = self._split_by_comma_balanced(inner)
        for part in parts:
            part = part.strip()
            if '=' in part:
                eq_pos = self._find_first_equals_pos(part)
                if eq_pos != -1:
                    var_name = part[:eq_pos].strip().lstrip('$')
                    value = part[eq_pos + 1:].strip()
                    pairs.append((var_name, value))
                else:
                    var_name = part.strip().lstrip('$')
                    if var_name:
                        pairs.append((var_name, None))
            else:
                var_name = part.strip().lstrip('$')
                if var_name:
                    pairs.append((var_name, None))
        return pairs
    
    def _split_by_comma_balanced(self, text):
        """Split by comma respecting brackets, parens, and strings."""
        parts = []
        current = ''
        depth = 0
        in_string = False
        string_char = ''
        
        for char in text:
            if char in ['"', "'"]:
                if in_string and char == string_char:
                    in_string = False
                elif not in_string:
                    in_string = True
                    string_char = char
            elif not in_string:
                if char in ['(', '[', '{']:
                    depth += 1
                elif char in [')', ']', '}']:
                    depth -= 1
                elif char == ',' and depth == 0:
                    parts.append(current.strip())
                    current = ''
                    continue
            current += char
        
        if current.strip():
            parts.append(current.strip())
        return parts
    
    def _find_first_equals_pos(self, text):
        """Find first = sign outside brackets/parens/strings."""
        depth = 0
        in_string = False
        string_char = ''
        
        for i, char in enumerate(text):
            if char in ['"', "'"]:
                if in_string and char == string_char:
                    in_string = False
                elif not in_string:
                    in_string = True
                    string_char = char
            elif not in_string:
                if char in ['(', '[', '{']:
                    depth += 1
                elif char in [')', ']', '}']:
                    depth -= 1
                elif char == '=' and depth == 0:
                    # Make sure it's not == or =>
                    if i + 1 < len(text) and text[i + 1] in ['=', '>']:
                        continue
                    return i
        return -1

    def _build_component_registry(self, component_imports):
        """Build PHP array string for $__ONE_COMPONENT_REGISTRY__ from imports dict.
        
        Args:
            component_imports: dict like {'tasks': "$__template__.'sessions.tasks'", ...}
        
        Returns:
            PHP array string like "['tasks' => $__template__.'sessions.tasks', ...]"
            or "[]" if no imports
        """
        if not component_imports:
            return '[]'
        
        entries = []
        for tag, path in component_imports.items():
            # path is already a raw PHP expression (e.g., $__template__.'sessions.tasks')
            # so don't wrap it in extra quotes
            entries.append(f"'{tag}' => {path}")
        
        return '[' + ', '.join(entries) + ']'

    # ── @importInclude resolution for Blade output ─────────────────────

    def _resolve_import_includes_blade(self, content):
        """
        Convert @importInclude...@endImportInclude blocks to Blade @include
        using Laravel's section system for safe children capture.
        
        Input format (from ImportTagResolver):
            @importInclude(tagName, path, ['attr1' => val1, ...])
            children_blade_content
            @endImportInclude
        
        Output:
            @exec($__env->startSection($__ONE_COMPONENT_REGISTRY__['tagName'].'_N'))
            children_blade_content
            @exec($__env->stopSection())
            @exec($__tagName__N_content = $__env->yieldContent($__ONE_COMPONENT_REGISTRY__['tagName'].'_N'))
            @include(path, ['attrs...', '__ONE_CHILDREN_CONTENT__' => $__tagName__N_content])
        """
        counter = [0]  # Mutable counter for nested calls
        return self._resolve_import_includes_blade_inner(content, counter)

    def _resolve_import_includes_blade_inner(self, content, counter):
        """Inner recursive resolver for @importInclude blocks."""
        max_iters = 100
        
        for _ in range(max_iters):
            match = re.search(r'@importInclude\s*\(', content)
            if not match:
                break
            
            paren_start = match.end() - 1
            args_content, args_end = extract_balanced_parentheses(content, paren_start)
            if args_content is None:
                break
            
            # Find matching @endImportInclude with nesting
            rest = content[args_end:]
            depth = 1
            pos = 0
            children_end = -1
            
            while pos < len(rest):
                next_open = re.search(r'@importInclude\s*\(', rest[pos:])
                next_close = re.search(r'@endImportInclude', rest[pos:])
                
                if not next_close:
                    break
                
                open_pos = (next_open.start() + pos) if next_open else len(rest)
                close_pos = next_close.start() + pos
                
                if open_pos < close_pos:
                    depth += 1
                    pos = open_pos + 1
                else:
                    depth -= 1
                    if depth == 0:
                        children_end = close_pos
                        break
                    pos = close_pos + len('@endImportInclude')
            
            if children_end == -1:
                break
            
            children = rest[:children_end].strip()
            total_end = args_end + children_end + len('@endImportInclude')
            
            # Recursively resolve nested @importInclude in children
            children = self._resolve_import_includes_blade_inner(children, counter)
            
            # Parse args: tagName, path [, data_array]
            args = args_content.strip()
            tag_name, path, data = self._parse_import_include_args(args)
            
            # Generate unique section name and variable
            n = counter[0]
            counter[0] += 1
            section_name = f"$__ONE_COMPONENT_REGISTRY__['{tag_name}'].'_{n}'"
            var_name = f"$__{tag_name}__{n}_content"
            
            parts = []
            parts.append(f"@exec($__env->startSection({section_name}))")
            parts.append(children)
            parts.append(f"@exec($__env->stopSection())")
            parts.append(f"@exec({var_name} = $__env->yieldContent({section_name}))")
            
            if data:
                # Insert __ONE_CHILDREN_CONTENT__ into data array
                if data.rstrip().endswith(']'):
                    data = data.rstrip()[:-1] + f", '__ONE_CHILDREN_CONTENT__' => {var_name}]"
                parts.append(f"@include({path}, {data})")
            else:
                parts.append(f"@include({path}, ['__ONE_CHILDREN_CONTENT__' => {var_name}])")
            
            replacement = '\n'.join(parts)
            content = content[:match.start()] + replacement + content[total_end:]
        
        return content

    def _parse_import_include_args(self, args):
        """
        Parse @importInclude args: tagName, path [, data_array]
        Returns (tag_name, path, data_str_or_None)
        """
        # First comma: after tagName
        first_comma = self._find_level_zero_comma(args)
        if first_comma < 0:
            # No comma — fallback
            return 'unknown', args, None
        
        tag_name = args[:first_comma].strip()
        remaining = args[first_comma + 1:].strip()
        
        # Second comma: after path (before data array)
        second_comma = self._find_level_zero_comma(remaining)
        if second_comma >= 0:
            path = remaining[:second_comma].strip()
            data = remaining[second_comma + 1:].strip()
            return tag_name, path, data
        else:
            return tag_name, remaining, None

    def _find_level_zero_comma(self, text):
        """Find first comma at nesting level 0 in text."""
        depth_p = 0
        depth_b = 0
        in_q = False
        q_ch = ''
        
        for i, c in enumerate(text):
            if in_q:
                if c == q_ch:
                    ec = 0
                    j = i - 1
                    while j >= 0 and text[j] == '\\':
                        ec += 1
                        j -= 1
                    if ec % 2 == 0:
                        in_q = False
                continue
            if c in ("'", '"'):
                in_q = True
                q_ch = c
            elif c == '(':
                depth_p += 1
            elif c == ')':
                depth_p -= 1
            elif c == '[':
                depth_b += 1
            elif c == ']':
                depth_b -= 1
            elif c == ',' and depth_p == 0 and depth_b == 0:
                return i
        return -1
