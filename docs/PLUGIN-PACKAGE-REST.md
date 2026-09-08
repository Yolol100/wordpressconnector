# REST plugin package delivery

WordPress Connector supports custom/private plugin ZIP delivery through the existing authenticated HTTPS REST transport.

## Why this is a separate action

`plugin.install` remains the WordPress.org slug installer. `plugin.install_package` handles request-scoped ZIP packages so the existing public contract stays backwards compatible.

## Flow

1. Upload the package to `POST /wp-json/webactueel-wordpress-connector/v1/assets` as multipart form data.
   - `request_id`: the request identity used for the following execute call.
   - `asset_path`: must be `plugin-packages/<safe-name>.zip`.
   - `file`: the ZIP bytes.
2. Calculate the SHA-256 of the exact ZIP bytes before dispatch.
3. Call `POST /wp-json/webactueel-wordpress-connector/v1/execute` with action `plugin.install_package` and the same `request_id`.
4. Use payload fields:
   - `source_path`: same `plugin-packages/<safe-name>.zip` path;
   - `sha256`: lowercase 64-character SHA-256;
   - `expected_plugin`: exact main plugin path, for example `my-plugin/my-plugin.php`;
   - `overwrite`: `false` for a new install, `true` only for the same already-installed plugin identity;
   - `activate`: optional activation after verified install;
   - `network_wide`: optional multisite network activation.
5. Use `dry_run:true` first. A real mutation requires `confirm:true`.

## Runtime gates

The REST endpoint still requires HTTPS, authentication and `manage_options`. The semantic action additionally requires the connector privileged and system-update gates. A real non-dry-run mutation also requires the write gate and `confirm:true`.

WordPress capabilities are checked close to the package operation: `install_plugins`, `update_plugins` when overwriting, `activate_plugins` when activating, and `manage_network_plugins` for network-wide activation.

## Package validation

Before WordPress `Plugin_Upgrader` receives the ZIP, the connector verifies:

- request-scoped asset-root containment;
- `.zip` extension and maximum compressed package size;
- caller-supplied SHA-256 against the stored bytes;
- bounded entry count and bounded total uncompressed bytes;
- no absolute paths, traversal segments, backslash paths, NUL path bytes or symlink entries;
- exactly one top-level plugin directory;
- exactly one detectable main plugin file with a `Plugin Name` header;
- exact equality between the detected main plugin file and `expected_plugin`;
- no ambiguous overwrite or destination-directory collision.

The connector refuses to replace its own active runtime through `plugin.install_package`; connector releases continue through the repository/deployment path.

## Limits

Default package limit: 20 MiB compressed. The environment variable `WPCONNECTOR_MAX_PLUGIN_PACKAGE_BYTES` may lower that limit but is capped at 20 MiB. ZIP inspection also caps archive entries at 2,500 and total uncompressed data at 100 MiB.

Package installation/overwrite is intentionally non-rollbackable. Use staging first for a new package or a package whose upgrade behavior has not already been proven.