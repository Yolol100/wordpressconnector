# Setup

## 1. Repository

For any persistent self-hosted runner connected to a live WordPress host, use a **private** GitHub repository. The workflow blocks public-repository execution unless `WPCONNECTOR_ALLOW_PUBLIC_SELF_HOSTED=true` is deliberately configured.

## 2. Self-hosted runner

In GitHub: `Settings -> Actions -> Runners -> New self-hosted runner`.

Install the Linux runner on the WordPress host or a trusted management host with:

- PHP compatible with the site;
- WP-CLI;
- filesystem access to the WordPress installation;
- outbound HTTPS access to GitHub;
- a dedicated OS account with the minimum filesystem permissions needed;
- runner label `wordpressconnector`.

Do not run the runner as root.

## 3. Repository variables

Set under `Settings -> Secrets and variables -> Actions -> Variables`:

| Variable | Required | Default/recommendation |
| --- | --- | --- |
| `WP_CONNECTOR_WORDPRESS_PATH` | yes | absolute WordPress root containing `wp-load.php` |
| `WP_CONNECTOR_DEPLOY_PLUGIN` | no | `false` until staging preflight; `true` lets the trusted base commit deploy the plugin |
| `WPCONNECTOR_ALLOW_WRITES` | no | `false`; enable after staging write/rollback test |
| `WPCONNECTOR_ALLOW_PRIVILEGED` | no | `false`; enable only for administration workflows |
| `WPCONNECTOR_ALLOW_SENSITIVE` | no | `false`; keep off unless a private, explicitly approved sensitive adapter is required |
| `WPCONNECTOR_ALLOW_SYSTEM_UPDATES` | no | `false`; controls plugin/theme/core install/update/delete actions |
| `WPCONNECTOR_ALLOW_PUBLIC_SELF_HOSTED` | no | keep unset/false; public self-hosted execution is discouraged |

No WordPress credential or OpenAI API key is needed because WP-CLI operates inside the trusted WordPress runtime.

## 4. Install manually if auto-deploy is off

Copy `plugin/wordpressconnector` to `wp-content/plugins/wordpressconnector` and activate it. The plugin registers commands only in WP-CLI context and does not expose a public REST/AJAX endpoint.

Verify:

```bash
wp --path=/path/to/wordpress plugin activate wordpressconnector
wp --path=/path/to/wordpress wordpress-connector doctor
wp --path=/path/to/wordpress wordpress-connector actions
```

## 5. First request

Create a branch from `main`, add exactly one JSON file under `requests/`, and open a PR. Use `examples/discover.json` as the first test. Runtime request PRs may contain only:

- one `requests/*.json` file;
- optional files under `assets/inbox/`;
- generated `results/*.json`.

The guard job validates paths, author, repository visibility, request size and schema before the self-hosted job is scheduled.

## 6. Acceptance sequence

1. `connector.discover` read-only.
2. `system.doctor`/CLI doctor.
3. Read one published normal page.
4. Inspect one Gutenberg page.
5. Inspect one Elementor page/template.
6. Read one WooCommerce product and variation.
7. Read one ACF-backed item.
8. Import one disposable image on staging; set title/alt/caption/description; assign it; rollback/remove the test content.
9. Dry-run one post, Elementor, WooCommerce and ACF mutation.
10. Enable writes on staging and repeat with rollback.
11. Run the same representative tests twice after the last code/config change.
12. Enable production writes only after the target runtime passes.
