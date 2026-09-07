# WordPress Connector

Private Webactueel WordPress execution layer for advanced actions behind WP Agent.

## Canonical flow

`ChatGPT -> WP Agent -> WordPress REST -> WordPress Connector -> WordPress`

WP Agent is the remote transport. GitHub is only for source control, CI, review and releases.

## Why this plugin exists

Keep this connector only for capabilities that WP Agent or WordPress core do not already cover well enough, including:

- advanced Elementor document, form and capability operations;
- ACF field and field-group operations;
- WooCommerce product/variation/attribute/coupon operations;
- Yoast-specific metadata beyond generic WordPress fields;
- bounded filesystem inspection and controlled plugin/theme text-file replacement;
- advanced WordPress administration and plugin settings;
- rollback, idempotency, stale-state fingerprints and batch compensation;
- selected WordPress Abilities discovery.

Do not add a second transport, orchestration layer or generic remote shell here.

## REST surface

The plugin exposes authenticated HTTPS endpoints under:

`/wp-json/webactueel-wordpress-connector/v1/`

Core endpoints:

- `/health` — connector status and enabled security gates.
- `/execute` — execute one registered semantic connector action.
- `/assets` — bounded request-scoped media input for actions such as `media.import`.

All endpoints require HTTPS, an authenticated WordPress user and `manage_options`.

## Safety

The connector is intentionally not a remote shell. It does not expose arbitrary PHP, shell/process execution, SQL, unrestricted filesystem access, generic HTTP proxying or secret export.

Mutations default to dry-run and require explicit confirmation plus the relevant WordPress-side gates. Supported writes use readback, idempotency, stale-state protection and rollback data.

Keep sensitive, system-update and filesystem-write gates disabled unless the current approved task genuinely requires them.

## Main capabilities

See `docs/ACTION-CATALOG.md` for the exact runtime action list.

Important groups include:

- WordPress core/content and Gutenberg;
- Elementor and Elementor Forms;
- ACF;
- WooCommerce;
- Yoast SEO;
- media;
- plugin settings;
- controlled filesystem operations;
- menus, users, plugins, themes, cron, cache and multisite administration.

## WordPress Abilities

Prefer stable native WordPress/plugin Abilities when they already provide the required capability. `wordpress.abilities` discovers selected exposed abilities and schemas. Keep a connector adapter only when upstream capabilities do not provide the required safe operation or rollback/readback contract.

## Development

Repository structure is intentionally small:

```text
wordpressconnector/
├── .github/
│   └── workflows/
│       └── ci.yml
├── plugin/
│   └── wordpressconnector/
├── tests/
├── docs/
├── README.md
├── AGENTS.md
└── REPOSITORY-HYGIENE-POLICY.md
```

Runtime request/result files do not belong on `main`.

## Acceptance

CI proves source/package contracts only. Before broad production mutations, verify the installed target runtime read-only, use dry-run, then test representative writes and rollback on staging when the change has meaningful blast radius.
