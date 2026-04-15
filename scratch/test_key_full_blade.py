import sys
import os

# Add src to path
current_dir = os.path.dirname(os.path.abspath(__file__))
compiler_dir = os.path.join(os.path.dirname(current_dir), 'src')
if compiler_dir not in sys.path:
    sys.path.insert(0, compiler_dir)

from sao2blade.hydrate_processor import BladeHydrateProcessor

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

processor = BladeHydrateProcessor()
blade_output = processor.process(sao_code)

print("--- Generated Blade Code ---")
# Print lines with @hydrate or @startMarker
for line in blade_output.split('\n'):
    if '@hydrate' in line or '@startMarker' in line or '@foreach' in line:
        print(line.strip())
