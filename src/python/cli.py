"""
Command line interface cho Saola Compiler (backward compatible dispatcher)
Chuyển tiếp sang sao2js hoặc sao2blade tùy theo tham số
"""

import sys
import os

# Add current directory to Python path
current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.insert(0, current_dir)

def main():
    """
    Backward compatible CLI - mặc định chạy sao2js compiler.
    Sử dụng:
        python cli.py <input> <output.js> [function_name] [view_path] [factory_name]  → sao2js
        python cli.py --blade <input> <output.blade.php>                               → sao2blade
    """
    if len(sys.argv) >= 2 and sys.argv[1] == '--blade':
        # Dispatch to sao2blade
        sys.argv = [sys.argv[0]] + sys.argv[2:]  # Remove --blade flag
        from sao2blade.cli import main as blade_main
        blade_main()
    else:
        # Default: dispatch to sao2js (backward compatible)
        from sao2js.cli import main as js_main
        js_main()

if __name__ == "__main__":
    main()
