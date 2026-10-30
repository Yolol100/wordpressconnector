# WordPress Connector

> **Portfoliostatus:** actief ondersteunend · gecontroleerde WordPress automation bridge · geen clientcase

WordPress Connector is the canonical Webactueel bridge for controlled WordPress automation from approved HTTPS clients, native MCP clients and the guarded GitHub runtime.

**1.20.5 candidate:** fail-closed mutation reservations, verified rollback-state persistence and atomic database-backed GitHub OIDC replay claims; **not yet published or deployed**. A pending/unknown operation must be reconciled by an administrator, never automatically retried. No hosted OAuth or fleet deployment service is introduced.

**1.20.4 candidate:** source-only release-preflight improvements and pinned native plugin/theme updates; no automatic deployment or direct ChatGPT OAuth. Full release still requires GitHub CI, explicit publish and site-level acceptance.

**1.20.3 candidate:** critical media permissions/rollback hardening and exact bulk matching for known Elementor export suffixes, on top of Elementor-normalized readback verification, administrator preview/confirmation for safe multi-JSON import, and guarded media.rename for SEO-friendly filenames with old URLs retained. Existing multi-operation requests preflight registered action-level permissions and request shape before any change, and recheck authorization when each operation executes; private `connector.setup.inspect` now exposes read-only permission and server-policy indicators. Batch compensation is best effort, not atomic, and a private site registry, ChatGPT OAuth linking, hosting backup/restore and connector self-removal still require separate authorized infrastructure.

**Developer profile:** [Andrew Baeten](https://github.com/Yolol100) · [GitHub project index](https://github.com/Yolol100?tab=repositories)

**1.19.8 audit hardening:** WordPress plugin activation follows native activation hooks; WordPress.org plugin and theme installs reject noncanonical download URLs, including filtered API substitutions. Browser-active HTML/JavaScript/CSS file creation and deletion require explicit risk acknowledgement and independent readback. A disposable Docker WordPress test exercises real package installation and lifecycle; production hosts still require staging acceptance. Private site enumeration and unrestricted public_html access are not available through this public GitHub transport.

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

The canonical `Yolol100/wordpressconnector` GitHub OIDC executor is bound to its exact repository ID, owner ID, trusted workflow on `main`, site-specific audience and short-lived, non-replayed GitHub token. It can operate in either GitHub visibility mode: signed `repository_visibility=private` activates the existing guarded private policy; signed `repository_visibility=public` keeps the restrictive public action allowlist and sanitized-receipt behavior. This is not a request-selectable mode and does **not** weaken confirmation, capability, stale-state, rollback or sensitive-action checks.

The legacy `Yolol100/Wordpress` private trust entry is separately pinned to its own repository ID and workflow and **never** accepts a public visibility claim. The separate repository is not required for canonical connector operation.

**Security and lifecycle:** switching any repository that has stored private request/result branches, workflow artifacts or logs back to public may expose historical client information. Do **not** change private to public until a documented security audit removes sensitive history and verifies exposure risks. Do not publish private request results in public branches or logs. This code change does not update an already installed WordPress plugin by itself; deploy a verified plugin release to each site using an authorized update path before expecting private OIDC to work.

## Guarded fleet release preflight (no automatic deployment)

The canonical private WordPress API already exposes single-site `plugin.update` and `theme.update`. Both now accept optional `expected_target_version` for pinned native update offers and post-upgrade version readback. For multi-site release preparation, the repository includes an offline, non-executing `scripts/plan-fleet-release.py`, covered by the canonical contract tests. It operates only on an independently authorized private inventory. See `docs/PRIVATE-SITE-REGISTRY-ROADMAP.md`.

This is **not** direct ChatGPT OAuth, site registration, a hosted private control plane, GitHub-to-website deployment or proof of a usable Hostinger backup. No automatic site updates, hosting changes, backups or staging environments are created.

## Native WordPress update notifications

Starting with release 1.19.0, the plugin declares a canonical GitHub `Update URI` and offers newer, validated releases to WordPress Core through the native **Plugins → Update Now** flow. Only this plugin's updates are handled. The Core upgrader receives a temporary ZIP after the existing trusted GitHub release, SHA-256 and archive identity checks succeed; invalid or unavailable releases fail closed.

The existing guarded `connector.update.check` and `connector.update.apply` actions remain independent for already-installed older versions. **An installation still on 1.18.9 (`Update URI: false`) requires one supervised upgrade to 1.19.0 before native dashboard notices are enabled.** Manual site backups and a tested restore are recommended; WordPress Core's temporary backup protects only supported installation failures, not defects after a successful update.

## Zero-config GitHub connection

Starting with version **1.19.6**, normal WordPress administrator **activation** is the only step needed to connect a new installation to the pinned Yolol100 GitHub OIDC workflow; there is no second pairing button. The activation is a deliberate grant of WordPress site administration to that exact repository, limited by WordPress capability checks and the connector public-safe action contract. The URL-bound and revocable site pairing added in 1.19.5 remains intact. Unattended WP-CLI/network activation, previously unpaired upgrades and site clones are not silently approved; use Settings → WordPress Connector to connect those cases. No long-lived credentials are required.

1. Install and activate the plugin on the WordPress site.
2. Presence can be checked at `/wp-json/webactueel-wordpress-connector/v1/presence`, **but this does not prove authorization**. After normal administrator activation, verify a trusted read-only GitHub OIDC request before claiming access. Other install modes can be connected in Settings → WordPress Connector.
3. A ChatGPT-created runtime request includes its canonical `site_url` in `requests/*.json`.
4. `wordpress-request.yml` validates the request without production credentials.
5. The trusted `wordpress-zero-config-execute.yml` workflow requests a short-lived GitHub Actions OIDC token.
6. WordPress verifies the JWT signature against GitHub's JWKS and binds it to repository ID `1341990468`, owner ID `22932777`, `main`, the exact executor workflow, the site-specific audience and a GitHub-hosted runner.
7. Request-scoped assets and the final connector request are sent through authenticated HTTPS REST using short-lived OIDC tokens.

The request itself remains strict: mutations require `confirm=true`; stale-state guards, capability checks, idempotency, readback and rollback remain active. Sensitive actions additionally require request-level confirmation and are blocked in public-repository mode.

## Discovery boundary

Installation makes a plugin presence endpoint recognizable once its domain is known, but GitHub cannot securely enumerate arbitrary unknown WordPress domains merely because a plugin was installed. Presence does not imply permission to make changes. Full automatic “which sites have this plugin?” discovery requires a separate authenticated site registry/pairing service. The connector does not fake that capability with web crawling or public data leakage.

## Public vs private GitHub runtime

The same canonical connector repository supports both modes, but never simultaneously. GitHub's signed `repository_visibility` determines the WordPress policy. Public runs are restricted to a reviewed action allowlist with sanitized receipts; private runs may access more registered actions only with the usual authorization, role, dry-run, confirmation and sensitive-data gates. Private GitHub-hosted runners can consume the account's Actions allowance.

GitHub OIDC requires **no WordPress Application Password**. A directly connected REST/MCP client may use a separately revocable WordPress Application Password over HTTPS; never commit that credential to GitHub or store it in request files.

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
