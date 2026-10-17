# Isolated local WordPress smoke test (Docker)

This repository retains its existing secretless contract CI and guarded GitHub OIDC workflow. Docker is only an **additional, isolated runtime test**: never a production connector, GitHub transport replacement, or release authorization.

## Prerequisites

- Docker Engine/Desktop running locally, or a Linux GitHub-hosted runner.
- Node.js 24.18.0.
- `npm install --global @wordpress/env@11.17.0`

## Run

From repository root:

```sh
wp-env start
wp-env run cli wp plugin is-active wordpressconnector
wp-env run cli wp eval 'if (!defined("WPCONNECTOR_VERSION")) { WP_CLI::error("Connector did not boot"); }'
wp-env destroy --force
```

The configuration mounts only `plugin/wordpressconnector` into a disposable WordPress 7.1.2 / PHP 8.3 environment. The GitHub workflow also checks three expected REST routes. Four unauthenticated HTTP route probes must return 403. With HTTPS simulated **inside the isolated WP-CLI test only**, public `/presence` must return 200 while `/health`, `/assets`, and `/execute` require authentication (401). Simulated HTTPS is not a real network/TLS transport test.

## Evidence and limitations

- This proves only activation, basic WordPress runtime boot, route registration, and the anonymous HTTP denial in the disposable environment.
- The real presence endpoint is **HTTPS-only**. Do not weaken that check or claim the local HTTP test proves authenticated HTTPS access.
- This does **not** prove production WordPress connectivity, GitHub OIDC signatures, Elementor/WooCommerce integration, production writes, deployment, rollback, or release compatibility across all declared PHP and WordPress versions.
- No production credentials, contact data, site URL, or request payloads are needed. Test failures block this new runtime gate but must not silently change existing trusted executor permissions.
- Keep independent PHP-version, static contract, package integrity, security and deployment checks. Docker is not a replacement for them.
- Prefer the existing `@wordpress/env` tool over writing a custom application Dockerfile or creating a separate Docker repository.
