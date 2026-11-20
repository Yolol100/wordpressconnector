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
3. Check HTTPS presence and the canonical RFC 9728 metadata URL:
   `https://wordpress.example.invalid/.well-known/oauth-protected-resource/wp-json/webactueel-wordpress-connector/v1/mcp`.
   The existing `/wp-json/webactueel-wordpress-connector/v1/oauth-resource` remains
   an optional REST alias. The hosting web server **must forward the .well-known
   path to WordPress** (some Nginx/Apache/CDN rules intercept it); a PHP route alone
   is not proof that the public URI works. HTTP 401 from the MCP resource advertises
   this canonical metadata location in `WWW-Authenticate`.
4. In ChatGPT Plugins, add a custom MCP server using this exact MCP URL, select
   OAuth, complete the authorization-provider login and consent as the linked
   account. This requires a workspace supporting custom authenticated MCP.
5. Confirm a *genuine* remote MCP OAuth handshake and read-only
   `site.identity.inspect`, then verify token expiry, invalid-audience denial,
   revoked-token denial, user-right downgrade and disconnect behavior.

## Scope and security boundaries

- The default integration grants **read-only MCP access only**. A separately approved, opt-in `wpconnector:update` scope allows only the canonical Connector release updater, never general writes.
- All OAuth bearer `tools/call` requests may execute only `connector.discover`
  or `site.identity.inspect`, `dry_run:true`, `confirm:false`, empty payload.
- GitHub OIDC and existing WordPress session/Application Password permissions are
  unchanged. OAuth bearer tokens do not grant `/execute` or `/assets` REST access.
- Introspection is a separate server-to-provider call for every bearer request.
  It sends only RFC 7662's `token` and `token_type_hint` fields, verifies the
  returned `aud` against the exact MCP URL, bounds provider responses to 16 KiB
  during download, disables redirects, verifies TLS and times out after five seconds.
  A network timeout, 4xx/5xx, malformed JSON, missing audience/scope, wrong issuer,
  wrong subject, expired token or reduced WordPress capability **fails closed**.
- A WordPress transient-based guard permits at most 12 introspections per verified
  web-server remote IP and 60 per site per minute. The guard is best-effort under
  concurrency; also configure a trusted CDN/WAF rate limit at the edge, especially
  behind proxies that reuse a server IP. Never trust an unvalidated X-Forwarded-For
  value as proof of a distinct client.
- The `WWW-Authenticate` bearer challenge reports `invalid_token` on invalid
  401 responses and `insufficient_scope` on missing OAuth scope, with no error
  code for the initial unauthenticated discovery challenge.
- No access token or secret is persisted or logged by the Connector.
- The introspection client secret is **server-to-provider** and is not the
  OAuth client credential used by ChatGPT for the authorization-code/PKCE flow.
  The external authorization server must support CIMD or approved DCR/client
  registration, explicit user consent and the matching ChatGPT redirect URL.
- An `isConfigured` indicator proves only local configuration. It does **not**
  mean the provider supports ChatGPT, the OAuth grant succeeded, or the linked
  WordPress user explicitly consented. Real OAuth, multisite and provider tests
  are separate gates.

## Optional, owner-approved Connector self-update from ChatGPT

This capability is **disabled by default**. Once a trusted OAuth 2.1 authorization
server has been connected, the site owner may separately configure in wp-config.php:

```php
define( 'WPCONNECTOR_OAUTH_ALLOW_SELF_UPDATE', true );
```

Before a release can be approved, the WordPress server administrator must
**independently verify the release artifact attestation** against the protected
`Yolol100/wordpressconnector` repository, trusted release workflow,
and the **exact ZIP bytes** (for example with the official `gh attestation
verify` workflow), then pin the authenticated ZIP SHA-256 in the site's
secure `wp-config.php`:

```php
// EXAMPLE PLACEHOLDER. Replace only after verifying trusted attestation.
define( 'WPCONNECTOR_OAUTH_ATTESTED_RELEASE_SHA256', 'sha256:YOUR_VERIFIED_64_HEX_DIGEST' );
```

A GitHub release ZIP/checksum pair alone **does not prove provenance**.
Without the independently verified, exact pinned digest the WordPress
approval form remains unavailable and installation is denied. The plugin
does **not** implement native Sigstore attestation verification; the manual
attestation verification/pin is an explicit release prerequisite, not proof
that any current real release was verified.

After the provider is linked and this digest is pinned, the linked owner
opens **WordPress → Plugins**, reviews the release tag and ZIP digest,
and clicks **Approve this Connector release** with their real WordPress
cookie session and nonce. That approval lasts at most 10 minutes and is
one-use only. A ChatGPT-supplied `confirm:true` without this independent
server-held browser approval always fails. No arbitrary OAuth client can
create or renew the approval.

Do not enable it based on a ChatGPT prompt alone. The exact linked WordPress
account must currently possess `update_plugins`, and the access token must
contain **both** `wpconnector:read` and `wpconnector:update`. Neither scope
grants broad WordPress updates or arbitrary source-code editing.

The update procedure in the ChatGPT MCP connection is:

1. Use `wordpress_connector_release_preview` to inspect the **latest published**
   canonical GitHub release, its verified package SHA-256 and the plugin's
   installed version. Record the resulting `current_state_token` or current
   SHA-256 fingerprint. The preview does **not** write files.
2. Independently confirm the approved release/version and the site's recovery
   plan with the owner. A new version requires a new approval; a general
   "always update" instruction is not consent to future releases.
3. Call `wordpress_connector_release_apply` with a stable `request_id`,
   the exact **64-character** SHA-256 `release_fingerprint` from
   `structuredContent.result.data.release_fingerprint` in the preview as
   `expected_fingerprint`,
   and `confirm:true`. Missing rights/approval, stale installed version or
   changed package digest are rejected.
4. The existing `connector.update.apply` uses the canonical GitHub
   `releases/latest` API, verifies asset URL, ZIP contents and checksums,
   then invokes the WordPress Core Upgrader with temporary backup hooks.
   A failed installation/readback may trigger Core's temporary recovery, but
   a **post-success rollback is not guaranteed**. Verify plugin version, active
   status, HTTPS presence and representative read operations independently.

Never allow ChatGPT to submit raw PHP, arbitrary Git branches, file paths,
external ZIP URLs or credentials as upgrade inputs. Development happens in
a GitHub PR; tests, security review, approval and a separately published
release precede any live installation. This feature installs no draft commit.

**Important:** the OAuth protected-resource code in a draft PR does not
enable ChatGPT itself. A real provider with OAuth Authorization Code + PKCE,
approved user consent, site-local confidential introspection settings,
workspace MCP registration and production-host `.well-known` routing
remain separate prerequisites. Until these pass, the new tools remain
unavailable on live WordPress.

## Release decision

Do not merge/publish/deploy based solely on local contracts. Independently review
OAuth trust boundaries, run WordPress/MySQL integration and exact-provider handshake
on owner-approved staging, confirm the existing protected-workflow PR #1273,
and preserve rollback. No unapproved provider or credentials are configured by
this repository change.
