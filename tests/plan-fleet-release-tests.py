#!/usr/bin/env python3
"""Fail-closed contract tests for private-input fleet planning."""

import copy
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "plan-fleet-release.py"
spec = importlib.util.spec_from_file_location("fleet_planner", SCRIPT)
planner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(planner)


def sample():
    return {
        "schema_version": 1,
        "release": {
            "kind": "plugin",
            "identity": "sample/sample.php",
            "repository": "example-org/sample",
            "version": "2.1.0",
            "sha256": "a" * 64,
        },
        "sites": [
            {
                "site_ref": "site-002",
                "authorized": True,
                "read_verified": True,
                "components": [
                    {
                        "kind": "plugin",
                        "identity": "sample/sample.php",
                        "repository": "example-org/sample",
                        "version": "2.0.0",
                        "active": True,
                        "available_version": "2.1.0",
                    }
                ],
            }
        ],
    }


class FleetPlanTests(unittest.TestCase):
    def setUp(self):
        self.request = sample()

    def plan(self):
        return planner.plan_release(self.request)

    def block(self, reason):
        result = self.plan()
        self.assertEqual(result["candidates"], [])
        self.assertEqual(result["blocked"][0]["reason"], reason)
        self.assertFalse(result["execution_enabled"])

    def invalid(self):
        with self.assertRaises(planner.PlanError):
            self.plan()

    def test_native_plugin_version_pin(self):
        result = self.plan()
        self.assertEqual(result["summary"], {"candidates": 1, "blocked": 0, "skipped": 0})
        candidate = result["candidates"][0]
        self.assertEqual(candidate["action"], "plugin.update")
        self.assertEqual(candidate["target_version"], "2.1.0")
        self.assertTrue(candidate["requires_confirmation"])
        self.assertTrue(candidate["requires_live_readback"])
        self.assertFalse(result["execution_enabled"])
        self.assertTrue(result["site_identity_is_self_reported"])

    def test_private_package_candidate_without_offer(self):
        del self.request["sites"][0]["components"][0]["available_version"]
        result = self.plan()
        self.assertEqual(result["candidates"][0]["action"], "plugin.install_package")
        self.assertEqual(result["candidates"][0]["mode"], "private_verified_package")

    def test_mismatched_offer_blocks_package_fallback(self):
        self.request["sites"][0]["components"][0]["available_version"] = "2.2.0"
        self.block("different_update_offer")

    def test_missing_authorization_fails_closed(self):
        self.request["sites"][0]["authorized"] = False
        self.block("authorization_or_read_unverified")
        self.request["sites"][0]["authorized"] = True
        self.request["sites"][0]["read_verified"] = False
        self.block("authorization_or_read_unverified")

    def test_source_mismatch_blocks(self):
        self.request["sites"][0]["components"][0]["repository"] = "other-org/sample"
        self.block("source_repository_mismatch")

    def test_inactive_installation_skipped(self):
        self.request["sites"][0]["components"][0]["active"] = False
        result = self.plan()
        self.assertEqual(result["skipped"][0]["reason"], "not_active")

    def test_uninstalled_target_skipped(self):
        self.request["sites"][0]["components"] = []
        self.assertEqual(self.plan()["skipped"][0]["reason"], "not_installed")

    def test_current_and_newer_not_updated(self):
        component = self.request["sites"][0]["components"][0]
        component["version"] = "2.1.0"
        self.assertEqual(self.plan()["skipped"][0]["reason"], "already_current")
        component["version"] = "3.0.0"
        self.assertEqual(self.plan()["skipped"][0]["reason"], "newer_installed")

    def test_unparseable_version_blocks(self):
        self.request["sites"][0]["components"][0]["version"] = "nightly-2026"
        self.block("installed_version_unverifiable")
        self.request["sites"][0]["components"][0]["version"] = "2.0.0"
        self.request["sites"][0]["components"][0]["available_version"] = "nightly"
        self.block("update_offer_unverifiable")

    def test_active_theme_requires_native_offer(self):
        self.request["release"]["kind"] = "theme"
        self.request["release"]["identity"] = "example-theme"
        component = self.request["sites"][0]["components"][0]
        component.update({"kind": "theme", "identity": "example-theme"})
        component.pop("available_version")
        self.block("active_theme_needs_native_update_offer")
        component["available_version"] = "2.1.0"
        self.assertEqual(self.plan()["candidates"][0]["action"], "theme.update")

    def test_duplicate_sites_rejected(self):
        self.request["sites"].append(copy.deepcopy(self.request["sites"][0]))
        self.invalid()

    def test_duplicate_component_rejected(self):
        self.request["sites"][0]["components"].append(
            copy.deepcopy(self.request["sites"][0]["components"][0])
        )
        self.invalid()

    def test_foreign_or_secret_fields_rejected(self):
        self.request["sites"][0]["site_url"] = "https://customer.invalid"
        self.invalid()
        del self.request["sites"][0]["site_url"]
        self.request["sites"][0]["components"][0]["access_token"] = "should-not-leak"
        self.invalid()

    def test_invalid_target_identity_and_repository(self):
        self.request["release"]["identity"] = "../sample.php"
        self.invalid()
        self.request["release"]["identity"] = "sample/sample.php"
        self.request["release"]["repository"] = "https://github.com/example-org/sample"
        self.invalid()

    def test_bad_checksum_and_versions(self):
        self.request["release"]["sha256"] = "not-a-checksum"
        self.invalid()
        self.request["release"]["sha256"] = "a" * 64
        self.request["release"]["version"] = "release-candidate"
        self.invalid()

    def test_invalid_boolean_and_enumeration(self):
        self.request["sites"][0]["authorized"] = 1
        self.invalid()
        self.request["sites"][0]["authorized"] = True
        self.request["sites"][0]["components"][0]["active"] = "true"
        self.invalid()
        self.request["sites"][0]["components"][0]["active"] = True
        self.request["release"]["kind"] = "shell"
        self.invalid()

    def test_bounded_inventory_and_sorted_results(self):
        extra = copy.deepcopy(self.request["sites"][0])
        extra["site_ref"] = "site-001"
        extra["components"][0]["active"] = False
        self.request["sites"].append(extra)
        result = self.plan()
        self.assertEqual(result["candidates"][0]["site_ref"], "site-002")
        self.assertEqual(result["skipped"][0]["site_ref"], "site-001")
        self.request["sites"] *= 501
        self.invalid()

    def test_cli_writes_private_file_and_no_site_output(self):
        with tempfile.TemporaryDirectory() as folder:
            input_file = Path(folder) / "private.json"
            output_file = Path(folder) / "result.json"
            input_file.write_text(json.dumps(self.request), encoding="utf-8")
            cmd = [sys.executable, str(SCRIPT), "--input", str(input_file), "--output", str(output_file)]
            completed = subprocess.run(cmd, text=True, capture_output=True, check=False)
            self.assertEqual(completed.returncode, 0, completed.stderr)
            self.assertNotIn("site-002", completed.stdout)
            self.assertFalse(json.loads(output_file.read_text())["execution_enabled"])
            if os.name == "posix":
                self.assertEqual(stat.S_IMODE(output_file.stat().st_mode), 0o600)
            second = subprocess.run(cmd, text=True, capture_output=True, check=False)
            self.assertNotEqual(second.returncode, 0)

    def test_empty_site_list_is_no_change(self):
        self.request["sites"] = []
        self.assertEqual(self.plan()["summary"], {"candidates": 0, "skipped": 0, "blocked": 0})


if __name__ == "__main__":
    unittest.main(verbosity=2)
