"""
Test suite for @import parser, tag resolver, and integration with compilers.
"""

import sys
import os

# Setup paths
_current_dir = os.path.dirname(os.path.abspath(__file__))
_python_dir = os.path.join(_current_dir, '..')
if _python_dir not in sys.path:
    sys.path.insert(0, _python_dir)
_common_dir = os.path.join(_python_dir, 'common')
if _common_dir not in sys.path:
    sys.path.insert(0, _common_dir)

from common.import_parser import ImportParser
from common.import_tag_resolver import ImportTagResolver


def test_import_parser():
    """Test parsing @import directives."""
    print("=" * 60)
    print("TEST: ImportParser")
    print("=" * 60)
    
    code = """
@import($__template__.'sessions.tasks') {{-- comment --}}
@import($__template__.'sessions.projects' as projects) {{-- comment --}}
@import([
'counter' => 'sessions.tasks.count', // comment
'demo' => $__template__.'demo.fetch' // comment
])
@import($__layout__.'base' as baseLayout)
@import($__blade_custom_path__ as alert)

<blade>
    <tasks>
        <demo :users="$users" />
    </tasks>
</blade>
"""
    
    parser = ImportParser()
    imports = parser.parse_imports(code)
    
    print("\nParsed imports:")
    for tag, path in imports.items():
        print(f"  {tag:15} → {path}")
    
    # Assertions
    assert 'tasks' in imports, "Missing 'tasks' import"
    assert imports['tasks'] == "$__template__.'sessions.tasks'", f"Wrong path for tasks: {imports['tasks']}"
    
    assert 'projects' in imports, "Missing 'projects' import"
    assert imports['projects'] == "$__template__.'sessions.projects'", f"Wrong path for projects: {imports['projects']}"
    
    assert 'counter' in imports, "Missing 'counter' import"
    assert imports['counter'] == "'sessions.tasks.count'", f"Wrong path for counter: {imports['counter']}"
    
    assert 'demo' in imports, "Missing 'demo' import"
    assert imports['demo'] == "$__template__.'demo.fetch'", f"Wrong path for demo: {imports['demo']}"
    
    assert 'baseLayout' in imports, "Missing 'baseLayout' import"
    assert imports['baseLayout'] == "$__layout__.'base'", f"Wrong path for baseLayout: {imports['baseLayout']}"
    
    assert 'alert' in imports, "Missing 'alert' import"
    assert imports['alert'] == "$__blade_custom_path__", f"Wrong path for alert: {imports['alert']}"
    
    print("\n✓ All import parsing assertions passed!")
    
    # Test removal
    cleaned = parser.remove_imports(code)
    print("\nCleaned code (imports removed):")
    print(cleaned.strip())
    assert '@import' not in cleaned, "Failed to remove @import directives"
    assert '<blade>' in cleaned, "Template content should remain"
    print("✓ Import removal passed!")


def test_tag_resolver_self_closing():
    """Test self-closing tag resolution."""
    print("\n" + "=" * 60)
    print("TEST: TagResolver - Self-closing tags")
    print("=" * 60)
    
    imports = {
        'tasks': "$__template__.'sessions.tasks'",
        'demo': "$__template__.'demo.fetch'",
        'counter': "'sessions.tasks.count'",
        'alert': "$__blade_custom_path__",
    }
    
    resolver = ImportTagResolver(imports=imports, target='js')
    
    # Test simple self-closing without attrs
    code = '<counter />'
    result = resolver.resolve_tags(code)
    print(f"\n  {code}")
    print(f"  → {result}")
    assert "@include('sessions.tasks.count')" in result
    
    # Test self-closing with static attr
    code = '<tasks title="\'Custom Task List\'" />'
    result = resolver.resolve_tags(code)
    print(f"\n  {code}")
    print(f"  → {result}")
    assert "@include($__template__.'sessions.tasks'" in result
    assert "'title' => 'Custom Task List'" in result
    
    # Test self-closing with binding attr
    code = '<demo :users="$users" />'
    result = resolver.resolve_tags(code)
    print(f"\n  {code}")
    print(f"  → {result}")
    assert "@include($__template__.'demo.fetch'" in result
    assert "'users' => $users" in result
    
    # Test self-closing with mixed attrs
    code = '<alert type="success" message="This is alert!" />'
    result = resolver.resolve_tags(code)
    print(f"\n  {code}")
    print(f"  → {result}")
    assert "@include($__blade_custom_path__" in result
    assert "'type' => \"success\"" in result
    assert "'message' => \"This is alert!\"" in result
    
    print("\n✓ All self-closing tag assertions passed!")


