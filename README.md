# WordPress Connector

> **Portfoliostatus:** Actief ondersteunend · private Webactueel WordPress-runtimebridge

**Rol in het platform:** de standaard Webactueel-route laat deze private connector gecontroleerde request-PR's ontvangen vanuit de [Orchestrator](https://github.com/Yolol100/Orchestrator)-route. WordPress-runtimewrites vereisen expliciete goedkeuring, readback en domeinacceptatie; een aangeroepen workflow is geen geaccepteerd resultaat.

GitHub-controlled WordPress management bridge for the default flow:

`ChatGPT Webapp -> request PR -> secretless GitHub guard -> workflow_dispatch -> trusted main-branch ubuntu-latest executor -> HTTPS REST -> WordPress Connector -> WordPress -> result JSON -> GitHub -> ChatGPT`

No VPS or persistent GitHub Actions runner is required. GitHub provisions fresh `ubuntu-latest` runners automatically. WordPress is reached over HTTPS using a dedicated WordPress Application Password.

Local WP-CLI remains available as an optional maintenance and recovery transport.

## What it can manage

The connector discovers the actual WordPress runtime and exposes semantic actions for:

- posts, pages and registered custom post types;
- post meta, revisions and trash/restore;
- categories, tags, product taxonomies and registered custom taxonomies;
- Gutenberg/block content, including nested block patches;
- Elementor document JSON, nested elements, page settings, document creation and Theme Builder metadata;
- Elementor capability and usage inventory, including Core/Pro/add-on/theme widget provenance, versions when available, per-document usage counts, missing widget types and legacy/container/Atomic architecture signals;
- complete Elementor V3 Form widgets and V4 Atomic Form subtrees, including fields, labels, validation settings, submit actions, recipients, subject/message/from/reply-to/cc/bcc settings, success/error messages, styles and other runtime-supported form settings;
- WooCommerce products, variations, attributes and coupons through WooCommerce CRUD APIs;
- ACF fields and field groups through ACF APIs;
- media import, metadata, featured images, product galleries, site icon and custom logo;
- classic menus and menu locations;
- WordPress and multisite options/theme mods behind privileged gates;
- users, roles, plugins, themes, cron, rewrite/cache and multisite administration behind privileged/system-update gates;
- extension actions registered by other WordPress plugins through `wpconnector_register_actions`.

See [docs/ACTION-CATALOG.md](docs/ACTION-CATALOG.md), [docs/SCOPE.md](docs/SCOPE.md) and [docs/ELEMENTOR-FORMS.md](docs/ELEMENTOR-FORMS.md).

## Elementor inventory

`elementor.capabilities` reports what the active Elementor runtime currently registers. `elementor.inventory` additionally scans saved Elementor documents and correlates actual usage with that runtime inventory.

The inventory reports widget source (`elementor-core`, `elementor-pro`, `addon`, `theme` or `unknown`), source slug/name/version when available, instance and document counts, document IDs, unregistered widget types that are still present in saved Elementor data, and legacy/container/Atomic architecture usage. Scans are read-only and paginated with `limit`, `offset`, `has_more` and `next_offset`.

## Elementor forms

Use `elementor.form_capabilities` before generating or modifying a form. It reports the exact V3 Form controls and registered submit-action keys plus the active V4 `e-form*` Atomic types and their runtime Atomic schemas. The returned `schema_fingerprint` can be supplied to `elementor.form_upsert` to refuse stale writes after Elementor/Pro/add-on schema changes.

`elementor.form_inspect` returns complete form subtrees from a document. `elementor.form_upsert` accepts one complete V3 `widgetType=form` widget or V4 `elType=e-form` subtree and either inserts it into a document or fully replaces the existing form with the same element ID. The connector does not strip form settings: runtime-supported email content, recipients, fields, action configuration, styling and nested V4 atoms remain part of the supplied JSON.

V3 and V4 are deliberately not converted into each other automatically. V3 submit actions are validated against the active Form widget control options. V4 element types and typed `$$type/value` settings are validated against the active runtime, nested forms are rejected, exactly one V4 submit button is required, and success/error message structures are checked before Elementor's document save API is called. Every non-dry-run form mutation is read back exactly and restores the previous document snapshot on failure.

## Safety model

This is intentionally not a remote shell. There are no generic `eval`, arbitrary SQL, arbitrary filesystem write or arbitrary HTTP proxy actions.

Every action registers explicit security metadata: read-only/mutation, privileged, sensitive and system-update. Mutations default to dry-run and require `confirm: true`. Requests are idempotent, can use stale-state fingerprints and may create rollback snapshots.

The HTTPS transport adds these boundaries:

- the repository must be private;
- request PRs must come from the same repository and from the repository owner or one exact configured trusted actor;
- the PR-triggered workflow has no WordPress production secrets and can only validate the request and dispatch the trusted executor;
- the credentialed executor is a separate `workflow_dispatch` workflow run from `main`, and revalidates the PR, current head SHA, latest commit author, allowed paths, limits and request schema before using credentials;
- WordPress requires HTTPS, an authenticated user and `manage_options` for every connector REST endpoint;
- use a dedicated WordPress Application Password stored only in GitHub Secrets;
- write, privileged, sensitive and system-update gates are controlled in `Settings -> WordPress Connector` and default off except the REST transport itself;
- request JSON is capped at 256 KiB;
- request assets are capped at 10 files / 25 MiB total, max 20 MiB each, restricted to WordPress-allowed media extensions, and stored only in a request-scoped temporary directory;
- generated `results/**` commits do not retrigger execution.

## Start here

1. Install and activate `plugin/wordpressconnector` on the WordPress site.
2. Keep the GitHub repository private.
3. In WordPress, open `Settings -> WordPress Connector`. Keep sensitive/system-update gates off; enable writes and privileged actions only when required by an approved workflow.
4. Create a dedicated WordPress Application Password for the administrator/service account used by this connector.
5. Add GitHub repository variable `WPCONNECTOR_SITE_URL` with the canonical `https://` site URL.
6. Add GitHub repository secrets `WPCONNECTOR_REST_USERNAME` and `WPCONNECTOR_REST_APPLICATION_PASSWORD`.
7. Run `connector.discover` read-only.
8. Test dry-runs.
9. Perform a disposable staging write and rollback before broad production mutation use.
10. Test representative Gutenberg, Elementor, forms, WooCommerce, ACF and media round-trips.

Actual WordPress/Elementor/WooCommerce/ACF behavior remains `staging-first` until those runtime tests have been executed on the target environment.

## Optional local WP-CLI transport

The plugin still registers `wp wordpress-connector ...` commands when WP-CLI is present. This is useful for host-local recovery or diagnostics, but the default GitHub workflow no longer depends on a self-hosted runner, SSH, systemd or a continuously running process.
