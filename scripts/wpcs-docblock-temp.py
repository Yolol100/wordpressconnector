#!/usr/bin/env python3
"""One-time bounded WPCS doc-comment repair. Remove before opening PR."""
import collections, json, re, sys
from pathlib import Path

report = json.load(open(sys.argv[1], encoding="utf-8"))
issues = collections.defaultdict(list)
for filepath, entry in report["files"].items():
    if "plugin/wordpressconnector/" not in filepath:
        continue
    relative = filepath.split("plugin/wordpressconnector/", 1)[1]
    for message in entry.get("messages", []):
        kind = message.get("source", "")
        if kind in {
            "Squiz.Commenting.FunctionComment.MissingParamTag",
            "Squiz.Commenting.FunctionCommentThrowTag.Missing",
            "Squiz.Commenting.VariableComment.Missing",
        }:
            issues[relative].append((kind, message["line"], message.get("message", "")))

counts = collections.Counter()
skipped, changed = [], []
purposes = {
    "payload": "Requested action payload.",
    "context": "Authorized execution context.",
    "force": "Whether to force this operation.",
    "registry": "Registered connector actions.",
    "path": "Filesystem path.",
    "requestId": "Stable request ID.",
    "fingerprint": "Request fingerprint.",
    "action": "Connector action.",
    "filename": "Import filename.",
    "networkWide": "Whether activation is network wide.",
}
for relative, messages in issues.items():
    path = Path("plugin/wordpressconnector") / relative
    original = path.read_text(encoding="utf-8")
    lines = original.split("\n")
    inserts = collections.defaultdict(list)
    group = collections.defaultdict(list)
    def method_at(line):
        fun = next((i for i in range(line - 1, min(len(lines), line + 50))
                    if re.search(r"\bfunction\s+\w+\s*\(", lines[i])), None)
        if fun is None:
            return None
        close = next((i for i in range(fun - 1, max(-1, fun - 76), -1)
                      if "*/" in lines[i]), None)
        if close is None:
            return None
        start = next((i for i in range(close, max(-1, close - 76), -1)
                      if "/**" in lines[i]), None)
        if start is None:
            return None
        if any(s.strip() and not s.strip().startswith("#[") for s in lines[close + 1:fun]):
            return None
        return start, close, fun

    for kind, line, message in messages:
        if kind.endswith("VariableComment.Missing"):
            at = line - 1
            raw = lines[at]
            match = re.match(r"^(\s*)(?:public|protected|private)\s+(?:(?:static|readonly)\s+)*([\\\w|?]+)\s+(\$\w+)", raw)
            if match:
                indent, typ, name = match.group(1, 2, 3)
            else:
                match = re.match(r"^(\s*)(?:public|protected|private)\s+(\$\w+)", raw)
                if not match:
                    skipped.append((relative, line, "var"))
                    continue
                indent, name = match.group(1, 2)
                typ = "callable" if name == "$authorizationCallback" else "mixed"
            inserts[at].append(f"{indent}/**\n{indent} * Stored connector {name[1:].lower()} state.\n{indent} *\n{indent} * @var {typ}\n{indent} */")
            counts["property"] += 1
            continue
        if relative == "includes/CLI/Command.php":
            skipped.append((relative, line, "wp-cli doc"))
            continue
        location = method_at(line)
        if location is None:
            skipped.append((relative, line, "method"))
            continue
        group[location].append((kind, message))
    for (start, close, fun), records in group.items():
        doc = "\n".join(lines[start:close + 1])
        signature = "\n".join(lines[fun:fun + 24]).split("{", 1)[0]
        existing = set(re.findall(r"@param\s+\S+\s+(\$\w+)", doc))
        params, throws = [], []
        for kind, message in records:
            if kind.endswith("MissingParamTag"):
                found = re.search(r'"(\$\w+)"', message)
                if not found:
                    skipped.append((relative, fun + 1, "param"))
                    continue
                name = found.group(1)
                if name in existing:
                    continue
                if name not in signature:
                    skipped.append((relative, fun + 1, "not-in-signature"))
                    continue
                typematch = re.search(r"(?:(\??[\\a-zA-Z_][\\a-zA-Z0-9_|?]*)\s+)?" + re.escape(name) + r"\b", signature)
                typ = typematch.group(1) if typematch and typematch.group(1) else "mixed"
                desc = purposes.get(name[1:], f"{name[1:]} value for this operation.")
                params.append(f"\t * @param {typ} {name} {desc}")
                existing.add(name)
                counts["param"] += 1
            elif "@throws" not in doc and not throws:
                body = "\n".join(lines[fun:fun + 125])
                thrown = re.search(r"throw\s+new\s+([\\a-zA-Z_][\\a-zA-Z0-9_]*)", body)
                if not thrown:
                    skipped.append((relative, fun + 1, "throw-not-found"))
                    continue
                typ = thrown.group(1)
                if not typ.startswith("\\") and typ not in {"RuntimeException", "Exception", "InvalidArgumentException", "LogicException"}:
                    typ = "\\" + typ
                throws.append(f"\t * @throws {typ} When this operation cannot complete safely.")
                if relative == "includes/Adapters/ConnectorUpdateAdapter.php" and "downloadVerifiedPackage" in signature:
                    throws.append("\t * @throws \\Throwable When a downloaded ZIP must be cleaned up after an error.")
                counts["throws"] += len(throws)
        if params:
            existing_types = re.findall(r"@param\\s+(\\S+)\\s+\\$\\w+", doc)
            new_types = [re.search(r"@param\\s+(\\S+)", row).group(1) for row in params]
            width = max(map(len, existing_types + new_types))
            params = [
                re.sub(r"(@param\\s+)(\\S+)\\s+(\\$\\w+)",
                       lambda m: m.group(1) + m.group(2) + " " * (width - len(m.group(2)) + 1) + m.group(3), row, count=1)
                for row in params
            ]
            if relative == "includes/Adapters/ConnectorUpdateAdapter.php" and "verifiedNativeDownload" in signature:
                prior = [row for row in params if re.search(r"\\$package\\b", row)]
                if prior:
                    before_upgrader = next(i for i in range(start, close) if "@param" in lines[i] and "$upgrader" in lines[i])
                    inserts[before_upgrader].extend(prior)
                    params = [row for row in params if row not in prior]
        if params:
            target = next((i for i in range(start, close) if re.search(r"@(?:return|throws)\b", lines[i])), close)
            if lines[target - 1].strip() != "*" and not lines[target - 1].strip().startswith("* @"):
                inserts[target].append("\t *")
            inserts[target].extend(params)
        if throws:
            if not params and not re.search(r"@(?:return|param)\b", doc) and lines[close - 1].strip() != "*":
                inserts[close].append("\t *")
            inserts[close].extend(throws)
    updated = "\n".join("\n".join(inserts.get(i, []) + [line]) for i, line in enumerate(lines))
    if relative == "includes/Security/Policy.php":
        before_alignment = "$request_confirmed          = false;"
        if updated.count(before_alignment) != 1: raise RuntimeError("Policy spacing anchor missing")
        updated = updated.replace(before_alignment, "$request_confirmed = false;", 1)
    normalize = lambda s: re.sub(r"\s+", "", re.sub(r"/\*\*[\s\S]*?\*/", "", s))
    if normalize(updated) != normalize(original):
        raise RuntimeError("Executable source changed outside documentation: " + relative)
    if updated != original:
        path.write_text(updated, encoding="utf-8")
        changed.append(relative)
print("WPCS_DOCS", json.dumps({"changes": dict(counts), "files": len(changed), "skipped": skipped}))
assert len(changed) >= 12 and counts["param"] >= 35 and counts["property"] >= 15, "Unexpectedly small documentation change set"
