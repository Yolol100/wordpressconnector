# WordPress Connector

WordPress Connector is the canonical Webactueel bridge for controlled WordPress automation from approved HTTPS clients and the guarded GitHub runtime.

## Zero-config GitHub connection

From version 1.13.0, normal GitHub transport requires no repository secrets, no `WPCONNECTOR_SITE_URL` variable, no WordPress username, no Application Password and no WordPress settings checkboxes.

1. Install and activate the plugin on the WordPress site.
2. A known site can immediately be verified at `/wp-json/webactueel-wordpress-connector/v1/presence`.
3. A ChatGPT-created runtime request includes its canonical `site_url` in `requests/*.json`.
4. `wordpress-request.yml` validates the request without production credentials.
5. The trusted `wordpress-zero-config-execute.yml` workflow requests a short-lived GitHub Actions OIDC token.
6. WordPress verifies the JWT signature against GitHub's JWKS and binds it to repository ID `1341990468`, owner ID `22932777`, `main`, the exact executor workflow, the site-specific audience and a GitHub-hosted runner.
7. Request-scoped assets and the final connector request are sent through authenticated HTTPS REST using short-lived OIDC tokens.

The request itself remains strict: mutations require `confirm=true`; stale-state guards, capability checks, idempotency, readback and rollback remain active. Sensitive actions additionally require request-level confirmation and are blocked in public-repository mode.

## Discovery boundary

Installation makes a site immediately recognizable once its domain is known, but GitHub cannot securely enumerate arbitrary unknown WordPress domains merely because a plugin was installed. Full automatic “which sites have this plugin?” discovery requires a separate authenticated site registry/pairing service. The connector does not fake that capability with web crawling or public data leakage.

## Public vs private GitHub runtime

This repository is currently public. Public runtime requests remain deliberately restricted and only sanitized receipts may be committed. Full/private WordPress results require a private transport surface; they must never be written to a public branch, log or receipt.

## Direct HTTPS REST

Approved clients may still use WordPress-native authenticated REST sessions/Application Passwords. GitHub OIDC is the credential-free path for the canonical GitHub runtime, not a replacement for WordPress authentication standards used by other clients.

## Safety model

- HTTPS only.
- Exact GitHub OIDC issuer/signature/audience/repository/workflow/ref checks.
- No long-lived GitHub-to-WordPress credentials.
- WordPress `manage_options` plus action-specific capability checks.
- Dry-run by default; confirmed mutations only.
- Sensitive data requires request-level `confirm=true` and is blocked from public GitHub runtime.
- Public runtime returns sanitized receipts only.
- Request assets remain bounded to 10 files / 25 MiB total and are transported only after trusted request validation.
- Filesystem access remains bounded to approved non-secret paths and guarded writes.
- No arbitrary shell/eval execution.

## Main capabilities

The connector covers posts/pages/CPTs, Gutenberg, Elementor, WooCommerce products/variations/attributes/coupons, ACF, Yoast SEO, media, menus, taxonomies, Additional CSS, plugin settings, verified plugin package delivery, bounded plugin/theme filesystem work, system administration actions and canonical connector self-update.

`connector.discover` returns the site/runtime overview once a target site is authenticated. Elementor writes use Elementor document APIs rather than direct `_elementor_data` mutation.

## Validation

Run the canonical contract suite:

```bash
bash scripts/run-contracts.sh
```

Runtime acceptance still requires an isolated or staging WordPress installation. Static and controlled-runtime CI do not prove a production host.

See `docs/SETUP.md`, `docs/SECURITY.md`, `docs/ARCHITECTURE.md`, `docs/SCOPE.md` and `docs/ACTION-CATALOG.md`.
