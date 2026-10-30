#!/usr/bin/env python3
"""Build a non-executable, private-input release plan for enrolled WordPress sites."""

import argparse
import json
import os
import re
import sys
from pathlib import Path

REPOSITORY = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$")
PLUGIN = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.php$")
THEME = re.compile(r"^[a-z0-9][a-z0-9_.-]{0,79}$")
SITE_REF = re.compile(r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$")
VERSION = re.compile(r"^(?:0|[1-9][0-9]{0,7})(?:\.(?:0|[1-9][0-9]{0,7})){1,3}$")
SHA256 = re.compile(r"^[a-f0-9]{64}$")


class PlanError(ValueError):
    pass


def object_with_keys(value, required, optional=()):
    if not isinstance(value, dict) or set(value) - (set(required) | set(optional)):
        raise PlanError("Unexpected fields or invalid object in inventory.")
    if set(required) - set(value):
        raise PlanError("Inventory is missing required fields.")
    return value


def require_string(value, pattern):
    if not isinstance(value, str) or not pattern.fullmatch(value) or ".." in value:
        raise PlanError("Inventory contains an invalid identifier.")
    return value


def version_tuple(value):
    if not isinstance(value, str) or not VERSION.fullmatch(value):
        return None
    parts = tuple(int(part) for part in value.split("."))
    return parts + (0,) * (4 - len(parts))


def component_identity(kind, identity):
    if kind not in ("plugin", "theme"):
        raise PlanError("Unsupported release component type.")
    return require_string(identity, PLUGIN if kind == "plugin" else THEME)


def plan_release(inventory):
    object_with_keys(inventory, ("schema_version", "release", "sites"))
    if type(inventory["schema_version"]) is not int or inventory["schema_version"] != 1:
        raise PlanError("Unsupported fleet inventory schema version.")
    release = object_with_keys(
        inventory["release"], ("kind", "identity", "repository", "version", "sha256")
    )
    kind = release["kind"]
    identity = component_identity(kind, release["identity"])
    repository = require_string(release["repository"], REPOSITORY)
    target_version = release["version"]
    target_tuple = version_tuple(target_version)
    if target_tuple is None:
        raise PlanError("Target version must be stable numeric WordPress version metadata.")
    require_string(release["sha256"], SHA256)
    sites = inventory["sites"]
    if not isinstance(sites, list) or len(sites) > 500:
        raise PlanError("Fleet inventory must contain at most 500 sites.")

    candidates, skipped, blocked, seen = [], [], [], set()
    for site in sites:
        object_with_keys(site, ("site_ref", "authorized", "read_verified", "components"))
        site_ref = require_string(site["site_ref"], SITE_REF)
        if site_ref in seen:
            raise PlanError("Duplicate site reference.")
        seen.add(site_ref)
        if type(site["authorized"]) is not bool or type(site["read_verified"]) is not bool:
            raise PlanError("Site approval and readback states must be booleans.")
        components = site["components"]
        if not isinstance(components, list) or len(components) > 150:
            raise PlanError("Invalid site component list.")

        matches = []
        component_keys = set()
        for component in components:
            object_with_keys(
                component,
                ("kind", "identity", "repository", "version", "active"),
                ("available_version",),
            )
            component_kind = component["kind"]
            component_id = component_identity(component_kind, component["identity"])
            require_string(component["repository"], REPOSITORY)
            if type(component["active"]) is not bool:
                raise PlanError("Component active state must be boolean.")
            key = (component_kind, component_id)
            if key in component_keys:
                raise PlanError("Duplicate component identity on one site.")
            component_keys.add(key)
            if not isinstance(component["version"], str) or len(component["version"]) > 64:
                raise PlanError("Invalid installed version metadata.")
            available = component.get("available_version")
            if available is not None and (not isinstance(available, str) or len(available) > 64):
                raise PlanError("Invalid update offer metadata.")
            if key == (kind, identity):
                matches.append(component)

        if not site["authorized"] or not site["read_verified"]:
            blocked.append({"site_ref": site_ref, "reason": "authorization_or_read_unverified"})
            continue
        if not matches:
            skipped.append({"site_ref": site_ref, "reason": "not_installed"})
            continue
        component = matches[0]
        if not component["active"]:
            skipped.append({"site_ref": site_ref, "reason": "not_active"})
            continue
        if component["repository"] != repository:
            blocked.append({"site_ref": site_ref, "reason": "source_repository_mismatch"})
            continue
        installed = version_tuple(component["version"])
        if installed is None:
            blocked.append({"site_ref": site_ref, "reason": "installed_version_unverifiable"})
            continue
        if installed >= target_tuple:
            skipped.append({
                "site_ref": site_ref,
                "reason": "already_current" if installed == target_tuple else "newer_installed",
            })
            continue

        available = component.get("available_version")
        if available is not None and version_tuple(available) is None:
            blocked.append({"site_ref": site_ref, "reason": "update_offer_unverifiable"})
            continue
        if available == target_version:
            action = "plugin.update" if kind == "plugin" else "theme.update"
            mode = "native"
        elif available is not None:
            blocked.append({"site_ref": site_ref, "reason": "different_update_offer"})
            continue
        elif kind == "theme":
            blocked.append({"site_ref": site_ref, "reason": "active_theme_needs_native_update_offer"})
            continue
        else:
            action, mode = "plugin.install_package", "private_verified_package"

        candidates.append({
            "site_ref": site_ref,
            "installed_version": component["version"],
            "target_version": target_version,
            "action": action,
            "mode": mode,
            "requires_confirmation": True,
            "requires_live_readback": True,
        })

    for rows in (candidates, skipped, blocked):
        rows.sort(key=lambda entry: entry["site_ref"])
    return {
        "schema_version": 1,
        "release": release,
        "execution_enabled": False,
        "site_identity_is_self_reported": True,
        "candidates": candidates,
        "skipped": skipped,
        "blocked": blocked,
        "summary": {
            "candidates": len(candidates),
            "skipped": len(skipped),
            "blocked": len(blocked),
        },
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", required=True, help="Private inventory JSON, never commit this file.")
    parser.add_argument("--output", required=True, help="New private result file, created with mode 0600.")
    args = parser.parse_args()
    input_path, output_path = Path(args.input), Path(args.output)
    if input_path.resolve() == output_path.resolve():
        raise PlanError("Input and output must be different files.")
    with input_path.open("r", encoding="utf-8") as handle:
        data = json.load(handle)
    result = plan_release(data)
    descriptor = os.open(str(output_path), os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
        json.dump(result, handle, indent=2, sort_keys=True)
        handle.write("\n")
    s = result["summary"]
    print("Read-only plan: {} candidates, {} skipped, {} blocked.".format(
        s["candidates"], s["skipped"], s["blocked"]
    ))


if __name__ == "__main__":
    try:
        main()
    except (PlanError, ValueError, OSError, json.JSONDecodeError) as error:
        print("Fleet planning failed: {}".format(type(error).__name__), file=sys.stderr)
        sys.exit(1)
