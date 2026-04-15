import sys
import os

# Set up paths
_current_dir = os.getcwd()
sys.path.insert(0, os.path.join(_current_dir, 'src'))

from sao2blade.hydrate_processor import BladeHydrateProcessor

test_content = """
@foreach(categoryList as categoryItem)
    @key(categoryItem.id)
    <div class="category">
        <h3>{{ categoryItem.name }}</h3>
        @foreach(categoryItem.posts as postItem)
            @key(postItem.id)
            <div class="post">
                <span>{{ postItem.title }}</span>
            </div>
        @endforeach
    </div>
@endforeach
"""

processor = BladeHydrateProcessor()
result = processor.process(test_content)
print(result)
