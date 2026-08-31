import json, re, subprocess, sys, glob, os
PC = "/Users/doanln/Desktop/2026/Projects/saolabs/saola-ecosystem/php-compiler"

def compile_one(path):
    r = subprocess.run([f"{PC}/bin/saoc","compile",os.path.abspath(path),"--view-path=t.v","--fn=V","--factory=VF","--json"],
                       capture_output=True, text=True, cwd=PC)
    if r.returncode != 0:
        return None, (r.stderr or "").strip()[:160]
    try: return json.loads(r.stdout), None
    except Exception as e: return None, f"JSON lỗi: {e}"

# tên thẻ được cấp hydrate id
BLADE_TAG = re.compile(r"<([a-zA-Z][\w-]*)[^>]*?@class\(\[\$__VIEW_ID__")
JS_TAG    = re.compile(r"this\.html\(`[^`]+`,\s*\"([\w-]+)\"")
BLADE_OUT = re.compile(r"@startMarker\('output',\s*[\"']([\w\-{}$>.]+)[\"']")
JS_OUT    = re.compile(r"this\.output\(`([^`]+)`,\s*[^,]+,\s*(true|false),\s*\[([^\]]*)\]")

bad = []
warned = []
for f in sorted(glob.glob("cases/*.sao")):
    name = os.path.basename(f)[:-4]
    d, err = compile_one(f)
    if err:
        bad.append((name, "COMPILE", err)); continue
    b, js = d.get("blade") or "", d.get("js") or ""
    # Compiler đã tự báo ⇒ không còn là lỗi IM LẶNG, đó là hành vi mong muốn.
    if d.get("warnings"):
        warned.append((name, d["warnings"][0][:100])); continue
    bt, jt = BLADE_TAG.findall(b), JS_TAG.findall(js)
    if bt != jt:
        bad.append((name, "TAG", f"blade={bt} js={jt}")); continue
    # Điểm mù đã từng bỏ sót @block: cả hai cùng rỗng thì so danh sách vẫn "khớp".
    # Thẻ nằm trong wrapper mà KHÔNG có hydrate id là dấu hiệu nội dung bị bỏ quên.
    body = b.split("@wrapper", 1)[-1]
    body = re.sub(r"\{\{--.*?--\}\}|@verbatim\b.*?@endverbatim\b", " ", body, flags=re.S|re.I)
    body = re.sub(r"<(script|style|svg)\b.*?</\1>", " ", body, flags=re.S|re.I)
    VOID = {"br","hr","img","input","meta","link","source","track","area","base","col","embed","param","wbr","path"}
    naked = [t for t in re.findall(r"<([a-zA-Z][\w-]*)((?:[^>\"']|\"[^\"]*\"|'[^']*')*)>", body)
             if t[0].lower() not in VOID and "__VIEW_ID__" not in t[1] and not t[0].startswith("!")]
    if naked:
        bad.append((name, "NO-ID", f"thẻ trong blade không có hydrate id: {[t[0] for t in naked]}")); continue

    bo = set(BLADE_OUT.findall(b))
    jo = {m[0] for m in JS_OUT.findall(js) if m[1]=="true" or m[2].strip()}
    # id có {$loop->index} ở blade tương ứng ${__loopIndex} ở js -> chuẩn hoá
    # blade nội suy PHP "{$i->id}" == js template literal "${i.id}" -> cùng một id
    def norm(s):
        s = re.sub(r"\$\{[^}]*\}", "X", s)   # js  ${...}
        s = re.sub(r"\{\$[^}]*\}", "X", s)   # blade {$...}
        return s
    bo2, jo2 = {norm(x) for x in bo}, {norm(x) for x in jo}
    if bo2 != jo2:
        bad.append((name, "OUTPUT", f"chỉ-blade={sorted(bo2-jo2)} chỉ-js={sorted(jo2-bo2)}"))

print(f"Đã soát {len(glob.glob('cases/*.sao'))} ca — {len(bad)} lỗi im lặng, {len(warned)} ca compiler đã cảnh báo\n")
for n,m in warned: print(f"  [ĐÃ BÁO] {n}\n        {m}")
for n,k,m in bad: print(f"  [{k}] {n}\n        {m}")
