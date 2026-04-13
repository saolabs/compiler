"""
Saola Template AST Parser

Parse .sao template content vào AST (Abstract Syntax Tree) với hydrate IDs.
AST này được dùng chung cho cả sao2blade và sao2js compiler, đảm bảo 
hydrate IDs đồng nhất giữa SSR HTML (blade) và CSR JS.

AST Node Types:
- Element: HTML element (<div>, <span>, etc.)
- Text: Plain text content
- Echo: {{ expr }} or {!! expr !!}
- Directive: @if, @foreach, @while, @for, @switch, etc.
- Block: @block('name') ... @endblock
- Component: Custom component tags hoặc @include
- Comment: {{-- comment --}}
- Wrapper: @wrapper ... @endWrapper
- PageStart/PageEnd: @pageStart, @pageEnd
- Extends: @extends(...)
- Raw: Content that should not be processed (verbatim, etc.)
"""

import re
from common.hydrate_id import HydrateIdGenerator, make_loop_id_js, make_loop_id_blade


class ASTNode:
    """Base AST node."""
    def __init__(self, node_type, **kwargs):
        self.type = node_type
        self.children = kwargs.get('children', [])
        self.attrs = kwargs  # Store all kwargs as attributes
    
    def __repr__(self):
        return f"ASTNode({self.type}, {self.attrs})"