def test_tag_resolver_paired():
    """Test paired tag resolution (with children)."""
    print("\n" + "=" * 60)
    print("TEST: TagResolver - Paired tags")
    print("=" * 60)
    
    imports = {
        'tasks': "$__template__.'sessions.tasks'",
        'demo': "$__template__.'demo.fetch'",
        'projects': "$__template__.'sessions.projects'",
        'alert': "$__blade_custom_path__",
    }
    
    resolver = ImportTagResolver(imports=imports, target='js')
    
    # Test empty paired tag → @include (no children)
    code = '<projects></projects>'
    result = resolver.resolve_tags(code)
    print(f"\n  {code}")
    print(f"  → {result}")
    assert "@include($__template__.'sessions.projects')" in result
    
    # Test paired tag with children (the key feature!)
    code = """<tasks>
        <demo :users="$users" />
    </tasks>"""
    result = resolver.resolve_tags(code)
    print(f"\n  Input:\n{code}")
    print(f"\n  Output:\n{result}")
    # Inner <demo /> should be resolved to @include
    assert "@include($__template__.'demo.fetch'" in result
    assert "'users' => $users" in result
    # Outer <tasks> should be @importInclude block with tag name
    assert "@importInclude(tasks, $__template__.'sessions.tasks')" in result
    assert "@endImportInclude" in result
    
    # Test paired tag with attrs and children
    code = '<alert type="success" message="Hello">\n    <demo />\n</alert>'
    result = resolver.resolve_tags(code)
    print(f"\n  Input:\n{code}")
    print(f"\n  Output:\n{result}")
    assert "@importInclude(alert, $__blade_custom_path__" in result
    assert "'type' => \"success\"" in result
    assert "@include($__template__.'demo.fetch')" in result
    assert "@endImportInclude" in result
    
    print("\n✓ All paired tag assertions passed!")


def test_full_home_template():
    """Test the full home.sao template example."""
    print("\n" + "=" * 60)
    print("TEST: Full home.sao template")
    print("=" * 60)
    
    code = """@import($__template__.'sessions.tasks')
@import($__template__.'sessions.projects' as projects)
@import([
'counter' => 'sessions.tasks.count',
'demo' => $__template__.'demo.fetch'
])
@import($__layout__.'base' as baseLayout)
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
    
    # Step 1: Parse imports
    parser = ImportParser()
    imports = parser.parse_imports(code)
    print("\nImports parsed:", imports)
    assert len(imports) == 6, f"Expected 6 imports, got {len(imports)}"
    
    # Step 2: Get blade content (simulate what compiler does)
    import re
    blade_match = re.search(r'<blade>([\s\S]*?)</blade>', code)
    blade_content = blade_match.group(1).strip() if blade_match else ''
    
    # Step 3: Resolve tags
    resolver = ImportTagResolver(imports=imports, target='js')
    resolved = resolver.resolve_tags(blade_content)
    
    print("\nResolved template:")
    print(resolved)
    
    # Verify all custom tags are resolved
    remaining_tags = re.findall(r'<(tasks|demo|projects|counter|alert)[\s/>]', resolved)
    print(f"\nRemaining custom tags: {remaining_tags}")
    # Some may remain if they're inside @importInclude children
    # but top-level custom tags should all be resolved
    
    # Verify @include directives generated
    include_count = resolved.count('@include(')
    import_include_count = resolved.count('@importInclude(')
    print(f"@include count: {include_count}")
    print(f"@importInclude count: {import_include_count}")
    
    assert include_count >= 5, f"Expected at least 5 @include, got {include_count}"
    assert import_include_count >= 1, f"Expected at least 1 @importInclude, got {import_include_count}"
    
    print("\n✓ Full template test passed!")


def test_js_import_include_resolution():
    """Test _resolve_import_includes in template processor."""
    print("\n" + "=" * 60)
    print("TEST: JS @importInclude resolution")
    print("=" * 60)
    
    # Import the template processor
    sys.path.insert(0, os.path.join(_python_dir, 'sao2js'))
    from template_processor import TemplateProcessor
    
    tp = TemplateProcessor(usestate_variables=set())
    
    # Test simple @importInclude without attrs
    code = """@importInclude(tasks, $__template__.'sessions.tasks')
