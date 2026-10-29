# Repository Hygiene Policy

`main` contains only reusable connector source, tests, documentation, CI and the guarded GitHub runtime transport.

The GitHub runtime transport may contain reusable validators and workflows on `main`, but production request payloads, generated full WordPress results, client exports, credentials, private runtime state and date-stamped debugging residue must never be merged into the implementation branch.

Target-, client- or project-specific executors, migration helpers and one-time cleanup scripts do not belong on canonical `main`. Keep them on temporary request branches or in the dedicated private/project control-plane repository, and remove them once their bounded purpose is complete.

Runtime request PRs are temporary transport branches only. They must be same-repository and repository-owner/configured-trusted-actor controlled, and they must be closed without merge after result collection.

Private repositories may contain the intended `requests/*.json`, bounded `assets/inbox/*` files and generated full `results/*.json` on the temporary runtime branch.

Public repositories use a reduced public-safe contract. Public request branches may contain only non-sensitive request data intended to be publicly visible, bounded public assets and generated `receipts/*.json`. Full WordPress responses are never committed in public mode. Public receipts contain only request identity, success/failure category, readback status and deterministic state fingerprints; they never contain before/after content, state tokens, rollback payloads or raw connector errors.

Public runtime requests are restricted to the explicit allowlists in `scripts/validate-public-request.php` and the WordPress `Security/Policy.php`; the older post-only list is not the full current contract. All allowed reads and narrowly scoped mutations still require matching payload validation, administrator/target capabilities and runtime permission checks. Public post-targeted writes remain restricted to published, non-password-protected content; secret-like payload keys, non-post public ACF targets and `expected_state_token` are rejected before the trusted executor runs. Confirmed mutations require `dry_run=false`, `confirm=true` and a preceding `expected_fingerprint` where the action contract requires one. Privileged, sensitive, package, plugin update and self-update operations remain subject to their separate explicit guards; public request branches must contain only nonsecret, disclosure-safe assets and sanitized receipts.

WordPress credentials belong only in GitHub Actions Secrets. Site URLs belong in GitHub Actions Variables. Do not commit passwords, application passwords, tokens, salts, private customer/order/patient data or WordPress configuration. GitHub Actions history and logs for a public repository are public, so trusted workflows must never print full connector responses or secret-bearing error bodies.

The GitHub runtime is an authenticated HTTPS client for WordPress Connector, not a second WordPress implementation. WordPress remains the source of truth for content and runtime state, and all requests still pass connector authentication, semantic security gates, idempotency, mutation locks, stale-state guards, readback and rollback.

Completion requires source/static validation plus separate target-runtime proof for any claim about actual WordPress or Elementor mutation behavior.
