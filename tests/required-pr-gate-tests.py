#!/usr/bin/env python3
"""Pure, offline regressions for the always-emitted required PR status."""
import importlib.util
import pathlib
import unittest

script = pathlib.Path(__file__).resolve().parents[1] / "scripts" / "verify-required-pr-gate.py"
spec = importlib.util.spec_from_file_location("required_pr_gate", script)
gate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gate)
SHA = "f" * 40


def action_run(conclusion="success", name="Connector CI", pr=1024, head=SHA):
    return {
        "event": "pull_request", "status": "completed", "conclusion": conclusion,
        "head_sha": head, "created_at": "2026-10-22T12:00:00Z",
        "run_attempt": 1, "id": 1234, "pull_requests": [{"number": pr}],
    }


class FakeClient:
    repo = "Yolol100/wordpressconnector"

    def __init__(self, filenames=None, ci=None, docker=None, wpcs=None, head=SHA, base="main"):
        self.filenames = filenames or ["plugin/wordpressconnector/includes/Adapters/Foo.php"]
        self.ci = ci if ci is not None else [action_run()]
        self.docker = docker if docker is not None else [action_run(name="Docker")]
        self.wpcs = wpcs if wpcs is not None else [action_run(name="WPCS")]
        self.head, self.base = head, base

    def get(self, suffix):
        if suffix == "pulls/1024":
            return {"head": {"sha": self.head}, "base": {"ref": self.base}}
        if suffix.startswith("pulls/1024/files?"):
            return [{"filename": name, "status": "modified"} for name in self.filenames]
        if suffix.startswith("actions/workflows/ci.yml/"):
            return {"workflow_runs": self.ci}
        if suffix.startswith("actions/workflows/docker-runtime-smoke.yml/"):
            return {"workflow_runs": self.docker}
        if suffix.startswith("actions/workflows/wpcs-audit.yml/"):
            return {"workflow_runs": self.wpcs}
        raise AssertionError("Unexpected API call: " + suffix)


class RequiredPrGateTests(unittest.TestCase):
    def test_regular_code_and_documentation(self):
        self.assertTrue(gate.classify_paths(["plugin/wordpressconnector/file.php"]))
        self.assertTrue(gate.classify_paths([".wp-env.json"]))
        self.assertTrue(gate.classify_paths(["docs/LOCAL-RUNTIME-TESTING.md"]))
        self.assertFalse(gate.classify_paths(["README.md"]))
        self.assertFalse(gate.classify_paths(["tests/test-a.php"]))

    def test_untrusted_pr_cannot_modify_its_ci_or_release_authority(self):
        for path in sorted(gate.PROTECTED_GOVERNANCE_PATHS):
            with self.subTest(path=path), self.assertRaises(gate.GateError):
                gate.classify_paths([path])
            with self.subTest(mixed=path), self.assertRaises(gate.GateError):
                gate.classify_paths(["plugin/wordpressconnector/new.php", path])

    def test_quality_and_supply_chain_verifiers_are_immutable_via_normal_pr(self):
        required = {
            ".github/workflows/wpcs-audit.yml",
            "phpcs.xml.dist",
            "scripts/wpcs-regression-gate.py",
            "tests/wpcs-regression-gate-tests.py",
            "scripts/verify-release-supply-chain.py",
            "tests/release-supply-chain-tests.py",
            "scripts/build-release-package.py",
        }
        self.assertTrue(required.issubset(gate.PROTECTED_GOVERNANCE_PATHS))
        for path in sorted(required):
            with self.subTest(path=path), self.assertRaises(gate.GateError):
                gate.classify_paths([path])

    def test_privileged_release_uses_isolated_python(self):
        root = pathlib.Path(__file__).resolve().parents[1]
        workflow = (root / ".github/workflows/release.yml").read_text(encoding="utf-8")
        self.assertIn('PYTHONSAFEPATH: "1"', workflow)
        for invocation in (
            "python3 -I -B scripts/build-release-package.py",
            "python3 -I -B tests/release-supply-chain-tests.py",
            "python3 -I -B scripts/verify-release-supply-chain.py",
            "python3 -I -m json.tool",
        ):
            with self.subTest(invocation=invocation):
                self.assertIn(invocation, workflow)
        child = (root / "tests/release-supply-chain-tests.py").read_text(encoding="utf-8")
        self.assertIn('[sys.executable, "-I", "-B", str(VERIFY)', child)

    def test_runtime_transport_is_never_mergeable(self):
        for path in ("requests/temp.json", "results/private.json", "receipts/public.json", "assets/inbox/image.png"):
            with self.subTest(path=path), self.assertRaises(gate.GateError):
                gate.classify_paths([path])
            with self.subTest(mixed=path), self.assertRaises(gate.GateError):
                gate.classify_paths(["docs/CHANGELOG.md", path])
        with self.assertRaises(gate.GateError):
            gate.classify_paths([])

    def test_event_pr_identity_is_enforced(self):
        self.assertIsNotNone(gate.matching_run([action_run()], 1024, SHA))
        self.assertIsNone(gate.matching_run([action_run(pr=1025)], 1024, SHA))
        self.assertIsNone(gate.matching_run([action_run(head="a"*40)], 1024, SHA))
        bad = dict(action_run())
        bad["event"] = "push"
        self.assertIsNone(gate.matching_run([bad], 1024, SHA))

    def test_newest_run_outcome_wins(self):
        early = action_run(conclusion="success")
        late = dict(action_run(conclusion="failure"))
        late["created_at"] = "2026-10-22T13:00:00Z"
        late["id"] = 4567
        self.assertEqual(gate.matching_run([early, late], 1024, SHA)["conclusion"], "failure")

    def test_positive_code_and_docs_gates(self):
        result = gate.verify(FakeClient(), 1024, SHA, deadline_seconds=0)
        self.assertEqual(result["ci"], "success")
        self.assertEqual(result["wpcs"], "success")
        self.assertTrue(result["docker_required"])
        docs = gate.verify(FakeClient(filenames=["README.md"], docker=[]), 1024, SHA, deadline_seconds=0)
        self.assertFalse(docs["docker_required"])

    def test_fail_closed_on_checks_or_stale_head(self):
        bad_cases = (
            FakeClient(ci=[action_run(conclusion="failure")]),
            FakeClient(wpcs=[action_run(conclusion="failure", name="WPCS")]),
            FakeClient(wpcs=[]),
            FakeClient(docker=[action_run(conclusion="failure")]),
            FakeClient(ci=[]),
            FakeClient(docker=[]),
            FakeClient(head="a"*40),
            FakeClient(base="develop"),
            FakeClient(filenames=["requests/private.json"]),
        )
        for client in bad_cases:
            with self.subTest(failure=str(client.__dict__)), self.assertRaises(gate.GateError):
                gate.verify(client, 1024, SHA, deadline_seconds=0)

    def test_invalid_identity(self):
        for repo, number, sha in (("", 1024, SHA), ("evil/../repo", 1024, SHA),
                                  ("ok/repo", 0, SHA), ("ok/repo", 1024, "not-sha")):
            with self.subTest(repo=repo, number=number), self.assertRaises(gate.GateError):
                gate.validate_identity(repo, number, sha)


if __name__ == "__main__":
    unittest.main(verbosity=2)
