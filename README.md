# WordPress Connector

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
- WooCommerce products, variations, attributes and coupons through WooCommerce CRUD APIs;
- ACF fields and field groups through ACF APIs;
- media import, metadata, featured images, product galleries, site icon and custom logo;
- classic menus and menu locations;
- WordPress and multisite options/theme mods behind privileged gates;
- users, roles, plugins, themes, cron, rewrite/cache and multisite administration behind privileged/system-update gates;
- extension actions registered by other WordPress plugins through `wpconnector_register_actions`.

See [docs/ACTION-CATALOG.md](docs/ACTION-CATALOG.md) and [docs/SCOPE.md](docs/SCOPE.md).

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
10. Test representative Gutenberg, Elementor, WooCommerce, ACF and media round-trips.

Actual WordPress/Elementor/WooCommerce/ACF behavior remains `staging-first` until those runtime tests have been executed on the target environment.

## Optional local WP-CLI transport

The plugin still registers `wp wordpress-connector ...` commands when WP-CLI is present. This is useful for host-local recovery or diagnostics, but the default GitHub workflow no longer depends on a self-hosted runner, SSH, systemd or a continuously running process.
