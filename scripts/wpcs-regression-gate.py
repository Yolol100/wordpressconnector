#!/usr/bin/env python3
"""Reject newly introduced WPCS findings by path, sniff and severity.

Existing, explicitly visible legacy findings remain in the archived audit.
No blanket sniff exclusions and no reliance on a single total error count.
"""
from __future__ import annotations

import argparse
from collections import Counter
import json
from pathlib import Path
import sys

ROOT = "plugin/wordpressconnector/"


def load_counts(path: Path) -> tuple[Counter, dict]:
    report = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(report, dict) or not isinstance(report.get("files"), dict) or not report["files"]:
        raise ValueError(f"Missing or empty PHPCS file list: {path}")
    totals = report.get("totals", {})
    if not isinstance(totals.get("errors"), int) or not isinstance(totals.get("warnings"), int):
        raise ValueError(f"Invalid PHPCS totals: {path}")
    counts: Counter = Counter()
    observed = Counter()
    for fullpath, entry in report["files"].items():
        if not isinstance(fullpath, str) or ROOT not in fullpath or not isinstance(entry, dict):
            raise ValueError(f"Unrecognized PHPCS file path: {fullpath}")
        relative = ROOT + fullpath.split(ROOT, 1)[1].replace("\\", "/")
        messages = entry.get("messages")
        if not isinstance(messages, list):
            raise ValueError(f"Missing PHPCS messages in: {relative}")
        for message in messages:
            if not isinstance(message, dict):
                raise ValueError("Malformed PHPCS message")
            severity, sniff = message.get("type"), message.get("source")
            if severity not in ("ERROR", "WARNING") or not isinstance(sniff, str) or not sniff:
                raise ValueError(f"Invalid PHPCS type or sniff: {relative}")
            counts[(relative, severity, sniff)] += 1
            observed[severity] += 1
    if observed["ERROR"] != totals["errors"] or observed["WARNING"] != totals["warnings"]:
        raise ValueError(f"PHPCS totals disagree with messages: {path}")
    return counts, totals


def compare(base_file: Path, current_file: Path) -> tuple[list[str], str]:
    base, b = load_counts(base_file)
    current, c = load_counts(current_file)
    additions = [
        (file, level, sniff, count - base[(file, level, sniff)])
        for (file, level, sniff), count in current.items()
        if count > base[(file, level, sniff)]
    ]
    additions.sort()
    message = (
        f"WordPress Coding Standards regression gate: baseline "
        f"{b['errors']} errors / {b['warnings']} warnings; "
        f"current {c['errors']} errors / {c['warnings']} warnings.\n"
    )
    if additions:
        message += "NEW PHPCS findings (source + severity + file, not merely total):\n"
        message += "\n".join(
            f"  +{extra}: {level} {sniff} in {file}"
            for file, level, sniff, extra in additions
        ) + "\n"
    else:
        message += "PASS: no new findings compared with the exact trusted base.\n"
    return [f"{file} {level} {sniff} +{extra}" for file, level, sniff, extra in additions], message


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("baseline", type=Path)
    parser.add_argument("current", type=Path)
    parser.add_argument("--summary", type=Path)
    args = parser.parse_args()
    try:
        errors, report = compare(args.baseline, args.current)
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"FAIL: invalid WPCS comparison evidence: {error}", file=sys.stderr)
        return 2
    print(report)
    if args.summary:
        with args.summary.open("a", encoding="utf-8") as file:
            file.write("### WPCS no-regression gate\n\n```\n" + report + "```\n")
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
