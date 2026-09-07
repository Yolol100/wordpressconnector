# Setup

## 1. Install WordPress Connector

Install `plugin/wordpressconnector` on the WordPress site and activate it.

The plugin exposes authenticated HTTPS REST endpoints under:

`/wp-json/webactueel-wordpress-connector/v1/`

Important endpoints:
- `/health`
- `/execute`
- `/assets`

## 2. Connect the site with WP Agent

Use WP Agent to connect the WordPress site. WP Agent is the canonical remote transport from ChatGPT to WordPress.

The connector does not need GitHub Actions credentials for live execution.

## 3. Security gates

In `Settings -> WordPress Connector` keep all high-risk gates off until needed.

Recommended default:
- REST transport: on
- confirmed writes: off
- privileged actions: off
- sensitive actions: off
- system updates: off
- filesystem writes: off

All connector REST endpoints additionally require HTTPS, an authenticated WordPress user and `manage_options`.

## 4. First verification

Run in this order:
1. `/health`
2. `connector.actions`
3. `connector.discover`
4. the relevant read-only capability action
5. dry-run mutation
6. representative staging write + readback + rollback when the task has meaningful blast radius

## 5. GitHub

Use GitHub only for source control, CI, review and releases. Runtime requests/results do not belong in the repository.

## 6. Optional WP-CLI

WP-CLI remains an optional local recovery/diagnostics path when the hosting environment already provides it. It is not part of the normal ChatGPT-to-site transport.
