#!/usr/bin/env python3
"""Stable, read-only PR merge gate for path-filtered Connector workflows.

This is a required check, not an executor. Temporary OIDC transport PRs are
not mergeable implementation changes, even when their request workflow runs.
"""
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

RUNTIME_PREFIXES = ("requests/", "results/", "receipts/", "assets/inbox/")
DOCKER_PREFIX = "plugin/wordpressconnector/"
DOCKER_EXACT = {
    ".wp-env.json",
    ".github/workflows/docker-runtime-smoke.yml",
    "docs/LOCAL-RUNTIME-TESTING.md",
}


class GateError(RuntimeError):
    pass


def validate_identity(repo, number, head):
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repo or ""):
        raise GateError("Untrusted repository identity.")
    if not str(number).isdigit() or int(number) < 1:
        raise GateError("Invalid pull request number.")
    if not re.fullmatch(r"[a-f0-9]{40}", head or ""):
        raise GateError("Invalid immutable pull request commit SHA.")


def classify_paths(paths):
    if not paths:
        raise GateError("Empty PR diff cannot pass merge validation.")
    if any(p.startswith(RUNTIME_PREFIXES) for p in paths):
        raise GateError(
            "Temporary OIDC request/result/receipt/asset state must remain on "
            "its runtime PR branch and must not be merged into main."
        )
    return any(p.startswith(DOCKER_PREFIX) or p in DOCKER_EXACT for p in paths)


class GitHubReadClient:
    def __init__(self, repo, token):
        if not token:
            raise GateError("Read-only GitHub Actions token is unavailable.")
        self.repo = repo
        self.token = token

    def get(self, suffix):
        path = "https://api.github.com/repos/" + self.repo + "/" + suffix
        req = urllib.request.Request(
            path,
            headers={
                "Accept": "application/vnd.github+json",
                "Authorization": "Bearer " + self.token,
                "X-GitHub-Api-Version": "2022-11-28",
                "User-Agent": "wordpressconnector-required-pr-gate",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=25) as response:
                return json.load(response)
        except urllib.error.HTTPError as error:
            raise GateError("GitHub read failed (HTTP %s)." % error.code) from error
        except (urllib.error.URLError, ValueError) as error:
            raise GateError("GitHub read unavailable or malformed.") from error


def changed_paths(client, number):
    paths = []
    for page in range(1, 32):
        rows = client.get("pulls/%d/files?per_page=100&page=%d" % (number, page))
        if not isinstance(rows, list):
            raise GateError("Unexpected PR changed-files response.")
        for item in rows:
            if not isinstance(item, dict) or not isinstance(item.get("filename"), str):
                raise GateError("Malformed PR changed-file descriptor.")
            paths.append(item["filename"])
            if item.get("status") == "renamed":
                old = item.get("previous_filename")
                if not isinstance(old, str):
                    raise GateError("Renamed file has no previous path.")
                paths.append(old)
        if len(rows) < 100:
            return paths
    raise GateError("PR exceeds the bounded 3,000-file review limit.")


def matching_run(runs, number, head):
    compatible = []
    for run in runs:
        if (
            isinstance(run, dict)
            and run.get("event") == "pull_request"
            and run.get("head_sha") == head
            and any(pr.get("number") == number for pr in run.get("pull_requests", []) if isinstance(pr, dict))
        ):
            compatible.append(run)
    if not compatible:
        return None
    return max(
        compatible,
        key=lambda run: (run.get("created_at") or "", int(run.get("run_attempt") or 0), int(run.get("id") or 0)),
    )


def verify_head(client, number, expected):
    pr = client.get("pulls/%d" % number)
    if pr.get("base", {}).get("ref") != "main":
        raise GateError("Required PR gate only accepts the main target branch.")
    if pr.get("head", {}).get("sha") != expected:
        raise GateError("PR head changed during verification; refusing stale success.")
    return pr


def verify(client, number, head, deadline_seconds=1560, sleep_seconds=15):
    validate_identity(client.repo, number, head)
    verify_head(client, number, head)
    needs_docker = classify_paths(changed_paths(client, number))
    names = ["ci.yml"] + (["docker-runtime-smoke.yml"] if needs_docker else [])
    started = time.monotonic()
    while True:
        unfinished = []
        for workflow in names:
            qs = urllib.parse.urlencode({"event": "pull_request", "head_sha": head, "per_page": 100})
            response = client.get("actions/workflows/%s/runs?%s" % (workflow, qs))
            run = matching_run(response.get("workflow_runs", []), number, head)
            if run is None or run.get("status") != "completed":
                unfinished.append(workflow)
                continue
            if run.get("conclusion") != "success":
                raise GateError("%s completed without success (%s)." % (workflow, run.get("conclusion")))
        if not unfinished:
            verify_head(client, number, head)
            return {"sha": head, "pr": number, "ci": "success", "docker_required": needs_docker}
        if time.monotonic() - started >= deadline_seconds:
            raise GateError("Required workflow verification timed out: " + ", ".join(unfinished))
        time.sleep(sleep_seconds)


def main():
    repo = os.environ.get("GITHUB_REPOSITORY", "")
    number = os.environ.get("PR_NUMBER", "")
    head = os.environ.get("EXPECTED_HEAD_SHA", "")
    validate_identity(repo, number, head)
    client = GitHubReadClient(repo, os.environ.get("GITHUB_TOKEN", ""))
    result = verify(client, int(number), head)
    print("Required PR gate PASS: " + json.dumps(result, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except GateError as error:
        print("Required PR gate FAIL: " + str(error), file=sys.stderr)
        sys.exit(1)
