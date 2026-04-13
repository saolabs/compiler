"""
Hydrate ID Generator - Tạo ID đồng nhất cho hydration giữa Blade (SSR) và JS (CSR)

Cơ chế: Mỗi phần tử HTML/reactive/output được gán một ID duy nhất dựa trên vị trí
trong cây DOM. ID này giống nhau ở cả blade output và JS output, cho phép JS hydrate
chính xác phần tử HTML tương ứng từ server-side rendered HTML.

Quy tắc đặt ID:
- Mỗi cấp (scope) duy trì bộ đếm riêng cho con trực tiếp
- HTML tag: "{parentId}-{tagName}-{orderInParent}" e.g. "div-1", "div-1-h1-1"
- Block context: "block-{blockName}-{childId}" e.g. "block-content-div-1"
- Reactive (if/foreach/for/while/switch): "rc-{type}-{order}" e.g. "rc-if-1"
- Reactive case: "rc-if-1-case_{N}-" prefix cho children trong @if/@else branch
- Loop iteration: "foreach-1-{loopIndex}-" hoặc "while-1-{i}-" 
- Component: "component-{order}" prefix cho @include components
- Output: "{parentId}-output-{order}" cho {{ ... }} text interpolation
- Block outlet: "{parentId}-block-outlet" cho @useBlock/@blockOutlet
"""

import re


class HydrateIdScope:
    """
    Một scope trong cây ID. Mỗi scope theo dõi bộ đếm cho các loại con khác nhau.
    """
    
    def __init__(self, prefix='', loop_var=None, loop_var_blade=None):
        """
        Args:
            prefix: ID prefix cho scope này (e.g. "block-content", "div-1")
            loop_var: Biến vòng lặp JS (e.g. "__loopIndex + 1", "i + 1")
            loop_var_blade: Biến vòng lặp Blade (e.g. "$loop->index", "$i")
        """
        self.prefix = prefix
        self.loop_var = loop_var  # JS loop variable expression
        self.loop_var_blade = loop_var_blade  # Blade/PHP loop variable expression
        self._element_counter = 0  # Counter for HTML elements
        self._reactive_counter = 0  # Counter for reactive blocks (if/foreach/while/etc)
        self._output_counter = 0  # Counter for {{ }} output expressions
        self._component_counter = 0  # Counter for component includes
        self._block_outlet_counter = 0  # Counter for block outlets
        self._yield_counter = 0  # Counter for @yield directives
    
    def next_element_id(self, tag_name):
        """Generate ID cho HTML element tiếp theo. Returns: "tag-N" """
        self._element_counter += 1
        segment = f"{tag_name}-{self._element_counter}"
        return self._with_prefix(segment)
    
    def next_reactive_id(self, reactive_type):
        """Generate ID cho reactive block tiếp theo.
        Conditionals (if/switch): "rc-type-N"
        Loops (foreach/for/while): "type-N" (no rc- prefix)
        """
        self._reactive_counter += 1
        if reactive_type in ('if', 'switch'):
            segment = f"rc-{reactive_type}-{self._reactive_counter}"
        else:
            segment = f"{reactive_type}-{self._reactive_counter}"
        return self._with_prefix(segment)
    
    def next_output_id(self):
        """Generate ID cho output expression tiếp theo. Returns: "output-N" """
        self._output_counter += 1
        segment = f"output-{self._output_counter}"
        return self._with_prefix(segment)
    
    def next_component_id(self):
        """Generate ID cho component include tiếp theo. Returns: "component-N" """
        self._component_counter += 1
        segment = f"component-{self._component_counter}"
        return self._with_prefix(segment)
    
    def next_block_outlet_id(self):
        """Generate ID cho block outlet. Returns: "block-outlet" """
        segment = "block-outlet"
        return self._with_prefix(segment)
    
    def next_yield_id(self):
        """Generate ID cho @yield directive. Returns: "yield-N" """
        self._yield_counter += 1
        segment = f"yield-{self._yield_counter}"
        return self._with_prefix(segment)
    
    def _with_prefix(self, segment):
        """Combine prefix with segment. If loop_var exists, include loop index."""
        if self.prefix:
            return f"{self.prefix}-{segment}"
        return segment


