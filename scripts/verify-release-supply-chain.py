#!/usr/bin/env python3
"""Fail-closed local release ZIP + SPDX file-parity verification.

This is artifact/inventory validation, not a live vulnerability database scan.
If runtime dependency manifests appear, require a separate locked SCA review.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import re
import sys
from zipfile import ZipFile, BadZipFile

FORBIDDEN = {".git", ".github", "tests", "node_modules", "__pycache__"}
MANIFESTS = {"composer.json": "composer.lock", "package.json": "package-lock.json"}
ROOT = "wordpressconnector/"
MAX_ZIP = 20 * 1024 * 1024
MAX_EXTRACT = 50 * 1024 * 1024


def sha(algorithm: str, data: bytes) -> str:
    return hashlib.new(algorithm.lower(), data).hexdigest()


def verify(package_path: Path, sbom_path: Path) -> str:
    if not package_path.is_file() or package_path.stat().st_size > MAX_ZIP:
        raise ValueError("ZIP missing or exceeds 20 MiB")
    sbom = json.loads(sbom_path.read_text(encoding="utf-8"))
    if sbom.get("spdxVersion") != "SPDX-2.3" or sbom.get("dataLicense") != "CC0-1.0":
        raise ValueError("Invalid SPDX schema version")
    if not isinstance(sbom.get("packages"), list) or len(sbom["packages"]) != 1:
        raise ValueError("Exactly one SPDX package is required")
    pkg = sbom["packages"][0]
    if pkg.get("filesAnalyzed") is not True:
        raise ValueError("SPDX package must have filesAnalyzed=true")
    pkg_hashes = {p.get("algorithm"): p.get("checksumValue") for p in pkg.get("checksums", [])}
    if pkg_hashes.get("SHA256") != sha("sha256", package_path.read_bytes()):
        raise ValueError("SPDX package digest differs from ZIP")
    entries = sbom.get("files")
    if not isinstance(entries, list) or not entries:
        raise ValueError("SPDX does not inventory plugin files")
    expected = {}
    for obj in entries:
        if not isinstance(obj, dict) or not isinstance(obj.get("fileName"), str):
            raise ValueError("Bad SPDX file entry")
        name = obj["fileName"]
        if name in expected or not name.startswith(ROOT):
            raise ValueError("Duplicate or non-plugin SPDX file")
        hashes = {x.get("algorithm"): x.get("checksumValue") for x in obj.get("checksums", [])}
        if not re.fullmatch(r"[0-9a-f]{40}", str(hashes.get("SHA1"))) or not re.fullmatch(r"[0-9a-f]{64}", str(hashes.get("SHA256"))):
            raise ValueError("Missing SPDX per-file SHA1 and SHA256")
        expected[name] = hashes
    if pkg.get("packageVerificationCode", {}).get("packageVerificationCodeValue") != sha("sha1", "".join(sorted(x["SHA1"] for x in expected.values())).encode("ascii")):
        raise ValueError("Invalid SPDX packageVerificationCode")
    total = 0
    try:
        with ZipFile(package_path) as archive:
            infos = archive.infolist()
            if len(infos) != len(expected) or len(infos) != len({x.filename for x in infos}):
                raise ValueError("ZIP entry count/duplicate differs from SPDX")
            for info in infos:
                name = info.filename
                if name not in expected or info.is_dir():
                    raise ValueError("Unexpected directory or unlisted ZIP entry: " + name)
                parts = Path(name).parts
                if any(p in FORBIDDEN or p in ("", ".", "..") for p in parts) or "\\" in name:
                    raise ValueError("Invalid or dev-only ZIP path: " + name)
                # ZIP symlinks must never be extracted or accepted as code files.
                if ((info.external_attr >> 16) & 0xF000) == 0xA000:
                    raise ValueError("Symlink entry forbidden in release ZIP: " + name)
                if info.file_size < 0 or info.file_size > MAX_ZIP:
                    raise ValueError("Oversized ZIP entry: " + name)
                total += info.file_size
                if total > MAX_EXTRACT:
                    raise ValueError("Excessive uncompressed package size")
                # Use ZipFile.open to avoid an unbounded read of declared oversized entries.
                with archive.open(info) as reader:
                    data = reader.read(info.file_size + 1)
                if len(data) != info.file_size:
                    raise ValueError("ZIP extraction size mismatch")
                if sha("sha1", data) != expected[name]["SHA1"] or sha("sha256", data) != expected[name]["SHA256"]:
                    raise ValueError("ZIP bytes differ from SPD[X] file inventory: " + name)
            source = archive.read(ROOT + "wordpressconnector.php").decode("utf-8")
    except (BadZipFile, KeyError, UnicodeDecodeError) as error:
        raise ValueError("Invalid plugin ZIP: " + str(error)) from error
    match = re.search(r"(?m)^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$", source)
    if match is None or pkg.get("versionInfo") != match.group(1):
        raise ValueError("Version mismatch between plugin source and SPDX")
    found = {Path(name).name for name in expected}
    for manifest, lock in MANIFESTS.items():
        if manifest in found and lock not in found:
            raise ValueError(f"Dependency manifest {manifest} has no matching locked bill of materials; require additional SCA")
        if manifest in found:
            raise ValueError(f"Dependency manifest {manifest} detected: enforce locked Composer/npm vulnerability audit before release")
    return f"PASS: ZIP & SPDX exact byte parity ({len(expected)} files), version {match.group(1)}; no packaged Composer/npm manifests (CVE scanning N/A)."


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("package", type=Path)
    parser.add_argument("sbom", type=Path)
    args = parser.parse_args()
    try:
        print(verify(args.package, args.sbom))
        return 0
    except (ValueError, OSError, TypeError, json.JSONDecodeError) as error:
        print(f"FAIL: release supply-chain verification: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
