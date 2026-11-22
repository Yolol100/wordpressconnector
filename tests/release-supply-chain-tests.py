#!/usr/bin/env python3
"""Synthetic ZIP+SBOM consistency and failure cases; no network or credentials."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
from zipfile import ZipFile

ROOT = Path(__file__).resolve().parents[1]
VERIFY = ROOT / "scripts" / "verify-release-supply-chain.py"


def digest(algorithm: str, data: bytes) -> str:
    return hashlib.new(algorithm, data).hexdigest()


def artifact(root: Path, files: dict[str, bytes]) -> tuple[Path, Path]:
    zipfile = root / "wordpressconnector.zip"
    sbomfile = root / "wordpressconnector.spdx.json"
    with ZipFile(zipfile, "w") as archive:
        for name, content in files.items():
            archive.writestr(name, content)
    entries = []
    checksums = []
    for name, content in files.items():
        s1, s256 = digest("sha1", content), digest("sha256", content)
        checksums.append(s1)
        entries.append({"fileName": name, "checksums": [
            {"algorithm": "SHA1", "checksumValue": s1},
            {"algorithm": "SHA256", "checksumValue": s256},
        ]})
    sbom = {"spdxVersion": "SPDX-2.3", "dataLicense": "CC0-1.0",
            "packages": [{"versionInfo": "1.20.12", "filesAnalyzed": True,
                          "checksums": [{"algorithm": "SHA256",
                                         "checksumValue": digest("sha256", zipfile.read_bytes())}],
                          "packageVerificationCode": {"packageVerificationCodeValue":
                              digest("sha1", "".join(sorted(checksums)).encode("ascii"))}}],
            "files": entries}
    sbomfile.write_text(json.dumps(sbom), encoding="utf-8")
    return zipfile, sbomfile


def run(files: dict[str, bytes], expected: int, reason: str, modify_sbom=None):
    with TemporaryDirectory(prefix="connector-sbom-test-") as td:
        package, sbom = artifact(Path(td), files)
        if modify_sbom is not None:
            j = json.loads(sbom.read_text())
            modify_sbom(j)
            sbom.write_text(json.dumps(j))
        result = subprocess.run(
            [sys.executable, "-I", "-B", str(VERIFY), str(package), str(sbom)],
            capture_output=True, text=True, check=False)
        if result.returncode != expected:
            raise AssertionError(f"{reason}: expected {expected} got {result.returncode}: {result.stdout} {result.stderr}")


trusted = {"wordpressconnector/wordpressconnector.php":
           b"<?php\n/**\n * Plugin Name: WordPress Connector\n * Version: 1.20.12\n */\n",
           "wordpressconnector/readme.txt": b"Stable tag: 1.20.12\n"}
run(trusted, 0, "trusted ZIP and complete SPDX")
run({**trusted, "wordpressconnector/vendor/composer.json": b'{"require": {"unsafe/library": "*"}}'},
    1, "unlocked dependency manifest")
run({**trusted, "wordpressconnector/tests/secret.php": b"test"},
    1, "dev-only file in ZIP")
bad_version = {**trusted, "wordpressconnector/wordpressconnector.php":
               trusted["wordpressconnector/wordpressconnector.php"].replace(b"1.20.12", b"1.20.13")}
run(bad_version, 1, "plugin version does not match SPDX")
run(trusted, 1, "spoofed checksum",
    lambda j: j["packages"][0]["checksums"][0].update(checksumValue="0" * 64))
run(trusted, 1, "spoofed source hash",
    lambda j: j["files"][0]["checksums"][1].update(checksumValue="0" * 64))
print("release ZIP/SPDX parity, version, dependency manifest and negative tamper tests OK")
