#!/usr/bin/env python3
import json

CASES = [
    {"ops": [{"op": "foreach", "line": "@foreach($items as $item)"}, {"op": "append", "value": "x"}, {"op": "endforeach"}]},
    {"states": ["items"], "ops": [{"op": "foreach", "line": "@foreach($items as $key => $value)"}, {"op": "endforeach"}]},
    {"states": ["items"], "typescript": True, "ops": [{"op": "foreach", "line": "@foreach($items as $item)", "attribute": True}, {"op": "endforeach"}]},
    {"stack": [["for", 0]], "states": ["items"], "ops": [{"op": "foreach", "line": "@foreach($items as $item)"}, {"op": "endforeach"}]},
    {"ops": [{"op": "foreach", "line": "broken"}, {"op": "endforeach"}]},
    {"ops": [{"op": "for", "line": "@for($i = 0; $i < 10; $i++)"}, {"op": "append", "value": "x"}, {"op": "endfor"}]},
    {"states": ["max"], "ops": [{"op": "for", "line": "@for($i = 0; $i <= $max; $i++)"}, {"op": "endfor"}]},
    {"states": ["max"], "typescript": True, "ops": [{"op": "for", "line": "@for($i = 0; $i < $max; $i++)", "attribute": True}, {"op": "endfor"}]},
    {"stack": [["while", 0]], "states": ["max"], "ops": [{"op": "for", "line": "@for($i = 0; $i < $max; $i++)"}, {"op": "endfor"}]},
    {"stack": [["while", 0]], "ops": [{"op": "for", "line": "@for($i = 0; $i < 3; $i++)"}, {"op": "endfor"}]},
    {"ops": [{"op": "for", "line": "@for($i = 0; bad)"}, {"op": "endfor"}]},
    {"ops": [{"op": "while", "line": "@while($ready)"}, {"op": "append", "value": "x"}, {"op": "endwhile"}]},
    {"states": ["ready"], "ops": [{"op": "while", "line": "@while($ready && check($ready))"}, {"op": "endwhile"}]},
    {"states": ["ready"], "typescript": True, "ops": [{"op": "while", "line": "@while($ready)", "attribute": True}, {"op": "endwhile"}]},
    {"stack": [["for", 0]], "states": ["ready"], "ops": [{"op": "while", "line": "@while($ready)"}, {"op": "endwhile"}]},
    {"stack": [["for", 0]], "ops": [{"op": "while", "line": "@while(true)"}, {"op": "endwhile"}]},
    {"ops": [{"op": "while", "line": "broken"}, {"op": "endwhile"}]},
    {"states": ["items", "max"], "ops": [{"op": "foreach", "line": "@foreach($items as $item)"}, {"op": "for", "line": "@for($i = 0; $i < $max; $i++)"}, {"op": "endfor"}, {"op": "endforeach"}]},
]

for index, case in enumerate(CASES, 1):
    print(json.dumps({"name": f"loop-{index:02d}", **case}, ensure_ascii=False))
