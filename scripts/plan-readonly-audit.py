#!/usr/bin/env python3
"""Prepare isolated, public-safe WordPress Connector read-only requests.

No authentication, dispatch, PR creation or site modification occurs here.
"""
import argparse
import json
import re
from pathlib import Path
from urllib.parse import urlsplit

READS = ("site.identity.inspect", "plugin.audit.inspect", "post.list",
         "connector.update.check", "maintenance.cache_capabilities", "acf.field_groups")

def canonical_site(value):
    try:
        url = urlsplit(value)
        port = url.port
    except ValueError:
        raise ValueError("Invalid HTTPS URL")
    if (url.scheme != "https" or not url.hostname or url.username or url.password
            or port not in (None, 443) or url.path not in ("", "/")
            or url.query or url.fragment or url.hostname.lower() == "localhost"
            or not re.fullmatch(r"[a-z0-9.-]+", url.hostname.lower())
            or ".." in url.hostname or url.hostname.startswith(".")
            or url.hostname.endswith(".")):
        raise ValueError("Expected a canonical public HTTPS domain without path or credentials")
    return "https://" + url.hostname.lower()

def plan(site, prefix, elementor_id=None):
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{7,50}", prefix):
        raise ValueError("Prefix must be 8-51 safe characters")
    actions = list(READS)
    if elementor_id is not None:
        if elementor_id <= 0:
            raise ValueError("Elementor post ID must be positive")
        actions.append("elementor.inspect")
    out = []
    for idx, action in enumerate(actions, 1):
        payload = {"post_type": "page", "page": 1, "per_page": 10} if action == "post.list" else (
            {"id": elementor_id} if action == "elementor.inspect" else {})
        out.append({"version": 1, "site_url": site, "request_id": f"{prefix}-{idx:02d}",
                    "action": action, "payload": payload, "dry_run": True, "confirm": False})
    return out

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True)
    parser.add_argument("--prefix", required=True)
    parser.add_argument("--output", required=True, help="Empty output directory")
    parser.add_argument("--elementor-id", type=int, default=None)
    args = parser.parse_args()
    try:
        site = canonical_site(args.site)
        requests = plan(site, args.prefix, args.elementor_id)
        directory = Path(args.output)
        if directory.exists() and any(directory.iterdir()):
            parser.error("Output directory must be empty; never overwrite request state")
        directory.mkdir(parents=True, exist_ok=True)
        for req in requests:
            (directory / (req["request_id"] + ".json")).write_text(
                json.dumps(req, indent=2) + "\n", encoding="utf-8")
    except (ValueError, OSError) as error:
        parser.error(str(error))
    print(f"Prepared {len(requests)} isolated read-only requests for {site}.")
    print("Use exactly one requests/*.json per PR. Keep open until the executor receipt.")
    print("Require receipt ok=true, then close without merging. Never bypass permission_gate.")

if __name__ == "__main__":
    main()
