# WordPress Connector

GitHub-controlled WordPress management bridge for the flow:

`ChatGPT Webapp -> GitHub request PR -> guarded GitHub Actions workflow -> self-hosted runner -> WP-CLI -> WordPress -> result JSON -> GitHub -> ChatGPT`

No MCP server, OpenAI API key or WordPress Application Password is required. The WordPress runtime is reached locally by WP-CLI on a self-hosted runner.

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

Every action registers explicit security metadata: read-only/mutation, privileged, sensitive and system-update. Mutations default to dry-run and require `confirm: true` plus the appropriate runner flags. Requests are idempotent, can use stale-state fingerprints and may create rollback snapshots.

The request workflow validates a request on a GitHub-hosted runner **before** any self-hosted WordPress runner is assigned. Fork PRs, unexpected paths and public-repository execution are rejected by default. Runtime request PRs may be submitted by the repository owner or, when explicitly configured, one exact `WPCONNECTOR_TRUSTED_REQUEST_ACTOR` such as the dedicated Webactueel GitHub App bot; that allowance does not bypass the other guards.

GitHub recommends avoiding self-hosted runners for public repositories. Make this repository private before connecting a production WordPress host. Public-runner execution requires an explicit override and remains intentionally restricted.

## Start here

1. Merge/install the connector code.
2. Make the repository private for a production runner.
3. Add a self-hosted Linux runner with label `wordpressconnector` on a host that can run WP-CLI against the target site.
4. Configure repository variables described in [docs/SETUP.md](docs/SETUP.md).
5. Run `connector.discover` read-only.
6. Test dry-runs.
7. Perform a disposable staging write and rollback.
8. Test representative Gutenberg, Elementor, WooCommerce, ACF and media round-trips.
9. Only then enable production writes.

Actual WordPress/Elementor/WooCommerce/ACF behavior remains `staging-first` until those runtime tests have been executed on the target environment.
