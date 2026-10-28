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
            "https://example.com:8443", "https://127.0.0.1", "https://intranet", "https://example.com.evil/path", "https://-bad.example.com",
            "https://bad-.example.com", "https://example.com%0a.evil", "https://example.com "):
    try:
        module.canonical_site(bad)
    except ValueError:
        pass
    else:
        raise AssertionError("Unsafe URL accepted: " + bad)
requests = module.plan("https://example.com", "audit-20261028", 12)
assert len(requests) == 6
assert all(request["action"] != "acf.field_groups" for request in requests)
assert len(module.plan("https://example.com", "audit-20261028", 12, 22)) == 7
assert next(request for request in module.plan("https://example.com", "audit-20261028", 12, 22) if request["action"] == "acf.field_groups")["payload"] == {"post_id": 22}
assert module.plan("https://example.com", "audit-20261028", page=2, per_page=25)[2]["payload"] == {"post_type": "page", "page": 2, "per_page": 25}
for bad_page, bad_per_page in ((0, 25), (1, 26), (1, 0)):
    try:
        module.plan("https://example.com", "audit-20261028", page=bad_page, per_page=bad_per_page)
    except ValueError:
        pass
    else:
        raise AssertionError("Invalid paging accepted")
for request in requests:
    assert request["dry_run"] is True and request["confirm"] is False
    assert request["action"] in module.READS + ("elementor.inspect",)
    assert "expected_state_token" not in request
with tempfile.TemporaryDirectory() as temp:
    cmd = [sys.executable, str(script), "--site", "https://example.com",
           "--prefix", "audit-20261028", "--output", temp, "--elementor-id", "12"]
    assert subprocess.run(cmd, capture_output=True).returncode == 0
    files = list(Path(temp).glob("*.json"))
    assert len(files) == 6
    ids = {json.loads(p.read_text())["request_id"] for p in files}
    assert len(ids) == 7
    assert subprocess.run(cmd, capture_output=True).returncode != 0
    cmd_acf = [sys.executable, str(script), "--site", "https://example.com",
               "--prefix", "acf-audit-20261028", "--output", str(Path(temp) / "acf"),
               "--acf-post-id", "22"]
    assert subprocess.run(cmd_acf, capture_output=True).returncode == 0
    acf_requests = [json.loads(p.read_text()) for p in (Path(temp) / "acf").glob("*.json")]
    assert len(acf_requests) == 6
    assert next(req for req in acf_requests if req["action"] == "acf.field_groups")["payload"]["post_id"] == 22
print("read-only audit planner safety and isolation OK")
