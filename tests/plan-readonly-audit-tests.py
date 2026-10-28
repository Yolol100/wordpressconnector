#!/usr/bin/env python3
"""Offline safety tests for the public-safe audit planner."""
import importlib.util
import json
import subprocess
import sys
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
script = root / "scripts" / "plan-readonly-audit.py"
spec = importlib.util.spec_from_file_location("audit_planner", script)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
assert module.canonical_site("https://Example.COM") == "https://example.com"
for bad in ("http://example.com", "https://user:pass@example.com", "https://localhost",
            "https://example.com/wp-admin", "https://example.com?token=secret",
            "https://example.com:8443", "https://example.com.evil/path"):
    try:
        module.canonical_site(bad)
    except ValueError:
        pass
    else:
        raise AssertionError("Unsafe URL accepted: " + bad)
requests = module.plan("https://example.com", "audit-20261028", 12)
assert len(requests) == 7
for request in requests:
    assert request["dry_run"] is True and request["confirm"] is False
    assert request["action"] in module.READS + ("elementor.inspect",)
    assert "expected_state_token" not in request
with tempfile.TemporaryDirectory() as temp:
    cmd = [sys.executable, str(script), "--site", "https://example.com",
           "--prefix", "audit-20261028", "--output", temp, "--elementor-id", "12"]
    assert subprocess.run(cmd, capture_output=True).returncode == 0
    files = list(Path(temp).glob("*.json"))
    assert len(files) == 7
    ids = {json.loads(p.read_text())["request_id"] for p in files}
    assert len(ids) == 7
    assert subprocess.run(cmd, capture_output=True).returncode != 0
print("read-only audit planner safety and isolation OK")
