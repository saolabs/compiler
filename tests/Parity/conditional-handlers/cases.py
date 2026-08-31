#!/usr/bin/env python3
import json


CASES = [
    {"ops": [{"op": "if", "line": "@if($ready)"}, {"op": "append", "value": "yes"}, {"op": "endif"}]},
    {"states": ["ready"], "ops": [{"op": "if", "line": "@if($ready)"}, {"op": "append", "value": "yes"}, {"op": "endif"}]},
    {"states": ["ready"], "typescript": True, "ops": [{"op": "if", "line": "@if($ready)"}, {"op": "endif"}]},
    {"states": ["ready"], "ops": [{"op": "if", "line": "@if($ready)", "attribute": True}, {"op": "else"}, {"op": "endif"}]},
    {"states": ["ready", "other"], "ops": [{"op": "if", "line": "@if($ready)"}, {"op": "elseif", "line": "@elseif($other)"}, {"op": "else"}, {"op": "endif"}]},
    {"stack": [["for", 0]], "states": ["ready"], "ops": [{"op": "if", "line": "@if($ready)"}, {"op": "elseif", "line": "@elseif(!$ready)"}, {"op": "else"}, {"op": "break"}, {"op": "endif"}]},
    {"stack": [["foreach", 0]], "states": ["ready"], "ops": [{"op": "if", "line": "@if($ready)"}, {"op": "endif"}]},
    {"ops": [{"op": "if", "line": "broken"}, {"op": "elseif", "line": "broken"}, {"op": "endif"}]},
    {"states": ["status"], "ops": [{"op": "switch", "line": "@switch($status)"}, {"op": "case", "line": "@case('ok')"}, {"op": "append", "value": "OK"}, {"op": "break"}, {"op": "default"}, {"op": "append", "value": "NO"}, {"op": "break"}, {"op": "endswitch"}]},
    {"ops": [{"op": "switch", "line": "@switch(1)"}, {"op": "case", "line": "@case(fn(1, 2))"}, {"op": "break"}, {"op": "endswitch"}]},
    {"stack": [["while", 0]], "states": ["status"], "ops": [{"op": "switch", "line": "@switch($status)"}, {"op": "default"}, {"op": "break"}, {"op": "endswitch"}]},
    {"states": ["status"], "typescript": True, "ops": [{"op": "switch", "line": "@switch($status)", "attribute": True}, {"op": "endswitch"}]},
    {"ops": [{"op": "case", "line": "broken"}, {"op": "break"}, {"op": "endswitch"}]},
]

for index, case in enumerate(CASES, 1):
    print(json.dumps({"name": f"conditional-{index:02d}", **case}, ensure_ascii=False))
