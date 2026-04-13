"""End-to-end test: compile home.sao template through JS and Blade pipelines."""
import sys
import os

_python_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, _python_dir)
sys.path.insert(0, os.path.join(_python_dir, 'sao2js'))
sys.path.insert(0, os.path.join(_python_dir, 'sao2blade'))

HOME_ONE = """@import($__template__.'sessions.tasks')
@import($__template__.'sessions.projects' as projects)
@import([
'counter' => 'sessions.tasks.count',
'demo' => $__template__.'demo.fetch'
])
@import($__blade_custom_path__ as alert)

<blade>
    <tasks>
        <demo :users="$users" />
    </tasks>
    <tasks title="'Custom Task List'" />
    <projects></projects>
    <counter></counter>
    <demo></demo>
    <alert type="success" message="This is a custom alert component!"></alert>
</blade>
"""


def test_js_compilation():
    print("=" * 60)
    print("TEST: Full JS Compilation")
    print("=" * 60)
    
    from main_compiler import BladeCompiler
    compiler = BladeCompiler()
    result = compiler.compile_blade_to_js(HOME_ONE, 'home', 'Home')
    
    # Show relevant lines
    lines = result.split('\n')
    for i, line in enumerate(lines):
        stripped = line.strip()
        if any(kw in stripped for kw in ['__ONE_CHILDREN_CONTENT__', '__include', 'renderView']):
            print(f"  L{i}: {stripped[:150]}")
    
    print()
    assert '__ONE_CHILDREN_CONTENT__' in result, "Missing __ONE_CHILDREN_CONTENT__ in JS output"
    assert 'renderView(this.__include' in result, "Missing renderView.__include in JS output"
    assert '@import' not in result, "@import should be removed from JS output"
    assert '@importInclude' not in result, "@importInclude should be fully resolved"
    assert '@endImportInclude' not in result, "@endImportInclude should be fully resolved"
    
    # Count includes
    include_count = result.count('this.__include(')
    print(f"  this.__include() calls: {include_count}")
    assert include_count >= 6, f"Expected at least 6 __include calls, got {include_count}"
    
    print("  JS output length:", len(result), "chars")
    print("\n✓ JS compilation test passed!")


def test_blade_compilation():
    print("\n" + "=" * 60)
    print("TEST: Full Blade Compilation")
    print("=" * 60)
    
    from blade_compiler import BladeTemplateCompiler
    compiler = BladeTemplateCompiler()
    result = compiler.compile(HOME_ONE)
    
    print("\n  Blade output:")
    for line in result.split('\n'):
        if line.strip():
            print(f"    {line}")
    
    print()
    assert '@import' not in result, "@import should be removed from blade output"
    assert '@importInclude' not in result, "@importInclude should be fully resolved"
    assert 'startSection' in result, "Missing startSection for children capture"
    assert 'stopSection' in result, "Missing stopSection for children capture"
    assert 'yieldContent' in result, "Missing yieldContent for children capture"
    assert '__ONE_CHILDREN_CONTENT__' in result, "Missing __ONE_CHILDREN_CONTENT__"
    assert '$__ONE_COMPONENT_REGISTRY__' in result, "Missing component registry"
    
    # Count @include directives
    include_count = result.count('@include(')
    print(f"  @include() count: {include_count}")
    assert include_count >= 6, f"Expected at least 6 @include, got {include_count}"
    
    print("\n✓ Blade compilation test passed!")


if __name__ == '__main__':
    test_js_compilation()
    test_blade_compilation()
    
    print("\n" + "=" * 60)
    print("ALL E2E TESTS PASSED! ✓")
    print("=" * 60)
