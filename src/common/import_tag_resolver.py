"""
Import Tag Resolver - Resolve imported custom tags to @include directives

Transforms:
    <tasks :users="$users" title="'Custom'" />
    → @include($__template__.'sessions.tasks', ['users' => $users, 'title' => 'Custom'])

    <projects>inner content</projects>
    → @include($__template__.'sessions.projects', ['slot' => ... ]) (or without slot if empty)

Supports both self-closing and paired tags with attributes:
    - :attr="expr"   → binding attribute (PHP expression, keep as-is)
    - attr="value"   → static attribute (string value)
    - :attr="[...]"  → array/object binding
"""

import re


class ImportTagResolver:
    """Resolve custom imported tags into @include directives."""

    def __init__(self, imports=None, target='js'):
        """
        Args:
            imports: dict mapping tag_name -> raw_path_expression
            target: 'js' or 'blade' - determines output format
        """
        self.imports = imports or {}
        self.target = target  # 'js' or 'blade'

    def resolve_tags(self, code):
        """
        Replace all imported custom tags with @include directives.
        
        Process order:
        1. Self-closing tags: <tagName attr1="val1" :attr2="expr" />
        2. Paired tags: <tagName ...>children</tagName>
        
        Returns: code with custom tags replaced by @include directives
        """
        if not self.imports:
            return code
        
        # Build tag names pattern (sorted by length desc to match longer names first)
        tag_names = sorted(self.imports.keys(), key=len, reverse=True)
        tag_pattern = '|'.join(re.escape(t) for t in tag_names)
        
        # Step 1: Process self-closing tags <tagName ... />
        code = self._resolve_self_closing_tags(code, tag_pattern)
        
        # Step 2: Process paired tags <tagName ...>...</tagName>
        code = self._resolve_paired_tags(code, tag_pattern)
        
        return code

    def _resolve_self_closing_tags(self, code, tag_pattern):
        """
        Replace self-closing custom tags: <tagName attr="val" :bind="expr" />
        """
        # Match: <tagName (attributes)? />
        # Careful: tag names are case-sensitive (camelCase/PascalCase supported)
        pattern = re.compile(
            r'<(' + tag_pattern + r')'   # Opening tag with known tag name
            r'(\s[^>]*?)?'               # Optional attributes
            r'\s*/\s*>',                  # Self-closing />
            re.DOTALL
        )
        
        def replace_match(match):
            tag_name = match.group(1)
            attrs_str = match.group(2) or ''
            
            if tag_name not in self.imports:
                return match.group(0)
            
            path = self.imports[tag_name]
            attrs = self._parse_attributes(attrs_str)
            
            return self._build_include(path, attrs)
        
        return pattern.sub(replace_match, code)

    def _resolve_paired_tags(self, code, tag_pattern):
        """
        Replace paired custom tags: <tagName ...>children</tagName>
        Handles nested tags properly using iterative matching.
        """
        # Process each tag name independently to handle nesting
        for tag_name in self.imports:
            code = self._resolve_single_paired_tag(code, tag_name)
        
        return code

    def _resolve_single_paired_tag(self, code, tag_name):
        """Resolve a single paired tag type, handling nesting."""
        escaped_tag = re.escape(tag_name)
        
        # Pattern for opening tag (not self-closing)
        open_pattern = re.compile(
            r'<' + escaped_tag + r'(\s[^>]*?)?(?<!/)\s*>',
            re.DOTALL
        )
        
        max_iterations = 100  # Safety limit
        iterations = 0
        
        while iterations < max_iterations:
            iterations += 1
            
            match = open_pattern.search(code)
            if not match:
                break
            
            # Find matching closing tag, accounting for nesting
            open_start = match.start()
            open_end = match.end()
            attrs_str = match.group(1) or ''
            
            # Find matching </tagName>
            close_tag = f'</{tag_name}>'
            close_pos = self._find_matching_close_tag(code, tag_name, open_end)
            
            if close_pos == -1:
                # No matching close tag found - skip this occurrence
                break
            
            # Extract children content
            children = code[open_end:close_pos].strip()
            close_end = close_pos + len(close_tag)
            
            path = self.imports[tag_name]
            attrs = self._parse_attributes(attrs_str)
            
            # If there are children, pass as slot content
            if children:
                include_str = self._build_include_with_slot(path, attrs, children, tag_name)
            else:
                include_str = self._build_include(path, attrs)
            
            # Replace the tag
            code = code[:open_start] + include_str + code[close_end:]
        
        return code

    def _find_matching_close_tag(self, code, tag_name, start_pos):
        """
        Find the matching closing tag position, handling nested same-name tags.
        Returns the start position of the closing tag, or -1 if not found.
        """
        escaped_tag = re.escape(tag_name)
        
        # Pattern for open tags (non-self-closing)
        open_pattern = re.compile(
            r'<' + escaped_tag + r'(?:\s[^>]*?)?(?<!/)\s*>',
            re.DOTALL
        )
        # Pattern for self-closing tags (don't count these)
        self_close_pattern = re.compile(
            r'<' + escaped_tag + r'(?:\s[^>]*?)?\s*/\s*>',
            re.DOTALL
        )
        # Pattern for closing tags
        close_pattern = re.compile(
            r'</' + escaped_tag + r'\s*>'
        )
        
        depth = 1
        pos = start_pos
        
        while pos < len(code) and depth > 0:
            # Find next open, self-close, or close tag
            open_match = open_pattern.search(code, pos)
            close_match = close_pattern.search(code, pos)
            self_close_match = self_close_pattern.search(code, pos)
            
            # Get earliest match positions
            next_events = []
            if open_match:
                next_events.append(('open', open_match.start(), open_match.end()))
            if self_close_match:
                next_events.append(('self_close', self_close_match.start(), self_close_match.end()))
            if close_match:
                next_events.append(('close', close_match.start(), close_match.end()))
            
            if not next_events:
                return -1
            
            # Sort by position
            next_events.sort(key=lambda x: x[1])
            
            event_type, event_start, event_end = next_events[0]
            
            # If self-close and open match at the same position, prefer self-close
            if len(next_events) > 1:
                for evt_type, evt_start, evt_end in next_events:
                    if evt_start == next_events[0][1]:
                        if evt_type == 'self_close':
                            event_type = 'self_close'
                            event_start = evt_start
                            event_end = evt_end
                            break
            
            if event_type == 'self_close':
                # Self-closing tags don't affect depth
                pos = event_end
            elif event_type == 'open':
                depth += 1
                pos = event_end
            elif event_type == 'close':
                depth -= 1
                if depth == 0:
                    return event_start
                pos = event_end
        
        return -1

    def _parse_attributes(self, attrs_str):
        """
        Parse HTML-style attributes string into a list of (name, value, is_binding) tuples.
        
        Examples:
            :users="$users"         -> ('users', '$users', True)
            title="'Custom'"        -> ('title', "'Custom'", False)
            :data="['a' => 1]"      -> ('data', "['a' => 1]", True)
            name="name"             -> ('name', 'name', False)
        """
        attrs = []
        if not attrs_str or not attrs_str.strip():
            return attrs
        
        attrs_str = attrs_str.strip()
        
        # Match attributes: :name="value" or name="value"
        # Value can contain nested quotes, brackets, etc.
        pos = 0
        while pos < len(attrs_str):
            # Skip whitespace
            while pos < len(attrs_str) and attrs_str[pos] in ' \t\n\r':
                pos += 1
            
            if pos >= len(attrs_str):
                break
            
            # Check for binding prefix ':'
            is_binding = False
            if attrs_str[pos] == ':':
                is_binding = True
                pos += 1
            
            # Match attribute name
            name_match = re.match(r'[a-zA-Z_][a-zA-Z0-9_\-]*', attrs_str[pos:])
            if not name_match:
                pos += 1
                continue
            
            attr_name = name_match.group(0)
            pos += len(attr_name)
            
            # Skip whitespace
            while pos < len(attrs_str) and attrs_str[pos] in ' \t':
                pos += 1
            
            # Check for '='
            if pos < len(attrs_str) and attrs_str[pos] == '=':
                pos += 1
                # Skip whitespace
                while pos < len(attrs_str) and attrs_str[pos] in ' \t':
                    pos += 1
                
                # Extract value (quoted)
                if pos < len(attrs_str) and attrs_str[pos] == '"':
                    value, new_pos = self._extract_quoted_value(attrs_str, pos, '"')
                    pos = new_pos
                    attrs.append((attr_name, value, is_binding))
                elif pos < len(attrs_str) and attrs_str[pos] == "'":
                    value, new_pos = self._extract_quoted_value(attrs_str, pos, "'")
                    pos = new_pos
                    attrs.append((attr_name, value, is_binding))
                else:
                    # Unquoted value (rare but possible)
                    val_match = re.match(r'[^\s>]+', attrs_str[pos:])
                    if val_match:
                        attrs.append((attr_name, val_match.group(0), is_binding))
                        pos += len(val_match.group(0))
            else:
                # Boolean attribute (no value)
                attrs.append((attr_name, None, is_binding))
        
        return attrs

    def _extract_quoted_value(self, text, pos, quote_char):
        """
        Extract value from a quoted attribute, handling nested brackets and quotes.
        Returns (value_content, new_pos).
        """
        if pos >= len(text) or text[pos] != quote_char:
            return '', pos
        
        start = pos + 1  # Skip opening quote
        depth_bracket = 0
        depth_paren = 0
        depth_brace = 0
        in_inner_quote = False
        inner_quote_char = ''
        i = start
        
        while i < len(text):
            ch = text[i]
            
            if in_inner_quote:
                if ch == inner_quote_char:
                    # Check escape
                    escape_count = 0
                    j = i - 1
                    while j >= 0 and text[j] == '\\':
                        escape_count += 1
                        j -= 1
                    if escape_count % 2 == 0:
                        in_inner_quote = False
                i += 1
                continue
            
            if ch == quote_char and depth_bracket == 0 and depth_paren == 0 and depth_brace == 0:
                # End of attribute value
                return text[start:i], i + 1
            
            if ch in ("'", '"') and ch != quote_char:
                in_inner_quote = True
                inner_quote_char = ch
            elif ch == '[':
                depth_bracket += 1
            elif ch == ']':
                depth_bracket -= 1
            elif ch == '(':
                depth_paren += 1
            elif ch == ')':
                depth_paren -= 1
            elif ch == '{':
                depth_brace += 1
            elif ch == '}':
                depth_brace -= 1
            
            i += 1
        
        # No closing quote found, return rest
        return text[start:], len(text)

    def _build_include(self, path, attrs):
        """
        Build an @include directive string from path and attributes.
        
        For JS target:
            @include(path, ['key1' => value1, 'key2' => value2])
        For Blade target:
            @include(path, ['key1' => value1, 'key2' => value2])
        """
        if not attrs:
            return f"@include({path})"
        
        attr_parts = []
        for name, value, is_binding in attrs:
            if value is None:
                # Boolean attribute: pass as true
                attr_parts.append(f"'{name}' => true")
            elif is_binding:
                # Binding attribute: value is PHP expression, use as-is
                attr_parts.append(f"'{name}' => {value}")
            else:
                # Static attribute: value is a literal string
                # If value already has quotes, keep them
                if (value.startswith("'") and value.endswith("'")) or \
                   (value.startswith('"') and value.endswith('"')):
                    attr_parts.append(f"'{name}' => {value}")
                else:
                    # Wrap in double quotes as string literal
                    attr_parts.append(f"'{name}' => \"{value}\"")
        
        attrs_str = ', '.join(attr_parts)
        return f"@include({path}, [{attrs_str}])"

    def _build_include_with_slot(self, path, attrs, children, tag_name='unknown'):
        """
        Build @importInclude block directive for paired tags with children content.
        
        Output:
            @importInclude(tagName, path, ['attr1' => val1, ...])
            children_content
            @endImportInclude
        
        The @importInclude block is later resolved by the template processor (JS)
        or blade compiler (Blade) to produce the final output with
        __ONE_CHILDREN_CONTENT__ parameter.
        
        tagName is embedded as first arg so Blade resolver can generate unique
        section names: $__ONE_COMPONENT_REGISTRY__['tagName'].'_N'
        """
        if attrs:
            attr_parts = []
            for name, value, is_binding in attrs:
                if value is None:
                    attr_parts.append(f"'{name}' => true")
                elif is_binding:
                    attr_parts.append(f"'{name}' => {value}")
                else:
                    if (value.startswith("'") and value.endswith("'")) or \
                       (value.startswith('"') and value.endswith('"')):
                        attr_parts.append(f"'{name}' => {value}")
                    else:
                        attr_parts.append(f"'{name}' => \"{value}\"")
            attrs_str = ', '.join(attr_parts)
            return f"@importInclude({tag_name}, {path}, [{attrs_str}])\n{children}\n@endImportInclude"
        else:
            return f"@importInclude({tag_name}, {path})\n{children}\n@endImportInclude"