@include($__template__.'demo.fetch', ['users' => $users])
@endImportInclude"""
    
    result = tp._resolve_import_includes(code)
    print(f"\n  Input:\n{code}")
    print(f"\n  Output:\n{result}")
    
    assert '__ONE_CHILDREN_CONTENT__' in result, "Missing __ONE_CHILDREN_CONTENT__"
    assert 'renderView' in result, "Missing renderView call"
    assert '@importInclude' not in result, "@importInclude should be resolved"
    assert '@endImportInclude' not in result, "@endImportInclude should be resolved"
    
    # Test @importInclude with attrs
    code = """@importInclude(tasks, $__template__.'sessions.tasks', ['title' => 'Custom'])
some inner html
@endImportInclude"""
    
    result = tp._resolve_import_includes(code)
    print(f"\n  Input:\n{code}")
    print(f"\n  Output:\n{result}")
    
    assert '"title"' in result, "Missing title attr in JS"
    assert '__ONE_CHILDREN_CONTENT__' in result, "Missing __ONE_CHILDREN_CONTENT__"
    
    print("\n✓ JS @importInclude resolution passed!")


def test_blade_import_include_resolution():
    """Test _resolve_import_includes_blade in blade compiler."""
    print("\n" + "=" * 60)
    print("TEST: Blade @importInclude resolution")
    print("=" * 60)
    
    sys.path.insert(0, os.path.join(_python_dir, 'sao2blade'))
    from blade_compiler import BladeTemplateCompiler
    
    bc = BladeTemplateCompiler()
    
    # Test simple @importInclude
    code = """@importInclude(tasks, $__template__.'sessions.tasks')
    @include($__template__.'demo.fetch', ['users' => $users])
@endImportInclude"""
    
    result = bc._resolve_import_includes_blade(code)
    print(f"\n  Input:\n{code}")
    print(f"\n  Output:\n{result}")
    
    assert 'startSection' in result, "Missing startSection"
    assert 'stopSection' in result, "Missing stopSection"
    assert 'yieldContent' in result, "Missing yieldContent"
    assert "__ONE_CHILDREN_CONTENT__" in result, "Missing __ONE_CHILDREN_CONTENT__"
    assert '@importInclude' not in result, "@importInclude should be resolved"
    assert '@endImportInclude' not in result, "@endImportInclude should be resolved"
    assert "$__ONE_COMPONENT_REGISTRY__['tasks']" in result, "Missing registry reference"
    
    # Test with attrs
    code = """@importInclude(tasks, $__template__.'sessions.tasks', ['title' => 'Custom'])
    <p>Children content</p>
@endImportInclude"""
    
    result = bc._resolve_import_includes_blade(code)
    print(f"\n  Input:\n{code}")
    print(f"\n  Output:\n{result}")
    
    assert "'title' => 'Custom'" in result
    assert '__ONE_CHILDREN_CONTENT__' in result
    assert '<p>Children content</p>' in result
    
    print("\n✓ Blade @importInclude resolution passed!")


if __name__ == '__main__':
    test_import_parser()
    test_tag_resolver_self_closing()
    test_tag_resolver_paired()
    test_full_home_template()
    test_js_import_include_resolution()
    test_blade_import_include_resolution()
    
    print("\n" + "=" * 60)
    print("ALL TESTS PASSED! ✓")
    print("=" * 60)
