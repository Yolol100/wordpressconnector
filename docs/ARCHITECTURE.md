# Architecture and reference patterns

WordPress Connector combines established patterns rather than copying another project.

- **WordPress REST API + Application Passwords:** the default remote transport uses WordPress-native HTTPS authentication for external applications and keeps authorization inside WordPress.
- **GitHub-hosted runners:** GitHub provisions fresh `ubuntu-latest` virtual machines per job; no persistent runner or VPS is required.
- **Split credential boundary:** the PR-triggered workflow has no WordPress secrets. It can only validate a request and dispatch a separate executor explicitly from trusted `main`.
- **WP-CLI:** remains an optional local diagnostics/recovery transport with explicit command contracts and machine-readable output.
- **Semantic action registry:** both REST and WP-CLI call the same registry, policy layer, idempotency store, fingerprint guards and rollback engine.
- **Release hygiene:** runtime/generated request and result files stay outside the permanent product source and distributable plugin boundaries remain explicit.

## Default remote flow

`request PR -> secretless GitHub guard -> workflow_dispatch(ref=main) -> trusted GitHub-hosted executor -> authenticated HTTPS -> WordPress Connector REST controller -> shared Runner -> semantic adapter -> result JSON -> request branch`

The PR guard validates same-repository origin, trusted actor, private repository, allowed paths, request schema and request/asset limits without access to WordPress credentials.

The guard then dispatches `wordpress-execute.yml` using `ref=main`. The trusted executor independently fetches and validates the current PR, exact head SHA, base branch, latest commit author, changed paths, request limits and schema using validator code from trusted current `main`. The WordPress secrets are scoped only to later transport steps in this trusted workflow.

Immediately before `/execute`, the trusted workflow checks that the PR still points to the validated head SHA. After execution, the result is pushed only if the branch is still on that same SHA. If the branch moves after a mutation is transmitted, the existing stable request ID/idempotency contract permits safe reconciliation on a later rerun rather than assuming an unverified outcome.

WordPress independently requires HTTPS, a logged-in user and `manage_options` for the connector endpoints. Mutation/security gates remain WordPress-owned settings and are not controlled by request payloads.

## Asset flow

Files under `assets/inbox/` are uploaded to a request-scoped temporary directory. The REST asset controller enforces real HTTP upload provenance, WordPress-allowed media extensions, safe relative paths, per-file/total size limits and cleanup. `media.import` then applies the existing canonical path, MIME, extension and size checks before importing anything into the Media Library.

## State model

A request has one stable `request_id`, normalized payload and fingerprint. Mutating requests are idempotent. Optional `expected_fingerprint` prevents stale writes. A mutation may register a rollback snapshot; batches compensate completed operations in reverse order when a later operation fails.

## Runtime ownership

GitHub owns request transport and audit history. WordPress remains the source of truth for content/runtime state, authentication and authorization. The connector does not mirror the WordPress database into GitHub and does not store WordPress credentials in repository source or runtime request PRs.
