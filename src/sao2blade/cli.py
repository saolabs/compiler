"""
Command line interface cho One2Blade Compiler
Biên dịch .sao files sang Blade PHP templates với reactive directives
"""

import sys
import os

# Add parent directory (python/) to path for common package imports
parent_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if parent_dir not in sys.path:
    sys.path.insert(0, parent_dir)

# Add current directory to path for local imports
current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.insert(0, current_dir)

from blade_compiler import BladeTemplateCompiler


def main():
    if len(sys.argv) < 3:
        print("Sử dụng: python cli.py <input.sao> <output.blade.php>")
        print("  input.sao: File .sao source hoặc blade content")
        print("  output.blade.php: File blade.php đầu ra")
        sys.exit(1)
    
    input_file = sys.argv[1]
    output_file = sys.argv[2]
    
    try:
        with open(input_file, 'r', encoding='utf-8') as f:
            content = f.read()
        
        compiler = BladeTemplateCompiler()
        blade_output = compiler.compile(content)
        
        with open(output_file, 'w', encoding='utf-8') as f:
            f.write(blade_output)
        
        print(f"Đã compile blade thành công từ {input_file} sang {output_file}")
    except Exception as e:
        print(f"Lỗi: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)


if __name__ == "__main__":
    main()
