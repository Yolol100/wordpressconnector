#!/usr/bin/env python3
"""Reject newly introduced WPCS findings by path, sniff and severity.

Existing, explicitly visible legacy findings remain in the archived audit.
No blanket sniff exclusions and no reliance on a single total error count.
"""
from __future__ import annotations

import argparse
from collections import Counter
import json
import re
from pathlib import Path
import sys

ROOT = "plugin/wordpressconnector/"


def load_counts(path: Path, source_root: Path | None = None) -> tuple[Counter, dict]:
    report = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(report, dict) or not isinstance(report.get("files"), dict) or not report["files"]:
        raise ValueError(f"Missing or empty PHPCS file list: {path}")
    totals = report.get("totals", {})
    if not isinstance(totals.get("errors"), int) or not isinstance(totals.get("warnings"), int):
        raise ValueError(f"Invalid PHPCS totals: {path}")
    counts: Counter = Counter()
    observed = Counter()
    checked_lines = {}
    for fullpath, entry in report["files"].items():
        if not isinstance(fullpath, str) or ROOT not in fullpath or not isinstance(entry, dict):
            raise ValueError(f"Unrecognized PHPCS file path: {fullpath}")
        relative = ROOT + fullpath.split(ROOT, 1)[1].replace("\\", "/")
        messages = entry.get("messages")
        if not isinstance(messages, list):
            raise ValueError(f"Missing PHPCS messages in: {relative}")
        if source_root is not None and relative not in checked_lines:
            file_path = (source_root / relative).resolve()
            if not file_path.is_relative_to(source_root.resolve()) or not file_path.is_file():
                raise ValueError(f"PHPCS references a missing or unsafe source file: {relative}")
            checked_lines[relative] = file_path.read_text(encoding="utf-8").splitlines()
        for message in messages:
            if not isinstance(message, dict):
                raise ValueError("Malformed PHPCS message")
            severity, sniff = message.get("type"), message.get("source")
            if severity not in ("ERROR", "WARNING") or not isinstance(sniff, str) or not sniff:
                raise ValueError(f"Invalid PHPCS type or sniff: {relative}")
            # A simple count lets a new violation hide behind another fixed
            # violation of the SAME sniff in the SAME file. Bind each report
            # entry to its normalized offending source line instead.
            line_fingerprint = ""
            if source_root is not None:
                line_number = message.get("line")
                lines = checked_lines[relative]
                if not isinstance(line_number, int) or line_number <= 0 or line_number > len(lines):
                    raise ValueError(f"Invalid PHPCS source line in {relative}: {line_number}")
                line_fingerprint = re.sub(r"\\s+", " ", lines[line_number - 1].strip())
            counts[(relative, severity, sniff, line_fingerprint)] += 1
            observed[severity] += 1
    if observed["ERROR"] != totals["errors"] or observed["WARNING"] != totals["warnings"]:
        raise ValueError(f"PHPCS totals disagree with messages: {path}")
    return counts, totals


def compare(
    base_file: Path, current_file: Path,
    base_root: Path | None = None, current_root: Path | None = None,
) -> tuple[list[str], str]:
    if (base_root is None) != (current_root is None):
        raise ValueError("Both trusted base and candidate source roots are required together")
    base, b = load_counts(base_file, base_root)
    current, c = load_counts(current_file, current_root)
    additions = [
        (file, level, sniff, source, count - base[(file, level, sniff, source)])
        for (file, level, sniff, source), count in current.items()
        if count > base[(file, level, sniff, source)]
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
            f"  +{extra}: {level} {sniff} in {file}" +
            (f" on {source[:115]!r}" if source else "")
            for file, level, sniff, source, extra in additions
        ) + "\n"
    else:
        message += "PASS: no new findings compared with the exact trusted base.\n"
    return [f"{file} {level} {sniff} {source[:80]} +{extra}" for file, level, sniff, source, extra in additions], message


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("baseline", type=Path)
    parser.add_argument("current", type=Path)
    parser.add_argument("--summary", type=Path)
    parser.add_argument("--base-root", type=Path)
    parser.add_argument("--current-root", type=Path)
    args = parser.parse_args()
    try:
        errors, report = compare(
            args.baseline, args.current, args.base_root, args.current_root
        )
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
