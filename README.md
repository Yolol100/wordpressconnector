# WordPress Connector

> **Portfolio status:** active supporting runtime bridge for advanced WordPress operations.

WordPress Connector is the advanced execution layer behind WP Agent for capabilities that WP Agent does not expose itself.

Default live route:

`ChatGPT -> WP Agent -> authenticated WordPress REST -> WordPress Connector -> Registry/Runner -> semantic adapter -> WordPress`

GitHub is no longer part of the live request transport. This repository is used for source control, review, CI and releases only.

## Responsibility split

- **ChatGPT / Webactueel skills** decide what should happen.
- **WP Agent** is the standard ChatGPT-to-WordPress transport and site connection layer.
- **WordPress Connector** exposes advanced, explicitly registered operations through authenticated REST.
- **WordPress** remains the source of truth for runtime state, authentication and authorization.
- **GitHub** manages code, tests and releases; it does not relay production requests.

## What the connector adds

The connector keeps advanced capabilities that are outside the normal WP Agent surface, including:

- Elementor document JSON, nested element patches, inventory, forms and Theme Builder metadata;
- ACF field values and field-group discovery;
- WooCommerce product/variation/attribute/coupon operations through WooCommerce APIs;
- Gutenberg tree inspection and patching;
- Yoast SEO/Premium advanced metadata;
- plugin-specific settings behind allowlists;
- media operations beyond the standard surface;
- bounded WordPress filesystem inspection and existing plugin/theme text-file replacement;
- privileged WordPress administration and system-update actions behind separate gates;
- batch execution, fingerprints, idempotency and rollback;
- discovery of selected WordPress Abilities API entries through `wordpress.abilities`.

The connector is deliberately **not** a remote shell. It does not expose arbitrary PHP, shell commands, SQL, unrestricted filesystem writes or a generic HTTP proxy.

See `docs/ACTION-CATALOG.md`, `docs/SCOPE.md`, `docs/SECURITY.md` and `docs/PLUGIN-CONTROL.md`.

## REST runtime

The plugin registers authenticated routes under:

`/wp-json/webactueel-wordpress-connector/v1/`

Main endpoints:

- `/health` — transport/version/gate readback;
- `/execute` — runs one validated semantic connector request;
- `/assets` — request-scoped media upload for actions that need a local temporary asset.

REST requires HTTPS, an authenticated WordPress user and `manage_options`. Use a dedicated WordPress Application Password for the WP Agent/site integration where practical.

Every semantic action is registered in the shared Registry and executed through the Runner. Mutations remain confirmation-gated and can use fingerprints, idempotency and rollback snapshots.

## WordPress Abilities API

The connector can discover selected WordPress Abilities API entries when the target WordPress runtime provides them. Abilities complement the connector registry; they do not replace advanced Elementor, ACF, filesystem, rollback or other connector-specific capabilities.

Prefer a native WordPress/WooCommerce/plugin Ability when it already provides the required safe contract. Keep a connector adapter when it adds a necessary execution, safety, compatibility or rollback capability.

## Setup

1. Install and activate `plugin/wordpressconnector` on the WordPress site.
2. Connect the site through WP Agent.
3. Keep WordPress Connector REST enabled.
4. Leave write, privileged, sensitive, system-update and filesystem-write gates off by default.
5. Verify `/health`, then run `connector.discover` and the relevant read-only action.
6. Test mutations as dry-runs first.
7. Test a disposable staging write + readback + rollback before broad production use.

See `docs/SETUP.md` for the exact runtime model.

## Development and release

The repository should contain only reusable source, tests and documentation. Runtime request/result files do not belong on `main`.

CI validates PHP syntax, security/runtime contracts and the installable plugin ZIP. GitHub Actions are development/release infrastructure only; they do not execute WordPress production requests.

Optional local WP-CLI remains available for diagnostics and recovery when the host provides it.

Actual WordPress/Elementor/WooCommerce/ACF/filesystem behavior remains staging-first until representative runtime tests have passed on the intended target environment.