class TemplateASTParser:
    """
    Parse .sao template content into an AST with hydrate IDs.
    
    This parser is responsible for:
    1. Parsing HTML structure (tags, attributes, text)
    2. Parsing Blade-like directives (@if, @foreach, etc.)
    3. Parsing echo expressions ({{ }}, {!! !!})
    4. Assigning hydrate IDs via HydrateIdGenerator
    5. Handling block context (@block, @extends)
    6. Handling wrapper context (@wrapper, @pageStart)
    """
    
    # Self-closing HTML tags (void elements)
    VOID_ELEMENTS = {
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr'
    }
    
    def __init__(self):
        self.id_gen = HydrateIdGenerator()
    
    def parse(self, template_content, state_variables=None):
        """
        Parse template content into AST nodes.
        
        Args:
            template_content: The template HTML/directive content
            state_variables: Set of state variable names for reactive detection
            
        Returns:
            List of ASTNode objects
        """
        self.state_variables = state_variables or set()
        self.id_gen.reset()
        
        # Normalize line endings
        template_content = template_content.replace('\r\n', '\n')
        
        # Parse into tokens first, then build AST
        tokens = self._tokenize(template_content)
        ast = self._build_ast(tokens)
        
        return ast
    
    def _tokenize(self, content):
        """
        Tokenize template content into a flat list of tokens.
        Each token is a dict with 'type' and relevant data.
        """
        tokens = []
        pos = 0
        length = len(content)
        
        while pos < length:
            # Try to match different token types
            
            # 1. Blade comment {{-- ... --}}
            if content[pos:pos+3] == '{{-':
                match = re.match(r'\{\{--.*?--\}\}', content[pos:], re.DOTALL)
                if match:
                    tokens.append({'type': 'comment', 'content': match.group(0)})
                    pos += match.end()
                    continue
            
            # 2. Raw echo {!! expr !!}
            if content[pos:pos+3] == '{!!':
                match = re.match(r'\{!!\s*(.*?)\s*!!\}', content[pos:], re.DOTALL)
                if match:
                    tokens.append({'type': 'raw_echo', 'expr': match.group(1)})
                    pos += match.end()
                    continue
            
            # 3. Echo {{ expr }}
            if content[pos:pos+2] == '{{':
                match = re.match(r'\{\{\s*(.*?)\s*\}\}', content[pos:], re.DOTALL)
                if match:
                    tokens.append({'type': 'echo', 'expr': match.group(1)})
                    pos += match.end()
                    continue
            
            # 4. Directive @ 
            if content[pos] == '@':
                directive_match = re.match(
                    r'@([\w]+)', content[pos:]
                )
                if directive_match:
                    directive_name = directive_match.group(1)
                    
                    # Check if directive has parentheses
                    after_name_pos = pos + directive_match.end()
                    # Skip whitespace
                    ws_match = re.match(r'\s*', content[after_name_pos:])
                    ws_len = ws_match.end() if ws_match else 0
                    paren_pos = after_name_pos + ws_len
                    
                    if paren_pos < length and content[paren_pos] == '(':
                        # Extract balanced parentheses content
                        inner, end = self._extract_balanced_parens(content, paren_pos)
                        if inner is not None:
                            tokens.append({
                                'type': 'directive',
                                'name': directive_name,
                                'args': inner,
                                'raw': content[pos:end]
                            })
                            pos = end
                            continue
                    
                    # Directive without parens
                    tokens.append({
                        'type': 'directive',
                        'name': directive_name,
                        'args': None,
                        'raw': directive_match.group(0)
                    })
                    pos += directive_match.end()
                    continue
            
            # 5. HTML tag (opening, closing, self-closing)
            if content[pos] == '<':
                # Closing tag
                close_match = re.match(r'</\s*([a-zA-Z][\w-]*)\s*>', content[pos:])
                if close_match:
                    tokens.append({
                        'type': 'close_tag',
                        'tag': close_match.group(1).lower()
                    })
                    pos += close_match.end()
                    continue
                
                # Opening/self-closing tag
                tag_match = re.match(
                    r'<\s*([a-zA-Z][\w-]*)((?:\s+[^>]*?)?)\s*(/?)>',
                    content[pos:], re.DOTALL
                )
                if tag_match:
                    tag_name = tag_match.group(1).lower()
                    attrs_str = tag_match.group(2).strip()
                    is_self_closing = bool(tag_match.group(3)) or tag_name in self.VOID_ELEMENTS
                    
                    tokens.append({
                        'type': 'open_tag',
                        'tag': tag_name,
                        'attrs_str': attrs_str,
                        'self_closing': is_self_closing
                    })
                    pos += tag_match.end()
                    continue
            
            # 6. Text content (collect until next special character)
            text_end = pos
            while text_end < length and content[text_end] not in ('<', '@', '{'):
                text_end += 1
            
            if text_end == pos:
                # Single special char that didn't match above - treat as text
                text_end = pos + 1
            
            text = content[pos:text_end]
            if text.strip():
                tokens.append({'type': 'text', 'content': text})
            elif text:
                tokens.append({'type': 'whitespace', 'content': text})
            
            pos = text_end
        
        return tokens
    
    def _extract_balanced_parens(self, text, start_pos):
        """Extract content within balanced parentheses starting at start_pos.
        Returns (inner_content, end_position) or (None, start_pos)."""
        if start_pos >= len(text) or text[start_pos] != '(':
            return None, start_pos
        
        depth = 0
        in_string = False
        string_char = None
        i = start_pos
        
        while i < len(text):
            ch = text[i]
            
            if in_string:
                if ch == '\\' and i + 1 < len(text):
                    i += 2
                    continue
                if ch == string_char:
                    in_string = False
            else:
                if ch in ('"', "'"):
                    in_string = True
                    string_char = ch
                elif ch == '(':
                    depth += 1
                elif ch == ')':
                    depth -= 1
                    if depth == 0:
                        return text[start_pos + 1:i], i + 1
            i += 1
        
        return None, start_pos
    
    def _build_ast(self, tokens):
        """Build AST from flat token list."""
        # For now, return tokens as-is
        # The actual AST building will be done by the specific compilers
        # since blade and JS have different processing needs
        return tokens
    
    def _get_state_keys_from_expr(self, expr):
        """Extract state variable names referenced in expression."""
        if not expr:
            return []
        vars_found = re.findall(r'\$([a-zA-Z_][a-zA-Z0-9_]*)', expr)
        return sorted(set(v for v in vars_found if v in self.state_variables))
