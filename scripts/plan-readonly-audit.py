#!/usr/bin/env python3
"""Prepare isolated, public-safe WordPress Connector read-only requests.

No authentication, dispatch, PR creation or site modification occurs here.
"""
import argparse
import json
import ipaddress
import re
from pathlib import Path
from urllib.parse import urlsplit

READS = ("site.identity.inspect", "plugin.audit.inspect", "post.list",
         "connector.update.check", "maintenance.cache_capabilities")

def canonical_site(value):
    try:
        url = urlsplit(value)
        port = url.port
    except ValueError:
        raise ValueError("Invalid HTTPS URL")
    if (not isinstance(value, str) or any(ord(char) <= 32 or ord(char) == 127 for char in value)
            or url.scheme != "https" or not url.hostname or url.username or url.password
            or port not in (None, 443) or url.path not in ("", "/")
            or url.query or url.fragment or url.hostname.lower() == "localhost"
            or not re.fullmatch(r"[a-z0-9.-]+", url.hostname.lower())
            or ".." in url.hostname or url.hostname.startswith(".")
            or url.hostname.endswith(".")):
        raise ValueError("Expected a canonical public HTTPS domain without path or credentials")
    try:
        ipaddress.ip_address(url.hostname)
    except ValueError:
        pass
    else:
        raise ValueError("IP literals are not permitted")
    labels = url.hostname.lower().split(".")
    if (len(url.hostname) > 253 or len(labels) < 2 or any(
            not re.fullmatch(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?", label)
            for label in labels):
        raise ValueError("Expected a valid public DNS hostname")
    return "https://" + url.hostname.lower()

def plan(site, prefix, elementor_id=None, acf_post_id=None, page=1, per_page=25):
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]{7,50}", prefix):
        raise ValueError("Prefix must be 8-51 safe characters")
    if not isinstance(page, int) or page < 1 or not isinstance(per_page, int) or not 1 <= per_page <= 25:
        raise ValueError("Page must be positive and per_page must be 1-25 (public receipt limit)")
    actions = list(READS)
    if acf_post_id is not None:
        if acf_post_id <= 0:
            raise ValueError("ACF post ID must be positive")
        actions.append("acf.field_groups")
    if elementor_id is not None:
        if elementor_id <= 0:
            raise ValueError("Elementor post ID must be positive")
        actions.append("elementor.inspect")
    out = []
    for idx, action in enumerate(actions, 1):
        payload = {"post_type": "page", "page": page, "per_page": per_page} if action == "post.list" else (
            {"post_id": acf_post_id} if action == "acf.field_groups" else (
                {"id": elementor_id} if action == "elementor.inspect" else {}))
        out.append({"version": 1, "site_url": site, "request_id": f"{prefix}-{idx:02d}",
                    "action": action, "payload": payload, "dry_run": True, "confirm": False})
    return out

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True)
    parser.add_argument("--prefix", required=True)
    parser.add_argument("--output", required=True, help="Empty output directory")
    parser.add_argument("--elementor-id", type=int, default=None)
    parser.add_argument("--acf-post-id", type=int, default=None)
    parser.add_argument("--page", type=int, default=1, help="Page of published WordPress pages")
    parser.add_argument("--per-page", type=int, default=25, help="Published pages per receipt (1-25)")
    args = parser.parse_args()
    try:
        site = canonical_site(args.site)
        requests = plan(site, args.prefix, args.elementor_id,
                        args.acf_post_id, args.page, args.per_page)
        directory = Path(args.output)
        if directory.is_symlink():
            parser.error("Output directory must not be a symlink")
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
