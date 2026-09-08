#!/usr/bin/env python3
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import stat
import zipfile


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open('rb') as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b''):
            h.update(chunk)
    return h.hexdigest()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', required=True)
    parser.add_argument('--zip', dest='zip_path', required=True)
    parser.add_argument('--sbom', required=True)
    parser.add_argument('--source-commit', required=True)
    parser.add_argument('--source-date-epoch', type=int, required=True)
    args = parser.parse_args()

    source = Path(args.source).resolve()
    zip_path = Path(args.zip_path).resolve()
    sbom_path = Path(args.sbom).resolve()
    if not source.is_dir() or source.name != 'wordpressconnector':
        raise SystemExit('Source must be the wordpressconnector plugin directory.')
    if not all(c in '0123456789abcdef' for c in args.source_commit.lower()) or len(args.source_commit) != 40:
        raise SystemExit('source-commit must be a 40-character Git SHA.')

    files = []
    for path in sorted(source.rglob('*')):
        if path.is_symlink():
            raise SystemExit(f'Symlinks are forbidden in release packages: {path}')
        if path.is_dir():
            continue
        rel = path.relative_to(source).as_posix()
        if any(part in {'.git', '.github', 'tests', 'node_modules', '__pycache__'} for part in Path(rel).parts):
            raise SystemExit(f'Development-only path leaked into plugin source: {rel}')
        files.append((path, rel))
    if not files or not any(rel == 'wordpressconnector.php' for _, rel in files):
        raise SystemExit('Canonical plugin bootstrap is missing.')

    zip_path.parent.mkdir(parents=True, exist_ok=True)
    fixed_time = (1980, 1, 1, 0, 0, 0)
    with zipfile.ZipFile(zip_path, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path, rel in files:
            info = zipfile.ZipInfo(f'wordpressconnector/{rel}', date_time=fixed_time)
            info.create_system = 3
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (stat.S_IFREG | 0o644) << 16
            info.flag_bits = 0
            archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)

    bootstrap = (source / 'wordpressconnector.php').read_text(encoding='utf-8')
    import re
    match = re.search(r'^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$', bootstrap, re.MULTILINE)
    if not match:
        raise SystemExit('Plugin version could not be read.')
    version = match.group(1)

    created = dt.datetime.fromtimestamp(args.source_date_epoch, tz=dt.timezone.utc).replace(microsecond=0).isoformat().replace('+00:00', 'Z')
    package_id = 'SPDXRef-Package-WordPressConnector'
    sbom_files = []
    relationships = [{'spdxElementId': 'SPDXRef-DOCUMENT', 'relationshipType': 'DESCRIBES', 'relatedSpdxElement': package_id}]
    for index, (path, rel) in enumerate(files, start=1):
        file_id = f'SPDXRef-File-{index}'
        sbom_files.append({
            'SPDXID': file_id,
            'fileName': f'wordpressconnector/{rel}',
            'checksums': [{'algorithm': 'SHA256', 'checksumValue': sha256(path)}],
            'licenseConcluded': 'NOASSERTION',
            'licenseInfoInFiles': ['NOASSERTION'],
            'copyrightText': 'NOASSERTION',
        })
        relationships.append({'spdxElementId': package_id, 'relationshipType': 'CONTAINS', 'relatedSpdxElement': file_id})

    sbom = {
        'spdxVersion': 'SPDX-2.3',
        'dataLicense': 'CC0-1.0',
        'SPDXID': 'SPDXRef-DOCUMENT',
        'name': f'wordpressconnector-{version}',
        'documentNamespace': f'https://github.com/Yolol100/wordpressconnector/sbom/{args.source_commit}',
        'creationInfo': {'created': created, 'creators': ['Organization: Webactueel', 'Tool: scripts/build-release-package.py']},
        'packages': [{
            'name': 'WordPress Connector',
            'SPDXID': package_id,
            'versionInfo': version,
            'downloadLocation': 'NOASSERTION',
            'filesAnalyzed': True,
            'checksums': [{'algorithm': 'SHA256', 'checksumValue': sha256(zip_path)}],
            'licenseConcluded': 'GPL-2.0-or-later',
            'licenseDeclared': 'GPL-2.0-or-later',
            'copyrightText': 'NOASSERTION',
            'externalRefs': [{'referenceCategory': 'OTHER', 'referenceType': 'vcs', 'referenceLocator': f'https://github.com/Yolol100/wordpressconnector@{args.source_commit}'}],
        }],
        'files': sbom_files,
        'relationships': relationships,
    }
    sbom_path.parent.mkdir(parents=True, exist_ok=True)
    sbom_path.write_text(json.dumps(sbom, sort_keys=True, indent=2, separators=(',', ': ')) + '\n', encoding='utf-8')


if __name__ == '__main__':
    main()
