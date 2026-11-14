#!/usr/bin/env python3
"""One-time conservative private-only method rename for WPCS; remove before PR."""
from pathlib import Path
from collections import Counter
import re
import sys

if len(sys.argv) != 2:
    raise SystemExit("usage: wpcs-private-methods-temp.py plugin/wordpressconnector")
root = Path(sys.argv[1])
assert root.is_dir()
paths = list(root.rglob("*.php"))
sources = {p: p.read_text() for p in paths}
testdir = root.parents[1] / "tests"
assert testdir.is_dir()
test_corpus = "\n".join(p.read_text(errors="replace") for p in testdir.rglob("*.php"))
other_corpus = "\n".join(p.read_text(errors="replace") for p in root.parents[1].joinpath("scripts").rglob("*.php"))
changed_files = []
excluded = Counter()
for path, source in sources.items():
    candidates = []
    # Ambiguous dynamic dispatch / reflection means no method names in this file are changed.
    if re.search(r"\$this\s*->\s*\$|\$this\s*->\s*\{|call_user_func\s*\(|ReflectionMethod|is_callable\s*\(|method_exists\s*\(", source):
        excluded["dynamic_owner"] += 1
        continue
    # Keep mixed-class sources unchanged; cross-class lexical renames are not proven safe.
    if len(re.findall(r"(?<!->)\bclass\s+[A-Za-z_]\w*", source)) > 1:
        excluded["multi_class"] += 1
        continue
    for declaration in re.finditer(r"\bprivate\s+function\s+([A-Za-z_]\w*)\s*\(", source):
        original = declaration.group(1)
        renamed = re.sub(r"([a-z0-9])([A-Z])", r"\1_\2", re.sub(r"([A-Z]+)([A-Z][a-z])", r"\1_\2", original)).lower()
        if renamed == original:
            continue
        pattern = r"\b" + re.escape(original) + r"\b"
        if re.search(pattern, test_corpus, re.I) or re.search(pattern, other_corpus, re.I):
            excluded["referenced_by_tests_or_scripts"] += 1
            continue
        if any(re.search(pattern, text, re.I) for other, text in sources.items() if other != path):
            excluded["cross_file_reference"] += 1
            continue
        if re.search(r"(['\"])" + re.escape(original) + r"\1", source):
            excluded["literal_callback"] += 1
            continue
        occurrences = list(re.finditer(pattern, source))
        def safe_occurrence(match):
            before = source[max(0, match.start() - 48):match.start()]
            after = source[match.end():match.end() + 15]
            return bool((re.search(r"\bfunction\s*$", before) or re.search(r"->\s*$", before))
                        and re.match(r"\s*\(", after))
        if not all(safe_occurrence(x) for x in occurrences):
            excluded["unexpected_reference"] += 1
            continue
        if re.search(r"\b" + re.escape(renamed) + r"\b", source, re.I):
            excluded["new_name_collision"] += 1
            continue
        candidates.append((original, renamed, occurrences))
    if not candidates:
        continue
    edits = [(hit.start(), hit.end(), new) for _, new, occurrences in candidates for hit in occurrences]
    altered = source
    for first, last, replacement in sorted(edits, key=lambda x: x[0], reverse=True):
        altered = altered[:first] + replacement + altered[last:]
    if altered != source:
        path.write_text(altered)
        changed_files.append((str(path), len(candidates), len(edits)))
        print("PRIVATE_FILE", path, "methods", len(candidates), "usages", len(edits))
total = sum(x[1] for x in changed_files)
occurrences = sum(x[2] for x in changed_files)
print("PRIVATE_METHODS_SUMMARY", {"files":len(changed_files),"methods":total,"occurrences":occurrences,"excluded":dict(excluded)})
if not (180 <= total <= 260 and 500 <= occurrences <= 1100):
    raise RuntimeError("Unexpected private method candidate count, fail closed")
