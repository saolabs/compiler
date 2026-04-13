#!/usr/bin/env python3
"""
Test blade view template output with $__ONE_COMPONENT_REGISTRY__
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from sao2blade.blade_compiler import BladeTemplateCompiler


def test_blade_output_with_imports():
    """Test that blade output uses view.blade.php template with component registry"""
    compiler = BladeTemplateCompiler()
    
    one_content = """
@import('components.header')
@import('components.footer', 'FooterBar')

@useState($count, 0)

<blade>
<div>
    <Header title="Hello" />
    <p>Content</p>
    <FooterBar />
</div>
</blade>
"""
    result = compiler.compile(one_content)
    print("=== WITH IMPORTS ===")
    print(result)
    print()
    
    # Verify registry is populated
    assert "'Header' => 'components.header'" in result, "Missing Header in registry"
    assert "'FooterBar' => 'components.footer'" in result, "Missing FooterBar in registry"
    assert "@exec($__ONE_COMPONENT_REGISTRY__" in result, "Missing @exec registry directive"
    print("✓ Component registry populated correctly")


def test_blade_output_without_imports():
    """Test blade output without imports — registry should be empty"""
    compiler = BladeTemplateCompiler()
    
    one_content = """
@useState($count, 0)

<blade>
<div>
    <p>Hello {{ $count }}</p>
</div>
</blade>
"""
    result = compiler.compile(one_content)
    print("=== WITHOUT IMPORTS ===")
    print(result)
    print()
    
    # Registry should be empty
    assert "$__ONE_COMPONENT_REGISTRY__ = [])" in result, "Registry should be empty []"
    assert "@exec($__ONE_COMPONENT_REGISTRY__" in result, "Missing @exec registry directive"
    print("✓ Empty registry when no imports")


def test_blade_output_no_state():
    """Test blade output without state variables (no reactive wrapping)"""
    compiler = BladeTemplateCompiler()
    
    one_content = """
@import('components.nav')

<blade>
<nav>
    <Nav />
</nav>
</blade>
"""
    result = compiler.compile(one_content)
    print("=== NO STATE ===")
    print(result)
    print()
    
    assert "'Nav' => 'components.nav'" in result, "Missing Nav in registry"
    assert "@exec($__ONE_COMPONENT_REGISTRY__" in result, "Missing @exec"
    print("✓ No-state blade output uses template correctly")


def test_blade_output_with_children():
    """Test blade output with paired tags (children/slot content)"""
    compiler = BladeTemplateCompiler()
    
    one_content = """
@import('components.card')

<blade>
<div>
    <Card title="My Card">
        <p>Card content</p>
    </Card>
</div>
</blade>
"""
    result = compiler.compile(one_content)
    print("=== WITH CHILDREN ===")
    print(result)
    print()
    
    assert "'Card' => 'components.card'" in result, "Missing Card in registry"
    assert "__ONE_CHILDREN_CONTENT__" in result, "Missing children content"
    assert "ob_start" in result, "Missing ob_start for slot"
    print("✓ Children/slot content with template works")


def test_blade_template_loaded():
    """Test that view.blade.php template is loaded"""
    compiler = BladeTemplateCompiler()
    assert compiler.view_template is not None, "Template not loaded"
    assert '[ONE_COMPONENT_REGISTRY]' in compiler.view_template, "Missing registry placeholder"
    assert '[BLADE_TEMPLATE_CONTENT]' in compiler.view_template, "Missing template placeholder"
    print("✓ view.blade.php template loaded successfully")


def test_build_component_registry():
    """Test _build_component_registry method directly"""
    compiler = BladeTemplateCompiler()
    
    # Empty
    assert compiler._build_component_registry(None) == '[]'
    assert compiler._build_component_registry({}) == '[]'
    
    # Single
    result = compiler._build_component_registry({'Header': 'components.header'})
    assert result == "['Header' => 'components.header']"
    
    # Multiple
    result = compiler._build_component_registry({
        'Header': 'components.header',
        'Footer': 'components.footer',
        'Nav': 'layouts.nav'
    })
    assert "'Header' => 'components.header'" in result
    assert "'Footer' => 'components.footer'" in result
    assert "'Nav' => 'layouts.nav'" in result
    print("✓ _build_component_registry works correctly")


if __name__ == '__main__':
    test_blade_template_loaded()
    test_build_component_registry()
    test_blade_output_without_imports()
    test_blade_output_no_state()
    test_blade_output_with_imports()
    test_blade_output_with_children()
    print("\n✅ All blade view template tests passed!")
