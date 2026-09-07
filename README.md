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
- local WordPress-admin Elementor JSON downloads for Elementor-built pages and posts; Saved Templates keep Elementor's native export and receive a connector fallback when needed;
- Elementor capability and usage inventory, including Core/Pro/add-on/theme widget provenance, versions when available, per-document usage counts, missing widget types and legacy/container/Atomic architecture signals;
- complete Elementor V3 Form widgets and V4 Atomic Form subtrees, including fields, submit actions, recipients, subject/message/from/reply-to/CC/BCC settings, success/error messages, styles and other runtime-supported settings;
- WooCommerce products, variations, attributes and coupons through WooCommerce CRUD APIs;
- ACF fields and field groups through ACF APIs;
- Yoast SEO/Premium per-content metadata and selected plugin settings through explicit adapters;
- media import, metadata, featured images, product galleries, site icon and custom logo;
- bounded WordPress filesystem inspection plus existing plugin/theme text-file replacement through `WP_Filesystem`;
- classic menus and menu locations;
- WordPress and multisite options/theme mods behind privileged gates;
- users, roles, plugins, themes, cron, rewrite/cache and multisite administration behind privileged/system-update gates;
- extension actions registered by other WordPress plugins through `wpconnector_register_actions`.

See [docs/ACTION-CATALOG.md](docs/ACTION-CATALOG.md), [docs/ELEMENTOR-FORMS.md](docs/ELEMENTOR-FORMS.md), [docs/PLUGIN-CONTROL.md](docs/PLUGIN-CONTROL.md) and [docs/SCOPE.md](docs/SCOPE.md).

## Elementor local JSON export

Elementor already exposes a native export action for Saved Templates (`elementor_library`). WordPress Connector restores equivalent one-click JSON download for Elementor-built **Pages** and **Posts** from their WordPress admin list row actions. If Elementor's native Saved Templates row action is absent for an otherwise editable Elementor template, the connector supplies the same local download action as a fallback.

The download is built from Elementor's document `get_export_data()` API and contains the standard import-oriented fields `content`, `page_settings`, `version`, `title` and `type`. It does not read raw `_elementor_data` for the export. A user must be allowed to edit the document and every download URL is protected by a document-specific WordPress nonce.

## Elementor inventory

`elementor.capabilities` reports what the active Elementor runtime currently registers. `elementor.inventory` additionally scans saved Elementor documents and correlates actual usage with that runtime inventory.

The inventory reports widget source (`elementor-core`, `elementor-pro`, `addon`, `theme` or `unknown`), source slug/name/version when available, instance and document counts, document IDs, unregistered widget types that are still present in saved Elementor data, and legacy/container/Atomic architecture usage. Scans are read-only and paginated with `limit`, `offset`, `has_more` and `next_offset`.

## Elementor forms

`elementor.form_capabilities` reads the target runtime before form authoring. For V3 it exposes the classic Form control schema plus the currently registered submit-action keys. For V4 it inventories the active `e-form*` Atomic types and their Atomic prop/config schemas. The returned `schema_fingerprint` can be supplied to a later write so a changed Elementor/Pro/add-on schema fails closed instead of applying stale JSON.

`elementor.form_inspect` returns complete V3/V4 form subtrees. `elementor.form_upsert` accepts one complete V3 `widgetType=form` widget or V4 `elType=e-form` subtree and inserts it or replaces the existing form with the same element ID. The supplied form JSON is preserved as a whole, so runtime-supported form fields, email content and routing, styling and future settings are not reduced to a small connector allowlist.

V3 and V4 stay separate. V3 submit actions are checked against the target Form widget. V4 typed `$$type/value` settings and registered Atomic element types are checked against the target runtime; nested forms, duplicate element IDs, invalid success/error message structures and anything other than exactly one submit button are rejected. A real write uses Elementor's document save API, exact form readback and automatic restoration of the previous document snapshot on failure.

## Controlled filesystem access

`filesystem.inspect`, `filesystem.list` and `filesystem.read_text` provide bounded inspection inside the WordPress root. Secret/dotfile paths, uploads, caches, backups, upgrade state and security logs are excluded from this raw-file surface.

`filesystem.write_text` is deliberately narrower than a browser file-manager UI. It only replaces an **existing** UTF-8 text file under `wp-content/plugins/*` or `wp-content/themes/*`. WordPress core, uploads and the connector's own runtime are never writable through this action. Real writes require the normal write gate plus the dedicated filesystem-write gate, `confirm:true` and a matching `expected_sha256`; PHP/INC and JSON are validated before write, then verified by SHA-256 readback and stored with rollback data.

When WP File Manager is installed, it remains a visual admin interface over the same files. The connector does not emulate WP File Manager's private AJAX/elFinder protocol; it uses WordPress' `WP_Filesystem` abstraction, so successful connector changes are naturally visible in File Manager.

## Safety model

This is intentionally not a remote shell. There are no generic `eval`, arbitrary SQL, unrestricted filesystem write or arbitrary HTTP proxy actions.

Every action registers explicit security metadata: read-only/mutation, privileged, sensitive and system-update. Mutations default to dry-run and require `confirm: true`. Requests are idempotent, can use stale-state fingerprints and may create rollback snapshots.

The HTTPS transport adds these boundaries:

- the repository must be private;
- request PRs must come from the same repository and from the repository owner or one exact configured trusted actor;
- the PR-triggered workflow has no WordPress production secrets and can only validate the request and dispatch the trusted executor;
- the credentialed executor is a separate `workflow_dispatch` workflow run from `main`, and revalidates the PR, current head SHA, latest commit author, allowed paths, limits and request schema before using credentials;
- WordPress requires HTTPS, an authenticated user and `manage_options` for every connector REST endpoint;
- use a dedicated WordPress Application Password stored only in GitHub Secrets;
- write, privileged, sensitive, system-update and filesystem-write gates are controlled in `Settings -> WordPress Connector`; high-risk gates default off;
- filesystem writes additionally require direct WordPress filesystem mode; FTP/SSH credentials are never supplied through GitHub;
- request JSON is capped at 256 KiB;
- request assets are capped at 10 files / 25 MiB total, max 20 MiB each, restricted to WordPress-allowed media extensions, and stored only in a request-scoped temporary directory;
- generated `results/**` commits do not retrigger execution.

## Start here

1. Install and activate `plugin/wordpressconnector` on the WordPress site.
2. Keep the GitHub repository private.
3. In WordPress, open `Settings -> WordPress Connector`. Keep sensitive/system-update/filesystem-write gates off; enable writes and privileged actions only when required by an approved workflow.
4. Create a dedicated WordPress Application Password for the administrator/service account used by this connector.
5. Add GitHub repository variable `WPCONNECTOR_SITE_URL` with the canonical `https://` site URL.
6. Add GitHub repository secrets `WPCONNECTOR_REST_USERNAME` and `WPCONNECTOR_REST_APPLICATION_PASSWORD`.
7. Run `connector.discover`, `system.doctor` and the relevant read-only capability action first.
8. Test dry-runs.
9. Perform a disposable staging write and rollback before broad production mutation use.
10. Test representative Gutenberg, Elementor/forms, WooCommerce, ACF, media and filesystem round-trips that match the intended production actions.

Actual WordPress/Elementor/WooCommerce/ACF/filesystem behavior remains `staging-first` until those runtime tests have been executed on the target environment.

## Optional local WP-CLI transport

The plugin still registers `wp wordpress-connector ...` commands when WP-CLI is present. This is useful for host-local recovery or diagnostics, but the default GitHub workflow no longer depends on a self-hosted runner, SSH, systemd or a continuously running process.
