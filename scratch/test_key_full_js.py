import sys
import os
import re

# Add src to path
current_dir = os.path.dirname(os.path.abspath(__file__))
compiler_dir = os.path.join(os.path.dirname(current_dir), 'src')
if compiler_dir not in sys.path:
    sys.path.insert(0, compiler_dir)

from sao2js.main_compiler import BladeCompiler

# Mock wrapper.js with correct placeholders
os.makedirs(os.path.join(compiler_dir, 'templates'), exist_ok=True)
with open(os.path.join(compiler_dir, 'templates', 'view.js'), 'w') as f:
    f.write('''
// View template mock
class [COMPONENT_NAME]View {
    $__setup__(__data__, systemData) {
        this.__ctrl__.setup({
[VIEW_SETUP_CONFIG_PLACEHOLDER]
        });
    }
}
''')

# SAO template with @key
sao_code = """
<div>
    <h1>Category List</h1>
    @foreach($categories as $category)
        <div class="category">
            @key($category.id)
            <h2>{{ $category.name }}</h2>
            <ul>
                @foreach($category.items as $item)
                    @key($item.id + 100)
                    <li>{{ $item.title }}</li>
                @endforeach
            </ul>
        </div>
    @endforeach
</div>
"""

compiler = BladeCompiler()
js_code = compiler.compile_blade_to_js(sao_code, 'CategoryView')

print("--- Generated JS Code ---")
# Print everything to see what's going on
print(js_code)
