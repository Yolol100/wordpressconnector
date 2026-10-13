# Guarded cache maintenance

## What is already supported

The WordPress Connector exposes `maintenance.cache_capabilities` (read-only)
and `maintenance.cache_flush` (one scoped, authenticated cache operation) for
`elementor`, `wp_rocket` and `asset_cleanup`. The private/direct connector
also exposes `cache.flush` for the WordPress object cache. This is a distinct,
privileged operation: do not replace it with an unrestricted public "flush all"
action.

A client or GitHub transport that rejects a request *before* an authenticated
`/execute` response has **not** proved a WordPress-side permission failure.
Classify the boundary first: client/tool policy, GitHub PR/workflow validation,
OIDC verification, WordPress capability/policy, provider API or post-execution
readback. Never weaken an unrelated security boundary to address an upstream
tool-policy denial.

## Elementor CSS regeneration

By default, `maintenance.cache_flush` with `{"layer":"elementor"}` clears the
Elementor generated files/data through the Elementor provider API. New CSS files
may be created on the next frontend page request. A cache-clear hook is **not**
proof that any particular public asset URL returned HTTP 200.

For Elementor only, callers may now explicitly opt in to regeneration with
`{"layer":"elementor","regenerate":true}`. The connector first verifies that
the Elementor `generate_css()` provider API is callable, then uses that API
after a successful clear. No arbitrary filesystem operations are exposed. A
successful invocation still requires separate frontend CSS HTTP checks.

The `regenerate` flag must be a JSON boolean. It is forbidden for other cache
providers when true. Omitting the flag preserves existing behavior.

## Safe request lifecycle

1. Verify the exact known site, connector presence and intended cache provider.
2. Inspect capabilities and confirm that the provider API is available.
3. Submit `maintenance.cache_flush` as `dry_run=true, confirm=false`, with
   the requested layer and optional Elementor regeneration flag.
4. Read the fingerprint from the dry-run result/receipt. If provider state
   changed, repeat the dry-run. Never guess a fingerprint.
5. Submit a **new** stable request ID with `dry_run=false, confirm=true`,
   the same payload and the captured expected fingerprint, through an
   authorized transport. Preserve OIDC, trusted-actor, site capability,
   idempotency and public/private boundaries.
6. Confirm the provider completion signal and inspect `verification_scope`,
   `regenerate_requested` and `regenerate_invoked`. A cache clear has
   `rollback_supported=false`.
7. Request affected frontend pages, check the actual CSS URLs/status codes,
   inspect rendering, and rerun relevant QA/Ahrefs checks. In WooCommerce,
   confirm that product pages, cart and checkout are unaffected.

`readback_verified=true` on cache maintenance means the provider completion
signal was observed, **not** that the frontend was rebuilt or all assets exist.
The public receipt deliberately contains only bounded, non-sensitive fields.
Do not publish WordPress secrets, full configuration or site-private responses
in public GitHub request branches.

## Failure handling

- **Tool-side write blocked**: no WordPress execution occurred. Use an approved
  client/surface; do not relax connector policies or simulate success.
- **Missing fingerprint/stale fingerprint**: repeat the dry-run; do not bypass.
- **Provider missing**: verify the plugin is active and the provider method is
  available. Do not delete cache files manually.
- **Provider completion unverified**: fail the operation; inspect server logs
  privately, then re-check the provider state before retrying.
- **CSS returns 404 after regeneration**: identify its generating document,
  check upload permissions/caching/rewrite and whether the frontend references
  a stale version. Do not replace real CSS with an empty placeholder.
- **WordPress object cache**: use the separate privileged `cache.flush` only
  when required; persistent object caches may be shared across sites.
