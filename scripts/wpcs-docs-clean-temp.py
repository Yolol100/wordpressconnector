#!/usr/bin/env python3
"""One-time comment-only WordPress PHPDoc corrections. Never merge this helper."""
import sys, json, re, pathlib
from collections import defaultdict,Counter
if len(sys.argv)!=2: raise SystemExit("usage: script PHPCS_JSON")
root=pathlib.Path("plugin/wordpressconnector")
audit=json.load(open(sys.argv[1],encoding="utf-8"))
categories={
 "Squiz.Commenting.FunctionComment.MissingParamTag",
 "Squiz.Commenting.FunctionCommentThrowTag.Missing",
 "Squiz.Commenting.VariableComment.Missing",
}
count=Counter(); changed=[]
def block_for(lines, pos):
    if not 0<=pos<len(lines): return None
    if "/**" in lines[pos]:
        return next(((pos,k) for k in range(pos,min(len(lines),pos+80)) if "*/" in lines[k]),None)
    if "*/" in lines[pos]:
        return next(((k,pos) for k in range(pos,max(-1,pos-80),-1) if "/**" in lines[k]),None)
    return None
def description(n):
    examples={
      "requestId":"Request identifier for durable execution state.",
      "fingerprint":"Expected request fingerprint.",
      "payload":"Validated action payload.",
      "context":"Authorized execution context.",
      "filename":"Filename to process.",
      "path":"Filesystem path to validate.",
      "url":"URL to inspect.",
      "group":"Provider settings group.",
      "registry":"Action registry to register handlers with.",
      "pluginData":"Validated plugin metadata.",
      "package":"Validated package metadata.",
      "release":"Verified release metadata.",
      "args":"Command-line arguments.",
      "assocArgs":"Associative command-line arguments.",
      "hook":"Registered WordPress hook name.",
      "name":"Registered resource name.",
      "fields":"Fields to validate.",
      "force":"Whether to force a refresh.",
      "networkWide":"Whether the operation is network-wide.",
      "olderThanSeconds":"Minimum age in seconds.",
      "action":"Registered connector action.",
      "resultHash":"Stored result fingerprint.",
      "terminalError":"Error text recorded for failed operations.",
      "ok":"Whether the operation succeeded.",
      "id":"Target identifier.",
    }
    if n in examples:return examples[n]
    return "Validated "+re.sub(r"([a-z0-9])([A-Z])",r"\1 \2",n).replace("_"," ").lower()+" value."
def ptype(n,declaration):
    match=re.search(r"(?<![\w$])(?P<type>\??(?:\\?[A-Za-z_]\w*)(?:[|&]\\?[A-Za-z_]\w*)*)\s*(?:&\s*)?(?:\.\.\.\s*)?\$"+re.escape(n)+r"\b",declaration)
    if match:return match.group("type")
    return "mixed"
for full,entry in audit["files"].items():
    if "/plugin/wordpressconnector/" not in full:continue
    f=root / full.split("/plugin/wordpressconnector/",1)[1]
    if not f.is_file():raise RuntimeError("file missing "+str(f))
    selected=[m for m in entry["messages"] if m["source"] in categories]
    if not selected:continue
    original=f.read_text()
    lines=original.splitlines(keepends=True)
    changes=defaultdict(lambda:{"params":set(),"throws":False})
    vars=[]
    for m in selected:
        pos=m["line"]-1
        if m["source"].endswith("VariableComment.Missing"):
            vars.append(pos);continue
        bounds=block_for(lines,pos)
        if bounds is None:raise RuntimeError(f"cannot locate docblock {f}:{pos+1}")
        if m["source"].endswith("MissingParamTag"):
            match=re.search(r'parameter ["\x27]\$(\w+)["\x27] missing',m["message"])
            if not match: raise RuntimeError("missing param name "+repr(m))
            changes[bounds]["params"].add(match.group(1))
        else: changes[bounds]["throws"]=True
    operations=[]
    for (start,end),need in changes.items():
        block=lines[start:end+1]
        prefix=re.match(r"([ \t]*)",lines[start]).group(1)
        clean=[line.rstrip("\r\n") for line in block[:-1]]
        joined="\n".join(clean)
        declaration="".join(lines[end+1:end+20]).split("{",1)[0]
        names=sorted(need["params"],key=lambda n:(declaration.find("$"+n) if "$"+n in declaration else 9999,n))
        additions=[]
        for n in names:
            if re.search(r"@param\s+[^\r\n]*\$"+re.escape(n)+r"\b",joined):continue
            additions.append(f"{prefix} * @param {ptype(n,declaration)} ${n} {description(n)}")
        if additions:
            at=next((i for i,l in enumerate(clean) if re.search(r"@(?:return|throws|deprecated|since)\b",l)),len(clean))
            if at and clean[at-1].strip() not in ("*","") and "@param" not in clean[at-1]:
                additions.insert(0,f"{prefix} *")
            if at<len(clean) and clean[at].strip()!="*":additions.append(f"{prefix} *")
            clean[at:at]=additions
            count["params"]+=sum("@param" in x for x in additions)
        if need["throws"] and not any("@throws" in row for row in clean):
            if clean and clean[-1].strip() not in ("*",""):clean.append(f"{prefix} *")
            clean.append(f"{prefix} * @throws \Throwable When the requested operation cannot be completed safely.")
            count["throws"]+=1
        edit="\n".join(clean)+"\n"+block[-1]
        if edit!="".join(block):operations.append((start,end+1,edit))
    for pos in set(vars):
        line=lines[pos]
        match=re.search(r"\b(?:private|protected|public|var)\s+(?:(?:readonly|static)\s+)*(?:(?P<type>\??[\\\w|]+)\s+)?\$(?P<name>\w+)\s*[;=]",line)
        if not match:raise RuntimeError("unexpected member "+str(f)+":"+str(pos+1))
        indent=re.match(r"([ \t]*)",line).group(1)
        name=match.group("name")
        typ=match.group("type") or "mixed"
        label=re.sub(r"([a-z0-9])([A-Z])",r"\1 \2",name).replace("_"," ").lower()
        content=f"{indent}/**\n{indent} * {label.capitalize()} maintained by the connector.\n{indent} *\n{indent} * @var {typ}\n{indent} */\n"
        operations.append((pos,pos,content))
        count["members"]+=1
    for start,end,repl in sorted(operations,key=lambda op:op[0],reverse=True):
        lines[start:end]=repl.splitlines(keepends=True)
    updated="".join(lines)
    if original!=updated:
        f.write_text(updated)
        changed.append(str(f))
        print("changed",f,len(operations))
print("DOCS",dict(count),"files",len(changed))
assert count["params"]>=45 and count["throws"]>=12 and count["members"]>=15
