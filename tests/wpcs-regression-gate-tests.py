#!/usr/bin/env python3
"""Executable regression tests: drift and malformed WPCS evidence fail closed."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "wpcs-regression-gate.py"
PREFIX = "/home/runner/work/wordpressconnector/wordpressconnector/plugin/wordpressconnector/"


def fixture(spec):
    files = {}
    errors = warnings = 0
    for file, messages in spec.items():
        rows = []
        for level, source in messages:
            rows.append({"type": level, "source": source, "line": 1, "column": 1})
            errors += level == "ERROR"
            warnings += level == "WARNING"
        files[PREFIX + file] = {"errors": sum(m["type"] == "ERROR" for m in rows),
                                "warnings": sum(m["type"] == "WARNING" for m in rows),
                                "messages": rows}
    return {"totals": {"errors": errors, "warnings": warnings}, "files": files}


def run_case(baseline, current, expected, name):
    with tempfile.TemporaryDirectory(prefix="wpcs-gate-") as directory:
        basefile, nowfile = Path(directory) / "base.json", Path(directory) / "now.json"
        basefile.write_text(json.dumps(baseline), encoding="utf-8")
        nowfile.write_text(json.dumps(current), encoding="utf-8")
        result = subprocess.run(
            [sys.executable, "-B", str(SCRIPT), str(basefile), str(nowfile)],
            text=True, capture_output=True, check=False,
        )
        if result.returncode != expected:
            raise AssertionError(f"{name}: expected {expected}, got {result.returncode}\n{result.stdout}\n{result.stderr}")


base = fixture({
    "includes/Legacy.php": [("ERROR", "Sniff.A"), ("WARNING", "Sniff.C")],
    "includes/Auth.php": [("ERROR", "Sniff.B")],
})
run_case(base, base, 0, "no regression")
run_case(base, fixture({
    "includes/Legacy.php": [("WARNING", "Sniff.C")],
    "includes/Auth.php": [("ERROR", "Sniff.B")],
}), 0, "fewer findings")
run_case(base, fixture({
    "includes/Legacy.php": [("ERROR", "Sniff.B"), ("WARNING", "Sniff.C")],
    "includes/Auth.php": [("ERROR", "Sniff.B")],
}), 1, "new sniff even if total unchanged")
run_case(base, fixture({
    "includes/Legacy.php": [("ERROR", "Sniff.A"), ("WARNING", "Sniff.C")],
    "includes/Auth.php": [("ERROR", "Sniff.B"), ("ERROR", "Sniff.B")],
}), 1, "existing sniff increased")
run_case(base, {"totals": {"errors": 0, "warnings": 0}, "files": {}}, 2, "missing evidence")
run_case(base, {"totals": {"errors": 1, "warnings": 0}, "files": base["files"]}, 2, "corrupt totals")
# A net-unchanged count must NOT hide an entirely new violation on a
# different source line. This is the runtime mode used by GitHub Actions.
with tempfile.TemporaryDirectory(prefix="wpcs-lines-") as directory:
    root = Path(directory)
    base_root, candidate_root = root / "base", root / "candidate"
    rel = Path("plugin/wordpressconnector/includes/Auth.php")
    for source_root, content in ((base_root, "<?php\nunsafe_old();\n"),
                                 (candidate_root, "<?php\nunsafe_new();\n")):
        target = source_root / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8")
    report = fixture({"includes/Auth.php": [("ERROR", "Sniff.Untrusted")]})
    # Fixture source line 1 must be moved to the actual offending source line.
    report["files"][PREFIX + "includes/Auth.php"]["messages"][0]["line"] = 2
    basefile, nowfile = root / "before.json", root / "after.json"
    basefile.write_text(json.dumps(report), encoding="utf-8")
    nowfile.write_text(json.dumps(report), encoding="utf-8")
    cmd = [sys.executable, "-B", str(SCRIPT), str(basefile), str(nowfile),
           "--base-root", str(base_root), "--current-root", str(candidate_root)]
    changed = subprocess.run(cmd, capture_output=True, text=True, check=False)
    if changed.returncode != 1 or "unsafe_new" not in changed.stdout:
        raise AssertionError("New source-line violation must fail even when sniff counts match")
    (candidate_root / rel).write_text("<?php\nunsafe_old();\n", encoding="utf-8")
    stable = subprocess.run(cmd, capture_output=True, text=True, check=False)
    if stable.returncode != 0:
        raise AssertionError("Identical source lines should pass exact-file/sniff gate")
print("WPCS per-file/sniff/source-line baseline regression and fail-closed parser tests OK")
