"""
Render Generator - Generate structured JS render() code from template AST.

Walks an AST produced by TemplateASTParser and generates JS code using
this.html(), this.reactive(), this.output(), this.__foreach(), this.__while(),
this.block(), this.blockOutlet(), this.wrapper() API calls.

Uses HydrateIdGenerator from common to produce IDs matching sao2blade output.
"""

import re
import sys
import os

_parent_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _parent_dir not in sys.path:
    sys.path.insert(0, _parent_dir)

from common.hydrate_id import HydrateIdGenerator
from template_ast import (
    Node, RootNode, HtmlElement, TextNode, EchoNode,
    IfBlock, ForeachBlock, WhileBlock, ForBlock, SwitchBlock,
    BlockSection, BlockOutlet, IncludeNode, ImportIncludeNode, ExecNode,
    SectionNode, LongSectionNode, YieldNode
)


class RenderGenerator:
    """Generate structured JS render code from template AST.

    Usage:
        gen = RenderGenerator(state_variables={'status', 'user', 'posts'})
        render_body = gen.generate(ast_root, has_extends=True, extends_expr=...)
    """

    def __init__(self, state_variables=None, declared_variables=None, is_typescript=False):
        self.state_variables = state_variables or set()
        self.declared_variables = declared_variables or set()
        self._is_ts = is_typescript
        self.id_gen = HydrateIdGenerator()
        # Track active loop scopes: [(id_prefix, js_loop_expr)]
        self._loop_scopes = []
        # Track ancestor context for output decisions
        self._in_while_or_for = False
        self._while_for_vars = set()  # Loop counter vars (e.g. {'i'})
        self._declared_scope_stack = []

        self._exec_reserved_names = {
            'true', 'false', 'null', 'undefined', 'this', 'super',
            'if', 'else', 'for', 'while', 'switch', 'case', 'default',
            'return', 'break', 'continue', 'new', 'delete', 'typeof',
            'instanceof', 'in', 'of', 'void', 'yield', 'await',
            'let', 'const', 'var', 'class', 'function',
            'App', '__STATE__', '__VIEW_ID__',
            'parentElement', 'parentReactive',
            '__loop', '__loopKey', '__loopIndex', 'loopCtx'
        }

    def _param(self, name):
        """Add type annotation for parameter if TypeScript."""
        return f'{name}: any' if self._is_ts else name
    
    def _arrow_parent(self):
        """Generate arrow function parameter string for (parentElement) =>"""
        return f'({self._param("parentElement")}) =>'
    
    def _arrow_reactive(self):
        """Generate arrow function parameters string for (parentReactive, parentElement) =>"""
        return f'({self._param("parentReactive")}, {self._param("parentElement")}) =>'

    def generate(self, root, has_extends=False, extends_expression=None,
                 extends_data=None, block_sections=None, prerendered_sections=None):
        """Generate the complete render function body.

        Args:
            root: RootNode from parser
            has_extends: Whether this view uses @extends
            extends_expression: JS expression for extends path
            extends_data: JS expression for extends data
            block_sections: Optional list of block section names (from AST)
            prerendered_sections: Set of section names already declared in prerender (skip in render)

        Returns:
            String containing the JS function body (without function keyword/braces)
        """
        self.id_gen.reset()
        self._loop_scopes = []
        self._in_while_or_for = False
        self._while_for_vars = set()
        self._prerendered_sections = prerendered_sections or set()
        self._declared_scope_stack = [
            set(self.declared_variables) | set(self.state_variables) | {
                'parentElement', 'parentReactive'
            }
        ]

        lines = []
        lines.append('let parentElement = this.parentElement;')
        lines.append('let parentReactive = null;')

        if has_extends:
            # Render sections and blocks, then extends
            for node in root.children:
                if isinstance(node, BlockSection):
                    if node.name not in self._prerendered_sections:
                        block_code = self._gen_block_section(node, '')
                        lines.append(block_code)
                elif isinstance(node, SectionNode):
                    if node.name not in self._prerendered_sections:
                        section_code = self._gen_section(node, '')
                        lines.append(section_code)
                elif isinstance(node, LongSectionNode):
                    if node.name not in self._prerendered_sections:
                        section_code = self._gen_long_section(node, '')
                        lines.append(section_code)

            if extends_expression:
                lines.append(f'this.superViewPath = {extends_expression};')
                data_param = ', ' + extends_data if extends_data else ', {}'
                lines.append(f'return this.extendView(this.superViewPath{data_param});')
            else:
                lines.append("return this.extendView(this.superViewPath);")
        else:
            # Non-extends: wrap in this.wrapper()
            arrow = self._arrow_parent()
            if self._has_exec_nodes(root.children):
                arr_name = '__execArr'
                self._push_declared_scope({'parentElement'})
                imp_code = self._gen_children_imperative(root.children, '        ', arr_name)
                self._pop_declared_scope()
                lines.append(f'return this.wrapper({arrow} {{')
                lines.append(f'    const {arr_name} = [];')
                lines.append(imp_code)
                lines.append(f'    return {arr_name};')
                lines.append('});')
            else:
                children_code = self._gen_children_list(root.children, '    ')
                lines.append(f'return this.wrapper({arrow} [')
                lines.append(children_code)
                lines.append(']);')

        return '\n'.join(lines)

    def _push_declared_scope(self, names=None):
        self._declared_scope_stack.append(set(names or []))

    def _pop_declared_scope(self):
        if self._declared_scope_stack:
            self._declared_scope_stack.pop()

    def _is_declared(self, name):
        for scope in reversed(self._declared_scope_stack):
            if name in scope:
                return True
        return False

    def _declare_in_current_scope(self, name):
        if not self._declared_scope_stack:
            self._declared_scope_stack = [set()]
        self._declared_scope_stack[-1].add(name)

    def _normalize_exec_expression(self, js_expr):
        """Prefix 'let' for first assignment to undeclared bare identifiers."""
        from common.utils import split_top_level_semicolons

        statements = split_top_level_semicolons(js_expr)
        if not statements:
            statements = [js_expr]

        normalized = []
        assign_pattern = re.compile(r'^\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(?![=])(.*)$', re.DOTALL)

        for stmt in statements:
            s = stmt.strip()
            if not s:
                continue

            m = assign_pattern.match(s)
            if m:
                var_name = m.group(1)
                if (
                    var_name not in self._exec_reserved_names and
                    var_name not in self._while_for_vars and
                    not self._is_declared(var_name)
                ):
                    s = f'let {s}'
                    self._declare_in_current_scope(var_name)

            normalized.append(s)

        return '; '.join(normalized)

    def _extract_condition_assignment_vars(self, js_expr):
        """Extract bare identifiers assigned within a conditional expression."""
        if not js_expr:
            return []

        pattern = re.compile(r'(?<![\w.\]])\b([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(?![=])')
        result = []
        for match in pattern.finditer(js_expr):
            name = match.group(1)
            if name in self._exec_reserved_names:
                continue
            if name not in result:
                result.append(name)
        return result

    # ──────────────────────────────────────────────────────────────────
    # Children list generation
    # ──────────────────────────────────────────────────────────────────

    def _has_exec_nodes(self, nodes):
        """Return True if any direct child is an ExecNode."""
        return any(isinstance(n, ExecNode) for n in nodes)

    def _gen_children_imperative(self, nodes, indent, arr_name='__execArr'):
        """Generate imperative array-building code for a list that may contain ExecNodes.

        Produces statements of the form:
            exec_statement;
            arr_name.push(content_node_code);
        which preserves the order of exec statements relative to content nodes.
        """
        lines = []
        for node in nodes:
            if isinstance(node, ExecNode):
                exec_expr = self._normalize_exec_expression(node.js_expr)
                lines.append(f'{indent}{exec_expr};')
            else:
                code = self._gen_node(node, indent + '    ')
                if code is not None:
                    lines.append(f'{indent}{arr_name}.push(')
                    lines.append(code)
                    lines.append(f'{indent});')
        return '\n'.join(lines) if lines else ''

    def _gen_children_list(self, nodes, indent):
        """Generate comma-separated list of child expressions."""
        items = []
        for node in nodes:
            code = self._gen_node(node, indent)
            if code is not None:
                items.append(code)
        return (',\n').join(items)

    def _gen_node(self, node, indent):
        """Generate JS code for a single AST node."""
        if isinstance(node, HtmlElement):
            return self._gen_html(node, indent)
        elif isinstance(node, TextNode):
            return self._gen_text(node, indent)
        elif isinstance(node, EchoNode):
            return self._gen_echo(node, indent)
        elif isinstance(node, IfBlock):
            return self._gen_if(node, indent)
        elif isinstance(node, ForeachBlock):
            return self._gen_foreach(node, indent)
        elif isinstance(node, WhileBlock):
            return self._gen_while(node, indent)
        elif isinstance(node, ForBlock):
            return self._gen_for(node, indent)
        elif isinstance(node, SwitchBlock):
            return self._gen_switch(node, indent)
        elif isinstance(node, BlockOutlet):
            return self._gen_block_outlet(node, indent)
        elif isinstance(node, YieldNode):
            return self._gen_yield(node, indent)
        elif isinstance(node, ImportIncludeNode):
            return self._gen_import_include(node, indent)
        elif isinstance(node, IncludeNode):
            return self._gen_include(node, indent)
        elif isinstance(node, ExecNode):
            # ExecNode in a children array context - should only occur in while/for
            return None  # Handled specially in while/for generation
        elif isinstance(node, BlockSection):
            # Standalone block (shouldn't happen in non-extends, but handle gracefully)
            if node.name in self._prerendered_sections:
                return None
            return self._gen_block_section(node, indent)
        elif isinstance(node, SectionNode):
            if node.name in self._prerendered_sections:
                return None
            return self._gen_section(node, indent)
        elif isinstance(node, LongSectionNode):
            if node.name in self._prerendered_sections:
                return None
            return self._gen_long_section(node, indent)
        return None

    # ──────────────────────────────────────────────────────────────────
    # HTML Element
    # ──────────────────────────────────────────────────────────────────

    def _gen_html(self, node, indent):
        """Generate this.html(...) call."""
        if node.is_void:
            eid = self.id_gen.next_element(node.tag)
        else:
            eid = self.id_gen.push_element(node.tag)

        id_str = self._format_id(eid)
        options = self._gen_options(node)

        if node.is_void or not node.children:
            if not node.is_void:
                self.id_gen.pop_scope()
            if options == '{}':
                return f'{indent}this.html({id_str}, "{node.tag}", parentElement, {{}})'
            else:
                return f'{indent}this.html({id_str}, "{node.tag}", parentElement, {options})'

        # Has children
        arrow = self._arrow_parent()
        if self._has_exec_nodes(node.children):
            arr_name = '__execArr'
            self._push_declared_scope({'parentElement'})
            imp_code = self._gen_children_imperative(node.children, indent + '        ', arr_name)
            self._pop_declared_scope()
            self.id_gen.pop_scope()
            if options == '{}':
                return (
                    f'{indent}this.html({id_str}, "{node.tag}", parentElement, {{}},\n'
                    f'{indent}    {arrow} {{\n'
                    f'{indent}        const {arr_name} = [];\n'
                    f'{imp_code}\n'
                    f'{indent}        return {arr_name};\n'
                    f'{indent}    }})'
                )
            else:
                return (
                    f'{indent}this.html({id_str}, "{node.tag}", parentElement,\n'
                    f'{indent}    {options},\n'
                    f'{indent}    {arrow} {{\n'
                    f'{indent}        const {arr_name} = [];\n'
                    f'{imp_code}\n'
                    f'{indent}        return {arr_name};\n'
                    f'{indent}    }})'
                )

        children_code = self._gen_children_list(node.children, indent + '    ')
        self.id_gen.pop_scope()

        if options == '{}':
            return (
                f'{indent}this.html({id_str}, "{node.tag}", parentElement, {{}}, '
                f'{arrow} [\n'
                f'{children_code}\n'
                f'{indent}])'
            )
        else:
            return (
                f'{indent}this.html({id_str}, "{node.tag}", parentElement,\n'
                f'{indent}    {options},\n'
                f'{indent}    {arrow} [\n'
                f'{children_code}\n'
                f'{indent}    ])'
            )

    # ──────────────────────────────────────────────────────────────────
    # Text and Echo
    # ──────────────────────────────────────────────────────────────────

    def _gen_text(self, node, indent):
        """Generate this.text() call."""
        text = node.text.replace('\\', '\\\\').replace("'", "\\'")
        return f"{indent}this.text('{text}')"

    def _gen_echo(self, node, indent):
        """Generate this.output() call — always wrapped."""
        _, state_keys = self._should_use_output(node)
        oid = self.id_gen.next_output()
        id_str = self._format_id(oid)
        keys_str = str(sorted(state_keys)).replace("'", '"') if state_keys else '[]'
        escape_str = 'true' if node.escaped else 'false'
        return (
            f'{indent}this.output({id_str}, parentElement, '
            f'{escape_str}, {keys_str}, {self._arrow_parent()} {node.js_expr})'
        )

    def _should_use_output(self, node):
        """Determine if echo should use this.output() wrapper.
        Returns (should_wrap, state_keys_list)."""
        # Has state variables → always output
        if node.state_vars:
            return True, list(node.state_vars)

        # Inside while/for loop with loop variable → output
        if self._in_while_or_for:
            # Check if the echo uses any while/for counter variable
            expr_vars = set(re.findall(r'\b([a-zA-Z_]\w*)\b', node.js_expr))
            overlap = expr_vars & self._while_for_vars
            if overlap:
                return True, list(overlap)

        return False, []

    # ──────────────────────────────────────────────────────────────────
    # If Block
    # ──────────────────────────────────────────────────────────────────

    def _gen_if(self, node, indent):
        """Generate this.reactive("if", ...) or inline conditional."""
        rc_id = self.id_gen.push_reactive('if')
        id_str = self._format_id(rc_id)

        state_keys = sorted(node.state_vars)
        keys_str = str(state_keys).replace("'", '"')

        lines = []
        lines.append(
            f'{indent}this.reactive({id_str}, "if", parentReactive, parentElement, '
            f'{keys_str}, {self._arrow_reactive()} {{'
        )
        lines.append(f'{indent}    const reactiveContents = [];')

        self._push_declared_scope({'parentReactive', 'parentElement'})
        condition_declared = []
        for _, cond_js, _ in node.branches:
            for name in self._extract_condition_assignment_vars(cond_js):
                if not self._is_declared(name):
                    condition_declared.append(name)
                    self._declare_in_current_scope(name)
        for name in condition_declared:
            if self._is_ts:
                lines.append(f'{indent}    let {name}: any;')
            else:
                lines.append(f'{indent}    let {name};')

        case_counter = 0
        for i, (cond_php, cond_js, children) in enumerate(node.branches):
            case_counter += 1
            self.id_gen.push_case(case_counter)

            if i == 0:
                lines.append(f'{indent}    if ({cond_js}) {{')
            elif cond_js is not None:
                lines.append(f'{indent}    else if ({cond_js}) {{')
            else:
                lines.append(f'{indent}    else {{')

            # Generate children for this branch
            self._push_declared_scope({'parentReactive', 'parentElement'})
            if self._has_exec_nodes(children):
                # Interleaved exec + content: emit individually
                for child in children:
                    if isinstance(child, ExecNode):
                        exec_expr = self._normalize_exec_expression(child.js_expr)
                        lines.append(f'{indent}        {exec_expr};')
                    else:
                        code = self._gen_node(child, indent + '        ')
                        if code is not None:
                            lines.append(f'{indent}        reactiveContents.push(')
                            lines.append(code)
                            lines.append(f'{indent}        );')
            else:
                children_items = []
                for child in children:
                    code = self._gen_node(child, indent + '        ')
                    if code is not None:
                        children_items.append(code)

                if children_items:
                    lines.append(f'{indent}        reactiveContents.push(')
                    lines.append((',\n').join(children_items))
                    lines.append(f'{indent}        );')

            self._pop_declared_scope()

            self.id_gen.pop_scope()  # pop case

            if i < len(node.branches) - 1:
                lines.append(f'{indent}    }}')
            else:
                lines.append(f'{indent}    }}')

        lines.append(f'{indent}    return reactiveContents;')
        lines.append(f'{indent}}})')

        self._pop_declared_scope()

        self.id_gen.pop_scope()  # pop reactive

        return '\n'.join(lines)

    # ──────────────────────────────────────────────────────────────────
    # Foreach Block
    # ──────────────────────────────────────────────────────────────────

    def _gen_foreach(self, node, indent):
        """Generate this.reactive("foreach", ..., this.__foreach(...))."""
        rc_id = self.id_gen.push_reactive('foreach')
        id_str = self._format_id(rc_id)

        state_keys = sorted(node.state_vars)
        keys_str = str(state_keys).replace("'", '"')

        # Push loop scope for dynamic IDs
        loop_id_expr = node.custom_key_js if node.custom_key_js else '__loopIndex'
        self._loop_scopes.append((rc_id, loop_id_expr))

        # Callback parameters
        if node.key_var:
            if self._is_ts:
                cb_params = f'({node.value_var}: any, {node.key_var}: any, __loopIndex: any, __loop: any)'
            else:
                cb_params = f'({node.value_var}, {node.key_var}, __loopIndex, __loop)'
        else:
            if self._is_ts:
                cb_params = f'({node.value_var}: any, __loopKey: any, __loopIndex: any, __loop: any)'
            else:
                cb_params = f'({node.value_var}, __loopKey, __loopIndex, __loop)'

        # Generate children
        has_exec = self._has_exec_nodes(node.children)
        if has_exec:
            arr_name = '__execArr'
            loop_vars = {node.value_var, '__loopKey', '__loopIndex', '__loop'}
            if node.key_var:
                loop_vars.add(node.key_var)
            self._push_declared_scope(loop_vars)
            children_code = self._gen_children_imperative(node.children, indent + '    ', arr_name)
            self._pop_declared_scope()
        else:
            children_code = self._gen_children_list(node.children, indent + '        ')

        # Pop loop scope
        self._loop_scopes.pop()

        self.id_gen.pop_scope()  # pop reactive

        if has_exec:
            arr_name = '__execArr'
            if state_keys:
                return (
                    f'{indent}this.reactive({id_str}, "foreach", parentReactive, parentElement, '
                    f'{keys_str}, {self._arrow_reactive()} {{\n'
                    f'{indent}    return this.__foreach({node.array_js}, {cb_params} => {{\n'
                    f'{indent}        const {arr_name} = [];\n'
                    f'{children_code}\n'
                    f'{indent}        return {arr_name};\n'
                    f'{indent}    }})\n'
                    f'{indent}}})'
                )
            else:
                return (
                    f'{indent}this.__foreach({node.array_js}, {cb_params} => {{\n'
                    f'{indent}    const {arr_name} = [];\n'
                    f'{children_code}\n'
                    f'{indent}    return {arr_name};\n'
                    f'{indent}}})'
                )
        elif state_keys:
            return (
                f'{indent}this.reactive({id_str}, "foreach", parentReactive, parentElement, '
                f'{keys_str}, {self._arrow_reactive()} {{\n'
                f'{indent}    return this.__foreach({node.array_js}, {cb_params} => [\n'
                f'{children_code}\n'
                f'{indent}    ])\n'
                f'{indent}}})'
            )
        else:
            return (
                f'{indent}this.__foreach({node.array_js}, {cb_params} => [\n'
                f'{children_code}\n'
                f'{indent}])'
            )

    # ──────────────────────────────────────────────────────────────────
    # While Block
    # ──────────────────────────────────────────────────────────────────

    def _gen_while(self, node, indent):
        """Generate this.__while((loopCtx) => { ... }, count)."""
        rc_id = self.id_gen.push_reactive('while')

        # Determine loop variable and push loop scope
        loop_var = node.loop_var or 'i'
        loop_id_expr = node.custom_key_js if node.custom_key_js else f'{loop_var}'
        self._loop_scopes.append((rc_id, loop_id_expr))

        # Track that we're inside a while/for for output decisions
        prev_in_loop = self._in_while_or_for
        prev_loop_vars = self._while_for_vars.copy()
        self._in_while_or_for = True
        self._while_for_vars.add(loop_var)
        self._push_declared_scope({loop_var, 'loopCtx', 'parentElement'})

        # Separate exec nodes from content nodes
        content_nodes = []
        exec_nodes = []
        for child in node.children:
            if isinstance(child, ExecNode):
                exec_nodes.append(child)
            else:
                content_nodes.append(child)

        # Generate children content
        children_items = []
        for child in content_nodes:
            code = self._gen_node(child, indent + '            ')
            if code is not None:
                children_items.append(code)

        # Restore context
        self._in_while_or_for = prev_in_loop
        self._while_for_vars = prev_loop_vars
        self._pop_declared_scope()
        self._loop_scopes.pop()
        self.id_gen.pop_scope()  # pop reactive

        # Generate exec statements
        exec_stmts = '\n'.join(
            f'{indent}            {self._normalize_exec_expression(e.js_expr)};'
            for e in exec_nodes
        )

        # Build while output
        count_param = f', {node.end_val}' if node.end_val else ''
        set_count = f'{indent}        loopCtx.setCount({node.end_val});' if node.end_val else ''

        lines = []
        lines.append(f'{indent}this.__while((loopCtx) => {{')
        if set_count:
            lines.append(set_count)
        lines.append(f'{indent}    let __whileOutput = [];')
        lines.append(f'{indent}    while ({node.condition_js}) {{')
        lines.append(f'{indent}        loopCtx.setCurrentTimes({loop_var});')

        if children_items:
            lines.append(f'{indent}        __whileOutput.push(')
            lines.append((',\n').join(children_items))
            lines.append(f'{indent}        );')

        if exec_stmts:
            lines.append(exec_stmts)

        lines.append(f'{indent}    }}')
        lines.append(f'{indent}    return __whileOutput;')
        lines.append(f'{indent}}}{count_param})')

        return '\n'.join(lines)

    # ──────────────────────────────────────────────────────────────────
    # For Block
    # ──────────────────────────────────────────────────────────────────

    def _gen_for(self, node, indent):
        """Generate this.__for(...) call."""
        rc_id = self.id_gen.push_reactive('for')
        id_str = self._format_id(rc_id)

        loop_id_expr = node.custom_key_js if node.custom_key_js else f'{node.var_name}'
        self._loop_scopes.append((rc_id, loop_id_expr))

        prev_in_loop = self._in_while_or_for
        prev_loop_vars = self._while_for_vars.copy()
        self._in_while_or_for = True
        self._while_for_vars.add(node.var_name)
        self._push_declared_scope({node.var_name, '__loop', 'parentElement'})

        content_nodes = [c for c in node.children if not isinstance(c, ExecNode)]
        exec_nodes = [c for c in node.children if isinstance(c, ExecNode)]

        children_items = []
        for child in content_nodes:
            code = self._gen_node(child, indent + '            ')
            if code is not None:
                children_items.append(code)

        self._in_while_or_for = prev_in_loop
        self._while_for_vars = prev_loop_vars
        self._pop_declared_scope()
        self._loop_scopes.pop()
        self.id_gen.pop_scope()

        exec_stmts = '\n'.join(
            f'{indent}            {self._normalize_exec_expression(e.js_expr)};'
            for e in exec_nodes
        )

        state_keys = sorted(node.state_vars)
        keys_str = str(state_keys).replace("'", '"')

        loop_param = '(__loop: any)' if self._is_ts else '(__loop)'

        lines = []
        if state_keys:
            lines.append(
                f'{indent}this.reactive({id_str}, "for", parentReactive, parentElement, '
                f'{keys_str}, {self._arrow_reactive()} {{'
            )
            lines.append(f'{indent}    return this.__for("increment", {node.start_js}, {node.end_js}, {loop_param} => {{')
        else:
            lines.append(f'{indent}this.__for("increment", {node.start_js}, {node.end_js}, {loop_param} => {{')

        lines.append(f'{indent}        let __forOutput = [];')
        lines.append(
            f'{indent}        for (let {node.var_name} = {node.start_js}; '
            f'{node.var_name} {node.operator} {node.end_js}; {node.var_name}++) {{'
        )
        lines.append(f'{indent}            __loop.setCurrentTimes({node.var_name});')

        if children_items:
            lines.append(f'{indent}            __forOutput.push(')
            lines.append((',\n').join(children_items))
            lines.append(f'{indent}            );')

        if exec_stmts:
            lines.append(exec_stmts)

        lines.append(f'{indent}        }}')
        lines.append(f'{indent}        return __forOutput;')
        lines.append(f'{indent}    }})')

        if state_keys:
            lines.append(f'{indent}}})')

        return '\n'.join(lines)

    # ──────────────────────────────────────────────────────────────────
    # Switch Block
    # ──────────────────────────────────────────────────────────────────

    def _gen_switch(self, node, indent):
        """Generate this.reactive("switch", ...) with switch statement."""
        rc_id = self.id_gen.push_reactive('switch')
        id_str = self._format_id(rc_id)

        state_keys = sorted(node.state_vars)
        keys_str = str(state_keys).replace("'", '"')

        lines = []
        lines.append(
            f'{indent}this.reactive({id_str}, "switch", parentReactive, parentElement, '
            f'{keys_str}, {self._arrow_reactive()} {{'
        )

        lines.append(f'{indent}    const reactiveContents = [];')
        lines.append(f'{indent}    switch ({node.expr_js}) {{')

        case_counter = 0
        for val_js, children in node.cases:
            case_counter += 1
            self.id_gen.push_case(case_counter)

            if val_js is not None:
                lines.append(f'{indent}        case {val_js}:')
            else:
                lines.append(f'{indent}        default:')

            children_items = []
            for child in children:
                code = self._gen_node(child, indent + '            ')
                if code is not None:
                    children_items.append(code)

            self.id_gen.pop_scope()  # pop case

            if children_items:
                lines.append(f'{indent}            reactiveContents.push(')
                lines.append((',\n').join(children_items))
                lines.append(f'{indent}            );')
            lines.append(f'{indent}            break;')

        lines.append(f'{indent}    }}')
        lines.append(f'{indent}    return reactiveContents;')
        lines.append(f'{indent}}})')

        self.id_gen.pop_scope()  # pop reactive

        return '\n'.join(lines)

    # ──────────────────────────────────────────────────────────────────
    # Block Section / Block Outlet
    # ──────────────────────────────────────────────────────────────────

    def _gen_block_section(self, node, indent):
        """Generate this.block('block-name', 'name', (parentElement) => [...])."""
        self.id_gen.push_block(node.name)

        children_code = self._gen_children_list(node.children, indent + '    ')

        self.id_gen.pop_scope()

        return (
            f'{indent}this.block(\'block-{node.name}\', \'{node.name}\', '
            f'{self._arrow_parent()} [\n'
            f'{children_code}\n'
            f'{indent}]);'
        )

    def _gen_block_outlet(self, node, indent):
        """Generate this.blockOutlet("id", "name", parentElement)."""
        outlet_id = self.id_gen.next_block_outlet()
        id_str = f'"{outlet_id}"'
        return f'{indent}this.blockOutlet({id_str}, "{node.name}", parentElement)'

    def _gen_yield(self, node, indent):
        """Generate this.yield("id", "name", defaultValue, parentElement)."""
        yield_id = self.id_gen.next_yield()
        id_str = f'"{yield_id}"'
        default_str = node.default_js if node.default_js else 'null'
        return f'{indent}this.yield({id_str}, "{node.name}", {default_str}, parentElement)'

    # ──────────────────────────────────────────────────────────────────
    # Section
    # ──────────────────────────────────────────────────────────────────

    def _gen_section(self, node, indent):
        """Generate this.section('name', config, () => value) for short sections."""
        state_keys = sorted(node.state_vars)
        stype = "'reactive'" if state_keys else "'static'"
        keys_str = str(state_keys).replace("'", '"') if state_keys else '[]'
        return (
            f"{indent}this.section('{node.name}', "
            f"{{ type: {stype}, contentType: '{node.content_type}', stateKeys: {keys_str} }}, "
            f"() => {node.value_js});"
        )

    def _gen_long_section(self, node, indent):
        """Generate this.section('name', config, (parentElement) => [...]) for long sections."""
        # Collect state vars from children
        state_keys = sorted(self._collect_state_vars(node.children))
        stype = "'reactive'" if state_keys else "'static'"
        keys_str = str(state_keys).replace("'", '"') if state_keys else '[]'
        children_code = self._gen_children_list(node.children, indent + '    ')
        return (
            f"{indent}this.section('{node.name}', "
            f"{{ type: {stype}, contentType: 'html', stateKeys: {keys_str} }}, "
            f"{self._arrow_parent()} [\n"
            f"{children_code}\n"
            f"{indent}]);"
        )

    def _collect_state_vars(self, nodes):
        """Collect state variables from a list of AST nodes."""
        svars = set()
        for node in nodes:
            if hasattr(node, 'state_vars'):
                svars |= node.state_vars
            if hasattr(node, 'children'):
                svars |= self._collect_state_vars(node.children)
            if isinstance(node, IfBlock):
                for _, _, children in node.branches:
                    svars |= self._collect_state_vars(children)
        return svars

    # ──────────────────────────────────────────────────────────────────
    # Include
    # ──────────────────────────────────────────────────────────────────

    def _gen_include(self, node, indent):
        """Generate this.include(id, path, parentElement, stateKeys, dataFactory) call."""
        comp_id = self.id_gen.next_component()
        id_str = f'"{comp_id}"'

        # Parse data_js (PHP array converted to JS object) into key-value pairs
        data_parts = []
        if node.data_js:
            data_parts.append(self._parse_include_data_to_pairs(node.data_js))

        if data_parts:
            data_inner = ', '.join(data_parts)
            return (
                f'{indent}this.include({id_str}, {node.path_js}, parentElement, [], '
                f'{self._arrow_parent()} ({{{data_inner}}})'
                f')'
            )
        return (
            f'{indent}this.include({id_str}, {node.path_js}, parentElement, [], '
            f'{self._arrow_parent()} ({{}}))'
        )

    def _gen_import_include(self, node, indent):
        """Generate this.include() with __ONE_CHILDREN_CONTENT__ for import components with children."""
        has_children = bool(node.children)
        
        if has_children:
            comp_id = self.id_gen.push_component()
        else:
            comp_id = self.id_gen.next_component()
        id_str = f'"{comp_id}"'

        # Build data factory parts from explicit data pairs
        data_parts = []
        for key, value_js in node.data_pairs:
            data_parts.append(f'"{key}": {value_js}')

        # Generate children content if present
        if has_children:
            arrow = self._arrow_parent()
            if self._has_exec_nodes(node.children):
                arr_name = '__execArr'
                self._push_declared_scope({'parentElement'})
                imp_code = self._gen_children_imperative(node.children, indent + '            ', arr_name)
                self._pop_declared_scope()
                children_slot = (
                    f'__ONE_CHILDREN_CONTENT__: {arrow} {{\n'
                    f'{indent}        const {arr_name} = [];\n'
                    f'{imp_code}\n'
                    f'{indent}        return {arr_name};\n'
                    f'{indent}    }}'
                )
            else:
                children_code = self._gen_children_list(node.children, indent + '        ')
                children_slot = (
                    f'__ONE_CHILDREN_CONTENT__: {arrow} [\n'
                    f'{children_code}\n'
                    f'{indent}    ]'
                )
            data_parts.append(children_slot)
            self.id_gen.pop_scope()

        if data_parts:
            data_inner = ',\n'.join(f'{indent}        {p}' for p in data_parts)
            return (
                f'{indent}this.include({id_str}, {node.path_js}, parentElement, [], '
                f'{self._arrow_parent()} ({{\n'
                f'{data_inner}\n'
                f'{indent}    }}))'
            )
        return (
            f'{indent}this.include({id_str}, {node.path_js}, parentElement, [], '
            f'{self._arrow_parent()} ({{}}))'
        )

    def _parse_include_data_to_pairs(self, data_js):
        """Parse a JS object string like {key: val, ...} into inline format."""
        # data_js comes from php_to_js and is already a JS expression
        # If it's wrapped in {} remove them and return inner content
        stripped = data_js.strip()
        if stripped.startswith('{') and stripped.endswith('}'):
            return stripped[1:-1].strip()
        return stripped

    # ──────────────────────────────────────────────────────────────────
    # Options object generation
    # ──────────────────────────────────────────────────────────────────

    def _gen_options(self, element):
        """Generate the options object for this.html() call."""
        parts = []

        # Classes
        classes_obj = self._gen_classes_obj(element)
        if classes_obj:
            parts.append(f'classes: {classes_obj}')

        # Attributes
        attrs_obj = self._gen_attrs_obj(element)
        if attrs_obj:
            parts.append(f'attrs: {attrs_obj}')

        # Events
        events_obj = self._gen_events_obj(element)
        if events_obj:
            parts.append(f'events: {events_obj}')

        if not parts:
            return '{}'
        return '{ ' + ', '.join(parts) + ' }'

    def _gen_classes_obj(self, element):
        """Generate classes configuration array: [{type, value, factory?, stateKeys?}]."""
        entries = []

        # Static classes
        for cls in element.static_classes:
            entries.append(f'{{ type: \'static\', value: "{cls}" }}')

        # Binding classes
        for cls, info in element.binding_classes.items():
            svars = sorted(info.get('state_vars', set()))
            keys_str = str(svars).replace("'", '"')
            entries.append(
                f'{{ type: \'binding\', value: "{cls}", '
                f'factory: () => {info["js"]}, stateKeys: {keys_str} }}'
            )

        if not entries:
            return None
        return '[' + ', '.join(entries) + ']'

    def _gen_attrs_obj(self, element):
        """Generate attrs configuration object."""
        entries = []

        # Static attrs
        for attr_name, attr_val in element.static_attrs.items():
            if attr_val is True:
                # Boolean attribute
                entries.append(f'"{attr_name}": {{ type: \'static\', value: true }}')
            else:
                # Escape quotes in static attribute values
                val_escaped = str(attr_val).replace('"', '\\"')
                entries.append(f'"{attr_name}": {{ type: \'static\', value: "{val_escaped}" }}')

        # Binding attrs
        for attr_name, info in element.binding_attrs.items():
            svars = sorted(info.get('state_vars', set()))
            keys_str = str(svars).replace("'", '"')
            js_val = info["js"]
            
            # Only use template literal backticks if js_val contains loop interpolation ${...}
            # Otherwise, use expression directly (not as string)
            if '${' in js_val:
                # Has loop variable interpolation - keep as template literal
                value_expr = f'`{js_val}`'
                factory_expr = f'() => `{js_val}`'
            else:
                # Pure expression - no backticks needed
                value_expr = js_val
                factory_expr = f'() => {js_val}'
            
            entries.append(
                f'"{attr_name}": ' + '{ type: \'binding\', value: ' + value_expr + ', '
                + 'factory: ' + factory_expr + ', stateKeys: ' + keys_str + ' }'
            )

        if not entries:
            return None
        return '{ ' + ', '.join(entries) + ' }'

    def _gen_events_obj(self, element):
        """Generate events configuration object."""
        import re
        entries = []

        for event_name, handlers in element.events.items():
            processed_handlers = []
            for h in handlers:
                h_processed = h.strip()

                # Keep object handler format as-is: {"handler":"...","params":[...]}
                if h_processed.startswith('{') and '"handler"' in h_processed:
                    processed_handlers.append(h_processed)
                    continue

                # Keep explicit arrow handlers as-is, only add TS annotation for event param.
                if '=>' in h_processed:
                    if self._is_ts:
                        h_processed = re.sub(r'^\(\s*event\s*\)\s*=>', '(event: any) =>', h_processed)
                    processed_handlers.append(h_processed)
                    continue

                # Fallback for legacy raw expressions.
                h_processed = re.sub(r'@event\b', 'event', h_processed, flags=re.IGNORECASE)
                event_arrow = '(event: any) =>' if self._is_ts else '(event) =>'
                processed_handlers.append(f'{event_arrow} {h_processed}')

            handlers_str = ', '.join(processed_handlers)
            entries.append(f'{event_name}: [{handlers_str}]')

        if not entries:
            return None
        return '{ ' + ', '.join(entries) + ' }'

    # ──────────────────────────────────────────────────────────────────
    # ID formatting
    # ──────────────────────────────────────────────────────────────────

    def _format_id(self, base_id):
        """Format a hydrate ID as JS template literal, injecting loop variables."""
        result = base_id

        for loop_prefix, js_loop_expr in reversed(self._loop_scopes):
            if loop_prefix in result and result != loop_prefix:
                idx = result.index(loop_prefix) + len(loop_prefix)
                if idx < len(result) and result[idx] == '-':
                    result = result[:idx] + f'-${{{js_loop_expr}}}' + result[idx:]

        if '${' in result:
            return f'`{result}`'
        return f'`{result}`'
