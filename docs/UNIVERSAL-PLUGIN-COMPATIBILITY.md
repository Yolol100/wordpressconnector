# Universal WordPress plugin compatibility (1.18.0)

WordPress Connector has a single authenticated semantic action bridge. It does not treat arbitrary WordPress REST routes or third-party PHP functions as safe to call. Some plugins have no stable public API, and even a GET route can have side effects.

## Existing extension points

- `connector.discover` lists WordPress capabilities and connector actions.
- `wordpress.abilities` and `wordpress.ability.read` discover/read explicitly exposed annotated WordPress Abilities, with native permission and schema checks. Delegated mutations remain restricted to vetted Elementor providers.
- `plugin.settings.catalog` describes installed plugins and supported actions. Its optional `registered_action_status` reports whether declared adapter action IDs are actually registered, not whether an external API is healthy.
- `wpconnector_register_actions` lets trusted WordPress plugins register narrowly scoped semantic actions in the existing Registry and permission boundary, rather than patching the Connector itself.

## New private read-only REST discovery

`connector.rest_catalog` inventories route patterns and methods of registered WordPress REST namespaces. Requires authenticated `manage_options`, the privileged-action policy and private transport. It is *not* allowed through the public GitHub repository.

Optional payload: `{ "namespace": "vendor/v1", "page": 1, "per_page": 20 }`; maximum 25 records per page. Output includes only namespace, WordPress route-regex pattern, advertised HTTP methods and `execution_enabled=false`.

- Does not dispatch any endpoint or endpoint permission callback, outbound request or arbitrary provider action. WordPress Core's `rest_endpoints` **filters may execute** while retrieving the route map.
- Does not return handler callbacks, argument schemas, default values, user content or credentials.
- Rejects unsafe filters, control characters, opaque token-like route strings and route names hinting at auth, customers, payments, orders or other sensitive data.
- Does not infer permissions or safety from GET/HEAD/OPTIONS being advertised.
- Fails closed on invalid routing metadata and bounded-output overflow.
- No automatic mutation capabilities are granted by discovering routes.

For provider support, implement a narrow owned adapter using `wpconnector_register_actions`. Each write should validate a strict input schema, check source/permissions, support dry-run and confirmation, and provide a readback and rollback contract where practical. Never expose unrestricted PHP, SQL, shell, HTTP proxy or filesystem access.

## Compatibility and verification

- PHP 7.4–8.5: registration, filters, no provider handler dispatch, sensitive-route suppression, paging, output bounds and unknown plugins.
- WordPress runtime matrix: core, Abilities, Elementor and WooCommerce legacy/HPOS combinations supported by existing CI.
- Existing public/private GitHub OIDC, MCP, REST, WooCommerce, Elementor and Code Snippets behavior must remain unchanged.
- Browser, hosting, third-party provider and payment/checkout tests require separate site-specific evidence.

## Clean-up decisions

1. The Code Snippets action inventory is corrected to include the shipped managed WooCommerce catalogue-only preset (`inspect`, `ensure`, `undo`) alongside the bounded exact patch.
2. The outdated external-backup attestation statement is corrected to describe the 1.17.19 updater's actual optional legacy field.
3. No adapter class, hook, migration, active endpoint, option or project-specific workflow is deleted without complete static/dynamic consumer and lifecycle proofs; unverified removals stay postponed.
4. The REST discovery method belongs to the existing DiscoveryAdapter; no second REST controller or overlapping generic executor is created.

## Release and deployment

Only package after PHP contracts, WordPress runtime CI, Plugin Check, artifact checks and private-readiness review pass. Release ZIP must be built from tested main. Installing on ISOHULK or publishing products is a separate guarded step; no site changes are part of this repository upgrade.