class HydrateIdGenerator:
    """
    Generator tạo hydrate IDs đồng nhất cho cả Blade và JS output.
    
    Usage:
        gen = HydrateIdGenerator()
        
        # Entering a block
        gen.push_block('content')
        
        # HTML elements
        div_id = gen.next_element('div')    # → "block-content-div-1"
        h1_id = gen.push_element('h1')      # → "block-content-div-1-h1-1" (also enters scope)
        gen.pop_scope()                      # back to div scope
        
        # Reactive blocks
        rc_id = gen.push_reactive('if')     # → "block-content-div-1-rc-if-1"
        gen.push_case(1)                     # → enters case_1 scope
        gen.pop_scope()                      # back to reactive scope
        gen.pop_scope()                      # back to div scope
    """
    
    def __init__(self):
        self._scope_stack = [HydrateIdScope()]  # Root scope
    
    @property
    def current_scope(self):
        return self._scope_stack[-1]
    
    def reset(self):
        """Reset generator cho view mới."""
        self._scope_stack = [HydrateIdScope()]
    
    # ── Block operations ──────────────────────────────────────────────
    
    def push_block(self, block_name):
        """Enter a @block('name') scope. Returns block prefix."""
        prefix = f"block-{block_name}"
        self._scope_stack.append(HydrateIdScope(prefix=prefix))
        return prefix
    
    # ── HTML element operations ───────────────────────────────────────
    
    def next_element(self, tag_name):
        """Generate ID cho HTML element nhưng KHÔNG enter scope con.
        Dùng cho void/self-closing elements hoặc leaf elements."""
        return self.current_scope.next_element_id(tag_name)
    
    def push_element(self, tag_name):
        """Generate ID cho HTML element VÀ enter scope con.
        Dùng cho elements có children."""
        element_id = self.current_scope.next_element_id(tag_name)
        self._scope_stack.append(HydrateIdScope(prefix=element_id))
        return element_id
    
    # ── Reactive operations ───────────────────────────────────────────
    
    def push_reactive(self, reactive_type):
        """Enter reactive block scope (if/foreach/for/while/switch).
        Returns reactive ID."""
        reactive_id = self.current_scope.next_reactive_id(reactive_type)
        self._scope_stack.append(HydrateIdScope(prefix=reactive_id))
        return reactive_id
    
    def push_case(self, case_number):
        """Enter a case branch scope within an if/switch reactive.
        case_number: 1 for first branch (if/case), 2 for else/second case, etc."""
        prefix = f"{self.current_scope.prefix}-case_{case_number}"
        self._scope_stack.append(HydrateIdScope(prefix=prefix))
        return prefix
    
    def push_loop_iteration(self, loop_var_js, loop_var_blade=None):
        """Enter a loop iteration scope.
        Args:
            loop_var_js: JS expression for loop index (e.g. "__loopIndex + 1", "i + 1")
            loop_var_blade: PHP expression for loop index (e.g. "$loop->index", "$i")
        """
        prefix_base = self.current_scope.prefix
        scope = HydrateIdScope(
            prefix=prefix_base,
            loop_var=loop_var_js,
            loop_var_blade=loop_var_blade
        )
        self._scope_stack.append(scope)
        return prefix_base
    
    # ── Output operations ─────────────────────────────────────────────
    
    def next_output(self):
        """Generate ID cho {{ }} output expression."""
        return self.current_scope.next_output_id()
    
    # ── Component operations ──────────────────────────────────────────
    
    def next_component(self):
        """Generate ID cho component @include (không enter scope)."""
        return self.current_scope.next_component_id()
    
    def push_component(self):
        """Generate ID cho component @include VÀ enter scope con.
        Dùng cho components có children (__ONE_CHILDREN_CONTENT__)."""
        component_id = self.current_scope.next_component_id()
        self._scope_stack.append(HydrateIdScope(prefix=component_id))
        return component_id
    
    # ── Block outlet operations ───────────────────────────────────────
    
    def next_block_outlet(self):
        """Generate ID cho @useBlock/@blockOutlet."""
        return self.current_scope.next_block_outlet_id()
    
    # ── Yield operations ──────────────────────────────────────────────
    
    def next_yield(self):
        """Generate ID cho @yield directive."""
        return self.current_scope.next_yield_id()
    
    # ── Scope management ──────────────────────────────────────────────
    
    def pop_scope(self):
        """Exit current scope, return to parent."""
        if len(self._scope_stack) > 1:
            return self._scope_stack.pop()
        return None
    
    def get_depth(self):
        """Get current scope depth."""
        return len(self._scope_stack)
    
    # ── ID formatting helpers ─────────────────────────────────────────
    
    def format_js_id(self, base_id):
        """Format ID cho JS output, wrapping dynamic parts in template literals.
        
        Args:
            base_id: The base ID string
            
        Returns: JS template literal string (with backticks if dynamic)
        """
        # Walk scope stack to find loop variables
        dynamic_parts = []
        for scope in self._scope_stack:
            if scope.loop_var:
                dynamic_parts.append(scope.loop_var)
        
        if not dynamic_parts:
            return f"`{base_id}`"
        
        return f"`{base_id}`"  # Base IDs already include static parts
    
    def format_blade_hydrate(self, base_id):
        """Format @hydrate directive cho Blade output.
        
        Returns: @hydrate('id') hoặc @hydrate("id-{$dynamic}")
        """
        # Check if ID contains dynamic (loop) parts
        has_dynamic = any(scope.loop_var_blade for scope in self._scope_stack)
        
        if not has_dynamic:
            return f"@hydrate('{base_id}')"
        
        return f'@hydrate("{base_id}")'


def make_loop_id_js(base_prefix, loop_var_expr):
    """Create a JS template literal ID component for loop iterations.
    
    Args:
        base_prefix: Static prefix (e.g. "rc-if-1-case_2-foreach-1")
        loop_var_expr: JS expression for index (e.g. "__loopIndex + 1")
    
    Returns: Template literal fragment like "${__loopIndex + 1}"
    """
    return f"${{{loop_var_expr}}}"


def make_loop_id_blade(base_prefix, loop_var_expr):
    """Create a Blade template ID component for loop iterations.
    
    Args:
        base_prefix: Static prefix
        loop_var_expr: PHP expression for index (e.g. "$loop->index", "$i")
    
    Returns: PHP interpolation fragment like "{$loop->index}"
    """
    return f"{{{loop_var_expr}}}"
