# Architecture and reference patterns

WordPress Connector combines established patterns rather than copying another project.

- **WordPress REST API + Application Passwords:** the default remote transport uses WordPress-native HTTPS authentication for external applications and keeps authorization inside WordPress.
- **GitHub-hosted runners:** each request job receives a fresh `ubuntu-latest` virtual machine from GitHub; no persistent runner or VPS is required.
- **WP-CLI:** remains an optional local diagnostics/recovery transport with explicit command contracts and machine-readable output.
- **Semantic action registry:** both REST and WP-CLI call the same registry, policy layer, idempotency store, fingerprint guards and rollback engine.
- **10up-style release hygiene:** runtime/generated request and result files stay outside the permanent product source and distributable plugin boundaries remain explicit.
- **Git-hosted lifecycle workflows:** GitHub provides request transport and audit history, but request PRs are data channels only; no arbitrary code from a request branch is executed on WordPress.

## Default remote flow

`request PR -> GitHub guard -> fresh ubuntu-latest execute job -> authenticated HTTPS -> WordPress Connector REST controller -> shared Runner -> semantic adapter -> result JSON -> request branch`

The guard validates the same-repository origin, trusted actor, private repository, allowed paths, request schema and request/asset limits before the credentialed execute job runs.

The execute job authenticates with a dedicated WordPress Application Password. WordPress independently requires HTTPS, a logged-in user and `manage_options` for the connector endpoints. Mutation/security gates remain WordPress-owned settings and are not controlled by request payloads.

## Asset flow

Files under `assets/inbox/` are uploaded to a request-scoped temporary directory. The REST asset controller enforces relative safe paths, upload provenance, per-file/total size limits and cleanup. `media.import` then applies the existing canonical path, MIME, extension and size checks before importing anything into the Media Library.

## State model

A request has one stable `request_id`, normalized payload and fingerprint. Mutating requests are idempotent. Optional `expected_fingerprint` prevents stale writes. A mutation may register a rollback snapshot; batches compensate completed operations in reverse order when a later operation fails.

## Runtime ownership

GitHub owns request transport and audit history. WordPress remains the source of truth for content/runtime state, authentication and authorization. The connector does not mirror the WordPress database into GitHub and does not store WordPress credentials in the repository.
