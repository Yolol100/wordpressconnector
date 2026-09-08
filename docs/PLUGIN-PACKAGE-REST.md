# REST plugin package delivery

WordPress Connector supports custom/private plugin ZIP delivery through the existing authenticated HTTPS REST transport.

## Why this is a separate action

`plugin.install` remains the WordPress.org slug installer. `plugin.install_package` handles request-scoped ZIP packages so the existing action contract stays backwards compatible and no caller-controlled remote package URL is introduced.

`plugin.install_package` is deliberately excluded from the public GitHub runtime allowlist. A ZIP committed to a public request branch is public data, so custom/private packages must use direct authenticated REST or a private repository transport.

## Preconditions

- Use HTTPS and an authenticated WordPress administrator. A dedicated WordPress Application Password is the preferred REST credential.
- Real mutations require the connector write, privileged and system-update gates plus `confirm:true`.
- The WordPress user needs `install_plugins`; overwrite additionally needs `update_plugins`; activation needs `activate_plugins`; network activation needs `manage_network_plugins`.
- PHP `ZipArchive` must be available.
- Use staging first for a new or unproven package. Package install/overwrite is intentionally non-rollbackable.
- Do not place a private/custom package under `assets/inbox/` on a public GitHub branch.

## Flow

### 1. Upload the exact ZIP

POST multipart form data to:

`/wp-json/webactueel-wordpress-connector/v1/assets`

Fields:

- `request_id`: 8-100 safe characters, for example `plugin-package-20260908-001`;
- `asset_path`: exactly `plugin-packages/<safe-name>.zip`;
- `file`: the ZIP bytes.

Calculate SHA-256 over the exact ZIP bytes before dispatch and keep the lowercase 64-character checksum.

### 2. Dry-run the package

POST JSON to:

`/wp-json/webactueel-wordpress-connector/v1/execute`

```json
{
  "request_id": "plugin-package-20260908-001",
  "action": "plugin.install_package",
  "payload": {
    "source_path": "plugin-packages/acme-tools.zip",
    "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
    "expected_plugin": "acme-tools/acme-tools.php",
    "overwrite": false,
    "activate": true,
    "network_wide": false
  },
  "dry_run": true,
  "confirm": false
}
```

A successful dry-run verifies the stored bytes, archive structure and target state and returns `current_state_token` for stale-state protection.

Important: REST execute cleanup is fail-closed. A dry-run consumes and removes that request's uploaded assets. Re-upload the exact same ZIP with the same `request_id` and `asset_path` before the real execution. The SHA-256 must still match.

### 3. Re-upload, then confirm

After re-uploading the exact package, repeat the execute request with `dry_run:false`, `confirm:true` and pass the dry-run token as `expected_state_token`:

```json
{
  "request_id": "plugin-package-20260908-001",
  "action": "plugin.install_package",
  "payload": {
    "source_path": "plugin-packages/acme-tools.zip",
    "sha256": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
    "expected_plugin": "acme-tools/acme-tools.php",
    "overwrite": false,
    "activate": true,
    "network_wide": false
  },
  "dry_run": false,
  "confirm": true,
  "expected_state_token": "replace-with-current_state_token-from-dry-run"
}
```

The confirmed execute call also cleans its request assets after the attempt. If a retry is needed, upload the package again.

## Install versus overwrite

- New plugin: use `overwrite:false`. The exact plugin must not already be installed and its destination directory must not already exist.
- Existing custom/private plugin replacement: use `overwrite:true`. `expected_plugin` must already be installed and the caller must have `update_plugins`.
- `activate:true, network_wide:false` ensures normal activation.
- `activate:true, network_wide:true` ensures network activation on multisite, even if the plugin was already active only on the current site.
- WordPress Connector itself cannot be replaced through this generic action. Connector self-update uses the dedicated `connector.update.check` / `connector.update.apply` path pinned to canonical `Yolol100/wordpressconnector` releases.

## Package validation

Before WordPress `Plugin_Upgrader` receives the ZIP, the connector verifies:

- exact `plugin-packages/<safe-name>.zip` request path and request-scoped asset-root containment;
- `.zip` extension and maximum compressed package size;
- caller-supplied SHA-256 against the stored bytes;
- bounded entry count and bounded total uncompressed bytes;
- no absolute paths, Windows drive paths, traversal segments, backslash paths, NUL/control characters, duplicate archive paths or symlink entries;
- exactly one top-level plugin directory;
- exactly one detectable main plugin file directly inside that directory with a `Plugin Name` header;
- exact equality between that detected main plugin file and `expected_plugin`;
- no ambiguous overwrite or destination-directory collision;
- exact installed-plugin identity readback after WordPress finishes.

Nested PHP files are not treated as candidate main-plugin headers. This avoids rejecting legitimate packages that contain examples, tests or libraries with plugin-like comments below the top-level plugin directory.

## Limits

Default package limit: 20 MiB compressed. `WPCONNECTOR_MAX_PLUGIN_PACKAGE_BYTES` may lower that limit but is capped at 20 MiB. ZIP inspection caps archive entries at 2,500 and total uncompressed data at 100 MiB.
