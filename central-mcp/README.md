# Webactueel Central MCP - foundation (NOT deployed)

This is the first, fail-closed security layer for a single ChatGPT app controlling several WordPress websites. It does not currently expose a public MCP server, issue OAuth tokens, pair WordPress sites, or dispatch WordPress actions. Those are separate integration gates. The live WordPress Connector and all GitHub Actions remain untouched.

## Architecture

1. ChatGPT connects **once** to one Webactueel-owned HTTPS Streamable HTTP MCP server, with OAuth.
2. Each new WordPress installation obtains **one explicit site administrator consent** to register its exact canonical origin and tenant.
3. The central registry stores tenant/user access grants and site identities, not an unrestricted global WordPress administrator credential.
4. OAuth grants are bound to the central MCP audience. Site-specific delegation is separate and revocable. **Never forward a ChatGPT bearer token to a WordPress site.**
5. Every site selection is filtered by tenant, verified OAuth subject, site status, actual site grant and verified origin.
6. WordPress continues checking capabilities, policy flags, request fingerprints, idempotency and rollback for each delegated operation.
7. Existing GitHub OIDC Actions and per-site REST/MCP transports remain available throughout migration.

## Implemented and locally tested

- src/site-policy.mjs: strict HTTPS root-origin validation, per-tenant grants, two explicitly allowlisted read plans, no active write transport.
- src/registry.mjs: parameter-bound D1 lookups by OAuth tenant and subject, filtered again before returning results.
- schema.sql: normalized registry/grants with cross-tenant foreign-key enforcement and **no token or password columns**.
- test/: 26 Node security tests; SQLite foreign-key isolation was also checked locally.

Run: node --test central-mcp/test/*.test.mjs

## Mandatory gates before a usable central service

- Cloudflare Worker using the modern stateless createMcpHandler (NOT the deprecated McpAgent), plus a properly configured OAuth provider.
- ChatGPT CIMD or DCR client registration, OAuth 2.1, PKCE, user login, explicit consent, audience validation, token refresh/revoke.
- One-use WordPress administrator pairing and proof of origin; tenant ownership, revocation and credential rotation.
- Site credentials encrypted and isolated; DNS/public IP checks on every outbound request, redirect blocking, retries and SSRF prevention.
- Implement and test WordPress-scoped delegation without bypassing GitHub OIDC, site role/capabilities or disabled WordPress file editor settings.
- Site status API and read-only tool execution, later mutating tools only with fresh preview, explicit approval, readback and rollback.
- Non-root WordPress installations need separately validated canonical path support.
- WordPress/WooCommerce integration, ChatGPT browser OAuth, multi-tenant isolation and independent staging QA.

Cloudflare account currently has no Workers, and no OAuth identity provider or central hostname has been configured. This branch must not auto-deploy.

## Official references checked 2026-11-22

- https://developers.openai.com/api/docs/guides/custom-mcp-server
- https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/docs/specification/2025-11-25/basic/authorization.mdx
- https://developers.cloudflare.com/agents/model-context-protocol/protocol/transport/
- https://developers.cloudflare.com/agents/model-context-protocol/protocol/authorization/
- https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
