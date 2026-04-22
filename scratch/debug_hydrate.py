
import sys
import os

# Add src to path
sys.path.append(os.path.join(os.getcwd(), 'src'))

from sao2blade.hydrate_processor import BladeHydrateProcessor

processor = BladeHydrateProcessor(state_variables={'userState'})

test_line = '<img src="{{asset(\'static/web/images/loho.png\')}}" alt="{{siteinfo(\'site_name\')}}" @class([\'site-logo\', \'has-login\' => $userState]) />'

test_line = '<img src="{{asset(\'static/web/images/loho.png\')}}" alt="{{siteinfo(\'site_name\')}}" @class([\'site-logo\', \'has-login\' => $userState]) />'

import re
# Proposed improved regex
open_m = re.match(
    r'<([a-zA-Z][\w-]*)((?:\s+(?:=>|->|[^>\'"]|\'[^\']*\'|"[^"]*")*?)?)\s*(/?)>',
    test_line, re.DOTALL
)

if open_m:
    print(f"Tag: {open_m.group(1)}")
    print(f"Attrs: [{open_m.group(2)}]")
    print(f"Slash: {open_m.group(3)}")
else:
    print("No match for open_tag regex")

print(f"\nInput line: {test_line}")
processed = processor.process(test_line)
print(f"Output line: {processed}")
