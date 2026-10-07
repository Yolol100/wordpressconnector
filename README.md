# WordPress Connector

> **Portfoliostatus:** actief ondersteunend · gecontroleerde WordPress automation bridge · geen clientcase

WordPress Connector is the canonical Webactueel bridge for controlled WordPress automation from approved HTTPS clients, native MCP clients and the guarded GitHub runtime.

**Developer profile:** [Andrew Baeten](https://github.com/Yolol100) · [Portfolio cases](https://andrewbaeten.nl/category/cases)

## What it demonstrates

| Area | Implementation |
| --- | --- |
| WordPress automation | Capability-gated REST actions across content, media, WooCommerce, ACF and site administration |
| Authentication | Short-lived GitHub Actions OIDC plus standard authenticated WordPress REST/MCP clients |
| AI transport | Native bounded MCP endpoint over the existing semantic action registry; no second business-logic layer |
| Safety | Dry-run defaults, explicit confirmation, stale-state guards and bounded filesystem access |
| Reliability | Idempotency, readback, rollback and exact target validation |
| Security | HTTPS-only transport, strict JWT binding and sanitized public receipts |
| Architecture | Direct connector transport kept separate from generic multi-repository orchestration |

## Trusted private control plane

Version 1.17.2 also trusts the exact private `Yolol100/Wordpress` GitHub Actions executor. Because that repository is private, requests and full results stay off the public connector repository and the connector does not apply the public action allowlist. All registered actions still pass their normal confirmation, capability, idempotency, stale-state, readback/rollback, filesystem and sensitive-action gates.

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

This repository is currently public. Public runtime requests remain deliberately restricted and only sanitized receipts may be committed. Full/private results require a separate private transport surface and must never be written to this public branch, log, issue, artifact or receipt.

## Direct HTTPS REST

Approved clients may still use WordPress-native authenticated REST sessions/Application Passwords. GitHub OIDC is the credential-free path for the canonical GitHub runtime, not a replacement for WordPress authentication standards used by other clients.

## Native MCP transport

Version 1.17.1 hardens the native Ability bridge introduced in 1.17.0. Generic delegated reads remain available for client-exposed WordPress Abilities that explicitly declare `readonly=true` and `destructive=false`, with native validation/permissions plus bounded redacted output. Delegated writes are restricted to explicitly MCP-exposed native Elementor Core/Pro providers whose `execute_guarded` callback, exact provider ID and callback source prove ownership beneath an active canonical Elementor plugin root; names and categories are never treated as ownership proof. Explicit `mcp.public=false` remains authoritative. Completed writes with over-budget output return a bounded terminal success, while provider failures after execution starts become terminal request-ID outcomes so retries cannot blindly repeat the mutation.

Version 1.17.0 adds namespace-filtered WordPress Ability discovery and guarded Elementor Ability execution on private/direct authenticated transports. Native provider schemas, permissions and guards remain authoritative, and connector dry-run never invokes mutating Ability code.

For Elementor this closes the MCP capability gap without copying Elementor internals: the connector dynamically reuses exposed native `elementor/*` abilities for the installed runtime, including Atomic composition/element operations, global classes/variables, default styles, Components, interactions/resources, dynamic-tag resources, settings and publish flows when those abilities are actually available. Future/Pro capabilities such as Loops remain target-dependent and are exposed only when the installed Elementor stack registers them under the same Ability contract.

Version 1.16.1 adds guarded public-runtime Additional CSS inspection plus named managed CSS patches with dry-run fingerprinting, active-theme and `edit_css` gating, exact readback, rollback and sanitized public receipts. Full CSS replacement remains restricted to private/direct authenticated transports.

Version 1.16.0 adds an authenticated MCP endpoint at `/wp-json/webactueel-wordpress-connector/v1/mcp`. It is deliberately a thin transport over the existing Registry and Runner: MCP cannot bypass WordPress authentication, capabilities, dry-run, confirmation, stale-state guards, idempotency, readback or rollback.

The endpoint supports modern MCP `2026-07-28` discovery plus the legacy `2025-11-25` initialize flow and exposes only three bounded tools:

- `wordpress_connector_actions` — action/security catalog;
- `wordpress_connector_discover` — authenticated runtime discovery;
- `wordpress_connector_execute` — execute one existing semantic connector action.

Real writes require `dry_run=false`, `confirm=true` and an explicit stable `request_id`. This repository does not add a hosted multi-site account registry, billing/usage quotas, shared cloud skills or a background-job SaaS layer; those remain separate control-plane concerns.

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

The connector covers posts/pages/CPTs, Gutenberg, Elementor, WooCommerce products/variations/attributes/coupons, bounded order reads, per-customer reads, shipping-zone geography and tax-class/rate reads, ACF values plus guarded private/direct complex schema creation (new field groups, image/relationship/group/repeater fields and nested sub fields), Yoast SEO, media, menus, taxonomies, Additional CSS, plugin settings, verified plugin package delivery, bounded plugin/theme filesystem work, system administration actions and canonical connector self-update.

`connector.discover` returns the site/runtime overview once a target site is authenticated. Elementor writes use Elementor document APIs rather than direct `_elementor_data` mutation. Native Elementor MCP capabilities are additionally surfaced through the WordPress Abilities bridge instead of duplicating Elementor's Atomic/V4 business logic.

## Validation

Run the canonical contract suite:

```bash
bash scripts/run-contracts.sh
```

CI includes isolated MySQL-backed WordPress/WooCommerce runtimes, WordPress 6.9 Abilities API coverage, and a controlled WordPress 7.1 + Elementor 4.3.4 MCP runtime that exercises native ability discovery, trusted-provider checks, read execution, a disposable native create-page mutation, readback and same-request-ID replay. This still does not prove every production host, Elementor Pro entitlement, frontend render, custom data store, WAF, cache layer or third-party plugin combination.

See `docs/SETUP.md`, `docs/SECURITY.md`, `docs/ARCHITECTURE.md`, `docs/SCOPE.md` and `docs/ACTION-CATALOG.md`.
