# Direct ChatGPT OAuth for WordPress Connector — candidate integration

**Status: integration candidate, not a completed ChatGPT connection or release.**
The existing GitHub Actions OIDC flow and WordPress Application Password support are unchanged.

## Trust architecture

The WordPress Connector is an **OAuth protected MCP resource**, not an authorization server.
Use an independently trusted OAuth 2.1 provider supporting:
- Authorization Code + PKCE **S256** and explicit human sign-in/consent.
- Public-client CIMD (preferred for ChatGPT) or an explicitly configured, approved
  pre-registered client. Do not use password or client-credentials grants.
- OAuth Authorization Server Metadata (RFC 8414 or OIDC discovery) with PKCE support.
- RFC 7662 token introspection over HTTPS using client_secret_basic.
- Issued **access tokens** introspecting to `active:true`, exact `iss`, `sub`,
  `aud`, `scope`, and `exp`; audience must be the **exact site's MCP URL**,
  scope includes `wpconnector:read`; token expiration must be within 1 hour.

The user must explicitly link an issuer subject to one **named existing WordPress
administrator** in the site's server configuration. No user/email/first-admin lookup,
auto-enrollment or arbitrary identity mapping is permitted. The provider must confirm
consent to this exact site/resource, not to arbitrary customer websites. Do not send
secret credentials to ChatGPT, GitHub, a file in the plugin ZIP or a chat.

## Server-side example (synthetic placeholders only)

In `wp-config.php`, outside version control, configure with real values supplied
by the trusted provider/owner:

```php
define( 'WPCONNECTOR_OAUTH_ISSUER', 'https://identity.example.invalid/realm/wordpress' );
define( 'WPCONNECTOR_OAUTH_INTROSPECTION_URL', 'https://identity.example.invalid/realm/wordpress/protocol/openid-connect/token/introspect' );
define( 'WPCONNECTOR_OAUTH_CLIENT_ID', 'wordpress-connector-private-introspection-client' );
define( 'WPCONNECTOR_OAUTH_CLIENT_SECRET', 'REPLACE_VIA_SECURE_CONFIGURATION_ONLY' );
define( 'WPCONNECTOR_OAUTH_ACCOUNT_SUBJECT', 'SUBJECT_FROM_TRUSTED_IDENTITY_PROVIDER' );
define( 'WPCONNECTOR_OAUTH_WORDPRESS_USER_ID', 123 );
define( 'WPCONNECTOR_OAUTH_ACCOUNT_LINK_APPROVED', true );
```

All seven values are mandatory. Issuer and introspection endpoints must use
HTTPS on the same host/port. Localhost or HTTP setups must not be used on a real
site. Never turn this on for a generic/shared admin identity. Disable it simply
by removing the approval constant (or setting it to `false`).

## ChatGPT connection after deployment and provider setup

1. Confirm the provider supports PKCE S256 and ChatGPT's approved client registration
   (CIMD recommended). Configure its access-token audience to the *exact* MCP URL:
   `https://wordpress.example.invalid/wp-json/webactueel-wordpress-connector/v1/mcp`.
2. Install the **reviewed and signed** future Connector release. Do not install
   an unreviewed branch/ZIP on live sites.
3. Check HTTPS presence and `/wp-json/webactueel-wordpress-connector/v1/oauth-resource`.
   The resource metadata advertises the issuer. Unauthorized MCP requests advertise
   this protected-resource metadata through `WWW-Authenticate`.
4. In ChatGPT Plugins, add a custom MCP server using this exact MCP URL, select
   OAuth, complete the authorization-provider login and consent as the linked
   account. This requires a workspace supporting custom authenticated MCP.
5. Confirm a *genuine* remote MCP OAuth handshake and read-only
   `site.identity.inspect`, then verify token expiry, invalid-audience denial,
   revoked-token denial, user-right downgrade and disconnect behavior.

## Scope and security boundaries

- The first integration grants **read-only MCP access only**.
- All OAuth bearer `tools/call` requests may execute only `connector.discover`
  or `site.identity.inspect`, `dry_run:true`, `confirm:false`, empty payload.
- GitHub OIDC and existing WordPress session/Application Password permissions are
  unchanged. OAuth bearer tokens do not grant `/execute` or `/assets` REST access.
- Introspection is a separate server-to-provider call for every bearer request.
  A network timeout, 4xx/5xx, malformed JSON, missing audience/scope, wrong issuer,
  wrong subject, expired token or reduced WordPress capability **fails closed**.
- No access token or secret is persisted or logged by the Connector.
- An `isConfigured` indicator proves only local configuration. It does **not**
  mean the provider supports ChatGPT, the OAuth grant succeeded, or the linked
  WordPress user explicitly consented. Real OAuth, multisite and provider tests
  are separate gates.

## Release decision

Do not merge/publish/deploy based solely on local contracts. Independently review
OAuth trust boundaries, run WordPress/MySQL integration and exact-provider handshake
on owner-approved staging, confirm the existing protected-workflow PR #1273,
and preserve rollback. No unapproved provider or credentials are configured by
this repository change.
