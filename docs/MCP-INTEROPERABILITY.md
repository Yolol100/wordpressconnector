# MCP interoperability review (2026-10-18)

This document explains the sources and the **smallest safe, implemented improvement** to the WordPress Connector; it is not a promise of universal third-party plugin administration.

| Reference | Verified public capabilities | Connector decision |
| --- | --- | --- |
| [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) | Exposes WordPress Abilities as MCP tools, resources and prompts with native permission checks, HTTP/STDIO and compact meta-tools | Add native MCP `resources/list`, `resources/read`, `prompts/list`, `prompts/get` within the existing authenticated connector, not a duplicate Abilities implementation |
| [Elementor MCP](https://github.com/elementor/elementor/blob/main/modules/mcp/module.php) | Native Elementor Abilities for pages, variables, classes and editor workflows; resources and a dedicated admin connection screen | Keep delegating Elementor-specific operations to verified installed Elementor Abilities; add static safety/integration MCP guidance rather than copying Elementor code or bypassing editor checks |
| [WooCommerce MCP](https://developer.woocommerce.com/docs/features/mcp/) | Native WooCommerce Abilities for product and order operations, delivered through the WordPress MCP Adapter; deprecated `/wp-json/woocommerce/mcp` removed in WooCommerce 11.3 | Preserve existing WooCommerce semantic adapters and native Ability discovery; do not reintroduce the deprecated endpoint or expose customer/order PII through new resources |
| [EMCP Tools](https://github.com/msrbuilds/elementor-mcp) | A broad third-party Elementor/WordPress tool catalog and guided client connection UI | Keep a compact three-tool MCP action surface plus separate resources/prompts; do not expose hundreds of generic unconstrained write tools |
| [MCP 2026-07-28 specification](https://modelcontextprotocol.io/specification/2026-07-28/server/resources) | Stateless modern MCP, authenticated resources, private cache hints, exact URI validation, prompt selection | Implement resources and prompts for both supported MCP revisions; private cache hints only on modern replies; exact allowlists and no arbitrary URI lookup |

## Shipping changes

- MCP capabilities now truthfully advertise `resources` and `prompts`, alongside existing `tools`, for both the legacy `initialize` and modern `server/discover` flow.
- Two fixed non-sensitive text resources: `wordpress-connector://guide/safe-changes` and `wordpress-connector://guide/integrations`.
- Two user-selectable prompts: `wordpress_connector_readonly_audit` (no arguments), `wordpress_connector_plan_safe_change` (optional short `goal` string).
- Strict allowlisting for resource URIs, prompt names/arguments and list cursors. No fetching of user URLs, remote content, filesystem or database data.
- Uses the existing WordPress HTTPS, administrator and MCP authorization, including modern `MCP-Protocol-Version` and `Mcp-Method` validation. No new REST endpoints, shared session identifiers, OAuth claims or credential exposure.
- Unit/contract tests for both protocol revisions and negative inputs; genuine disposable WordPress Docker test for anonymous denial and authenticated reads.

## Deliberate non-changes

- Direct ChatGPT OAuth linking needs a reviewed authorization server/provider and actual end-to-end user authorization. The Application Password button only supports suitable existing WordPress-authenticated clients. See [issue #984](https://github.com/Yolol100/wordpressconnector/issues/984).
- No third-party plugin's source code or commercial/brand assets were copied. Native provider permission callbacks remain authoritative.
- No generic arbitrary PHP, database, shell, filesystem, plugin install or public write endpoint was added.
- No automatic live deployment, admin role changes, password creation or server flag activation.

## Evidence required

The plugin source version and stable tag must match. Require all GitHub CI PHP matrices, WordPress Plugin Check, pinned WordPress 7.1.3 real Docker smoke (including the new MCP REST tests), deterministic GitHub Release artifact integrity, and deployed-site acceptance separately. GitHub build success does not prove a production website is updated.


## October 18, 2026 comparative upgrade

See [MCP competitor audit](MCP-COMPETITOR-AUDIT-2026-10-18.md) for the current comparison including Automattic Remote, self-hosted OAuth/approvals, MainWP, WP Umbrella, the official ChatGPT authorization specification and security/release boundaries. Connector v1.19.18 adds an authenticated browser-only connection diagnostic; it does **not** change OAuth authorization behavior or imply a verified ChatGPT custom-plugin connection.
