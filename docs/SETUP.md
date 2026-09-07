# Setup

## 1. Install WordPress Connector

Install `plugin/wordpressconnector` in `wp-content/plugins/wordpressconnector` and activate it.

The plugin registers:

- authenticated HTTPS REST under `/wp-json/webactueel-wordpress-connector/v1/`;
- the semantic action Registry and Runner;
- `Settings -> WordPress Connector` for execution gates;
- optional local `wp wordpress-connector ...` WP-CLI commands.

The REST surface is not a remote shell. It executes registered connector actions only.

## 2. Connect the site through WP Agent

WP Agent is the standard transport between ChatGPT and WordPress. Connect the site through WP Agent and use a dedicated WordPress Application Password/service account where practical.

The live architecture is:

`ChatGPT -> WP Agent -> WordPress REST -> WordPress Connector -> advanced action`

Do not configure GitHub repository secrets or request workflows for live WordPress execution. GitHub is used only for source control, CI and releases.

## 3. Configure WordPress security gates

Open `Settings -> WordPress Connector` as an administrator.

Recommended initial state:

| Gate | Initial value | Enable when |
| --- | --- | --- |
| HTTPS REST transport | on | required for remote connector access |
| confirmed writes | off | after read-only and dry-run verification |
| privileged actions | off | only for approved administrative actions |
| sensitive actions | off | only for explicitly approved sensitive workflows |
| system updates | off | only for approved plugin/theme/core lifecycle work |
| filesystem writes | off | only for approved bounded plugin/theme file replacement |

All REST endpoints additionally require HTTPS, an authenticated WordPress user and `manage_options`.

## 4. Acceptance sequence

1. Verify WP Agent can reach the site.
2. Verify Connector `/health` with the intended authenticated account.
3. Run `connector.discover` read-only.
4. Run `system.doctor` or the relevant read-only capability action.
5. Inspect representative Gutenberg, Elementor, WooCommerce and ACF content where applicable.
6. Run representative mutations as dry-runs.
7. On staging, enable only the write/privilege gate required for the test.
8. Perform one disposable mutation.
9. Verify exact readback.
10. Test rollback where the action supports it.
11. Repeat representative tests after the last code/configuration change.
12. Enable production mutation gates only after the intended target runtime passes.

## 5. Capability selection

Prefer WP Agent's native WordPress capability when it already does the job. Use WordPress Connector only for advanced capabilities such as Elementor JSON/forms, ACF, bounded filesystem access, advanced WooCommerce/plugin settings, rollback or privileged administration.

When WordPress or a plugin exposes a suitable Abilities API contract, prefer that native ability instead of duplicating it. `wordpress.abilities` can discover selected exposed abilities on supported WordPress runtimes.

## 6. Optional local WP-CLI recovery

If the hosting environment exposes WP-CLI, the plugin still supports local diagnostics/recovery commands. WP-CLI is optional and is not the default ChatGPT transport.

## 7. Operational rule

Keep broad writes staging-first. A successful REST response proves transport/runtime execution only; the originating domain skill and QA process still own acceptance of the actual website change.
