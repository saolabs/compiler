"""
Blade Template Compiler Package
"""

"""
Saola Python Compiler Package
Tái cấu trúc thành 3 module:
  - common/  : Shared modules (config, utils, php_converter, etc.)
  - sao2js/  : .sao → JavaScript/TypeScript compiler
  - sao2blade/: .sao → Blade PHP compiler (with reactive markers)
"""

import sys
import os

# Add current directory to Python path
current_dir = os.path.dirname(os.path.abspath(__file__))
if current_dir not in sys.path:
    sys.path.insert(0, current_dir)

# Backward compatible imports
from sao2js.main_compiler import BladeCompiler
from sao2blade.blade_compiler import BladeTemplateCompiler

__version__ = "1.0.0"
__author__ = "Blade Compiler Team"

__all__ = ['BladeCompiler']