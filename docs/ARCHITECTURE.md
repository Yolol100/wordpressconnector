# Architecture

WordPress Connector is the advanced execution layer behind WP Agent.

## Canonical flow

`ChatGPT -> WP Agent -> WordPress REST -> WordPress Connector -> WordPress/Elementor/ACF/WooCommerce`

WP Agent owns the remote transport to the connected WordPress site. The connector owns only capabilities that need a richer WordPress-side implementation than WP Agent provides directly.

GitHub is not part of the live request path. It is used only for source control, CI, review and releases.

## Runtime contract

The connector exposes authenticated HTTPS REST endpoints under `/wp-json/webactueel-wordpress-connector/v1/` and routes `/execute` through one shared semantic action registry and Runner.

Every connector action declares security metadata and is executed through the same WordPress-side policy, dry-run, confirmation, fingerprint, idempotency and rollback controls.

The connector remains deliberately capability-based rather than becoming a remote shell. Arbitrary PHP, shell/process execution, SQL, unrestricted filesystem writes and generic HTTP proxying remain excluded.

## Transport and authentication

WP Agent connects to WordPress and can call custom WordPress REST endpoints. Connector REST endpoints additionally require HTTPS, an authenticated WordPress user and `manage_options`.

The connector does not store WP Agent credentials and does not implement a second remote transport layer.

## Abilities direction

Where WordPress or an installed plugin exposes a stable WordPress Ability, prefer discovery and use of that supported capability over duplicating it in a bespoke adapter. The existing `AbilitiesAdapter` discovers selected exposed abilities and their schemas; connector-specific adapters remain appropriate for capabilities that are not sufficiently covered upstream.

## State and write safety

A connector request has a stable `request_id`, normalized payload and fingerprint. Mutations are idempotent. Optional `expected_fingerprint` prevents stale writes. Supported mutations can store rollback snapshots, and batches compensate completed operations in reverse order when a later operation fails.

WordPress is the source of truth for runtime state, authentication and authorization.

## Assets

The connector retains its bounded `/assets` endpoint because some advanced connector actions, such as controlled media import, require request-scoped binary input that cannot be represented by the JSON `/execute` body alone. Asset storage enforces WordPress MIME rules, safe relative paths, size limits and cleanup.

## Ownership

- WP Agent: ChatGPT-to-WordPress transport.
- WordPress Connector: advanced WordPress execution, safety and rollback.
- Domain Skills: decide what should change.
- GitHub: code, CI, review and release evidence only.
