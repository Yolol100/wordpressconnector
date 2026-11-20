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
print("WPCS per-file per-sniff baseline regression and fail-closed parser tests OK")
