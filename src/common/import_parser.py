"""
Import Parser - Parse @import directives to build tag-to-path mapping

Supports:
1. Single import without 'as':
   @import($__template__.'sessions.tasks')           -> tag: tasks,    path: $__template__.'sessions.tasks'
   @import($__layout__.'base')                        -> tag: base,     path: $__layout__.'base'
   @import('a')                                       -> tag: a,        path: 'a'
   @import("b.d")                                     -> tag: d,        path: "b.d"

2. Single import with 'as':
   @import($__template__.'sessions.projects' as projects) -> tag: projects,  path: $__template__.'sessions.projects'
   @import($__layout__.'base' as baseLayout)              -> tag: baseLayout, path: $__layout__.'base'
   @import($__blade_custom_path__ as alert)               -> tag: alert,      path: $__blade_custom_path__

3. Array import:
   @import([
       'counter' => 'sessions.tasks.count',
       'demo'    => $__template__.'demo.fetch'
   ])
"""

import re
from common.utils import extract_balanced_parentheses


class ImportParser:
    """Parse @import directives and build tag-to-path mapping."""

    def __init__(self):
        self.imports = {}  # tag -> path

    def parse_imports(self, code):
        """
        Parse all @import directives from the code.
        Returns:
            imports: dict mapping tag_name -> raw_path_expression
        """
        self.imports = {}
        
        # Find all @import directives using balanced parentheses
        import_pattern = re.compile(r'@import\s*\(', re.IGNORECASE)
        
        pos = 0
        while pos < len(code):
            match = import_pattern.search(code, pos)
            if not match:
                break
            
            paren_start = match.end() - 1  # Position of '('
            content, end_pos = extract_balanced_parentheses(code, paren_start)
            
            if content is None:
                pos = match.end()
                continue
            
            content = content.strip()
            
            # Remove Blade comments {{-- ... --}} from content
            content = re.sub(r'\{\{--.*?--\}\}', '', content, flags=re.DOTALL).strip()
            
            if content.startswith('['):
                # Array import: @import([ 'tag' => 'path', ... ])
                self._parse_array_import(content)
            else:
                # Single import: with or without 'as'
                self._parse_single_import(content)
            
            pos = end_pos
        
        return self.imports

    def remove_imports(self, code):
        """
        Remove all @import directives from the code.
        Returns cleaned code.
        """
        import_pattern = re.compile(r'@import\s*\(', re.IGNORECASE)
        
        result = code
        # Process in reverse to preserve positions
        matches = []
        pos = 0
        while pos < len(result):
            match = import_pattern.search(result, pos)
            if not match:
                break
            
            paren_start = match.end() - 1
            content, end_pos = extract_balanced_parentheses(result, paren_start)
            
            if content is None:
                pos = match.end()
                continue
            
            matches.append((match.start(), end_pos))
            pos = end_pos
        
        # Remove matches in reverse order
        for start, end in reversed(matches):
            # Also remove trailing Blade comments on same line
            after = result[end:]
            comment_match = re.match(r'\s*\{\{--.*?--\}\}\s*', after, re.DOTALL)
            if comment_match:
                end += comment_match.end()
            # Remove trailing newline
            if end < len(result) and result[end] == '\n':
                end += 1
            result = result[:start] + result[end:]
        
        return result

    def _parse_single_import(self, content):
        """
        Parse a single @import content (non-array).
        
        Formats:
            path_expr                    -> tag from last segment
            path_expr as aliasName       -> tag = aliasName
        """
        content = content.strip()
        
        # Check for 'as' keyword (must be standalone word, not part of path)
        # Pattern: ... as identifier (at the end)
        as_match = re.search(r'\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*$', content)
        
        if as_match:
            # Has 'as' alias
            tag = as_match.group(1)
            path = content[:as_match.start()].strip()
        else:
            # No 'as' - derive tag from the last segment of the path
            path = content.strip()
            tag = self._extract_tag_from_path(path)
        
        if tag and path:
            self.imports[tag] = path

    def _parse_array_import(self, content):
        """
        Parse array import: [ 'tag1' => 'path1', 'tag2' => path2, ... ]
        """
        # Remove outer brackets
        inner = content.strip()
        if inner.startswith('[') and inner.endswith(']'):
            inner = inner[1:-1].strip()
        
        # Remove Blade comments inside array
        inner = re.sub(r'\{\{--.*?--\}\}', '', inner, flags=re.DOTALL)
        # Remove PHP-style comments // ...
        inner = re.sub(r'//[^\n]*', '', inner)
        
        # Split by commas at level 0 (respecting nested brackets, quotes, parens)
        entries = self._split_at_level_zero(inner, ',')
        
        for entry in entries:
            entry = entry.strip()
            if not entry:
                continue
            
            # Match 'tag' => path or "tag" => path
            arrow_match = re.match(
                r"""['"]([a-zA-Z_][a-zA-Z0-9_]*)['"]  # tag in quotes
                \s*=>\s*                               # =>
                (.+)                                   # path expression
                """,
                entry, re.VERBOSE | re.DOTALL
            )
            
            if arrow_match:
                tag = arrow_match.group(1)
                path = arrow_match.group(2).strip()
                # Remove trailing comma
                path = path.rstrip(',').strip()
                self.imports[tag] = path

    def _extract_tag_from_path(self, path):
        """
        Extract tag name from the last segment of a path expression.
        
        Examples:
            $__template__.'sessions.tasks'  -> tasks
            $__layout__.'base'              -> base
            'a'                             -> a
            "b.d"                           -> d
            $__blade_custom_path__          -> (use variable name heuristic)
        """
        path = path.strip()
        
        # Try to find the last string literal in the path
        # Look for the last quoted string (single or double)
        last_string_match = None
        for m in re.finditer(r"""['"]([^'"]+)['"]""", path):
            last_string_match = m
        
        if last_string_match:
            string_content = last_string_match.group(1)
            # Get the last segment (after the last dot)
            parts = string_content.split('.')
            return parts[-1]
        
        # No string literal found - try to extract from variable name
        # e.g., $__blade_custom_path__ -> blade_custom_path (strip $ and __)
        var_match = re.match(r'\$_*([a-zA-Z][a-zA-Z0-9_]*?)_*$', path)
        if var_match:
            return var_match.group(1)
        
        # Last resort: use the whole path as tag (strip special chars)
        clean = re.sub(r'[^a-zA-Z0-9_]', '', path)
        return clean if clean else None

    def _split_at_level_zero(self, text, delimiter):
        """
        Split text by delimiter, only at nesting level 0.
        Respects brackets [], parentheses (), braces {}, and quotes.
        """
        parts = []
        current = ''
        depth_bracket = 0
        depth_paren = 0
        depth_brace = 0
        in_quote = False
        quote_char = ''
        
        i = 0
        while i < len(text):
            ch = text[i]
            
            if in_quote:
                current += ch
                if ch == quote_char:
                    # Check for escaped quote
                    escape_count = 0
                    j = i - 1
                    while j >= 0 and text[j] == '\\':
                        escape_count += 1
                        j -= 1
                    if escape_count % 2 == 0:
                        in_quote = False
            elif ch in ("'", '"'):
                in_quote = True
                quote_char = ch
                current += ch
            elif ch == '[':
                depth_bracket += 1
                current += ch
            elif ch == ']':
                depth_bracket -= 1
                current += ch
            elif ch == '(':
                depth_paren += 1
                current += ch
            elif ch == ')':
                depth_paren -= 1
                current += ch
            elif ch == '{':
                depth_brace += 1
                current += ch
            elif ch == '}':
                depth_brace -= 1
                current += ch
            elif ch == delimiter and depth_bracket == 0 and depth_paren == 0 and depth_brace == 0:
                parts.append(current)
                current = ''
            else:
                current += ch
            
            i += 1
        
        if current.strip():
            parts.append(current)
        
        return parts
