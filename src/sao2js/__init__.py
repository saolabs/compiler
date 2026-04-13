"""
Saola Compiler - One2JS
Biên dịch .sao files sang JavaScript/TypeScript View modules
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

from main_compiler import BladeCompiler
