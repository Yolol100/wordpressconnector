import {
  AuthorizationError,
  OAuthAuthorizationServer,
  authorizationErrorRedirect,
} from '@cloudflare/workers-oauth-provider';
import {env as workerBindings, WorkerEntrypoint} from 'cloudflare:workers';
import {
  loadLoginConfig, newPkceVerifier, githubAuthorizeUrl, verifiedGithubPrincipal,
  READ_SCOPE,
} from './github-identity.mjs';

const provider = new OAuthAuthorizationServer({
  issuer: workerBindings.ISSUER,
  resources: [workerBindings.MCP_SERVER_URL],
  scopesSupported: [READ_SCOPE, 'offline_access'],
  clientIdMetadataDocumentEnabled: true,
  clientRegistrationEndpoint: '/oauth/register',
});

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;',
  })[c]);
}

function consentPage(details, authRequest, handle) {
  const name = escapeHtml(details.clientName || details.clientId || 'MCP client');
  const host = escapeHtml(details.redirectHost || 'unknown');
  const scopes = authRequest.scope.map(escapeHtml).join(', ');
  return [
    '<!doctype html><html lang="en"><head><meta charset="utf-8">',
    '<meta name="viewport" content="width=device-width,initial-scale=1">',
    '<title>Authorize Webactueel MCP</title></head><body>',
    '<main><h1>Approve access to Webactueel</h1>',
    '<p>Client: <strong>', name, '</strong></p>',
    '<p>Access is returned to: <strong>', host, '</strong></p>',
    '<p>Requested permissions: <strong>', scopes, '</strong></p>',
    '<p>This signs you in with GitHub. It does not approve any WordPress website.</p>',
    '<form method="post" action="/authorize">',
    '<input type="hidden" name="handle" value="', escapeHtml(handle), '">',
    '<button type="submit" name="decision" value="approve">Approve and sign in with GitHub</button>',
    '<button type="submit" name="decision" value="deny">Deny</button>',
    '</form></main></body></html>',
  ].join('');
}

async function consent(request, env) {
  const oauth = provider.getOAuthApi(env);
  if (request.method === 'GET') {
    const authRequest = await oauth.parseAuthRequest(request);
    const details = await oauth.describeConsent(authRequest);
    const begun = await oauth.beginConsent(authRequest);
    const headers = new Headers(begun.headers);
    headers.set('Content-Type', 'text/html; charset=utf-8');
    headers.set('Referrer-Policy', 'no-referrer');
    return new Response(consentPage(details, authRequest, begun.handle), {
      status: 200, headers,
    });
  }
  if (request.method !== 'POST' ||
      !(request.headers.get('content-type') || '').startsWith('application/x-www-form-urlencoded') ||
      Number(request.headers.get('content-length') || '0') > 4096) {
    return new Response('Method not allowed', {status: 405});
  }
  const form = await request.formData();
  const handle = String(form.get('handle') || '');
  const decision = String(form.get('decision') || '');
  if (decision === 'deny') {
    const denied = await oauth.denyConsent(request, handle);
    return new Response(null, {status: 302, headers: denied.headers});
  }
  if (decision !== 'approve') return new Response('Invalid decision', {status: 400});
  const approved = await oauth.approveConsent(request, handle);
  const verifier = newPkceVerifier();
  const pending = await oauth.beginUpstream(approved.request, {
    data: {verifier},
    headers: approved.headers,
  });
  const authUrl = await githubAuthorizeUrl(await loadLoginConfig(env), pending.state, verifier);
  pending.headers.set('Location', authUrl);
  return new Response(null, {status: 302, headers: pending.headers});
}

async function finishGithub(request, env) {
  if (request.method !== 'GET') return new Response('Method not allowed', {status: 405});
  const oauth = provider.getOAuthApi(env);
  const pending = await oauth.finishUpstream(request);
  const original = pending.request;
  const url = new URL(request.url);
  const code = url.searchParams.get('code');
  if (url.searchParams.has('error') || !code) {
    pending.headers.set('Location', authorizationErrorRedirect(original, 'access_denied'));
    return new Response(null, {status: 302, headers: pending.headers});
  }
  let principal;
  try {
    principal = await verifiedGithubPrincipal(await loadLoginConfig(env), code, pending.data?.verifier);
  } catch {
    pending.headers.set('Location', authorizationErrorRedirect(original, 'access_denied'));
    return new Response(null, {status: 302, headers: pending.headers});
  }
  const {redirectTo} = await oauth.completeAuthorization({
    request: original,
    userId: principal.userId,
    metadata: {},
    scope: original.scope,
    props: {userId: principal.userId, tenantId: principal.tenantId},
  });
  pending.headers.set('Location', redirectTo);
  return new Response(null, {status: 302, headers: pending.headers});
}

export default class AuthServer extends WorkerEntrypoint {
  async fetch(request) {
    try {
      // Fail-closed even for discovery when the operator has not installed
      // the encrypted GitHub OAuth credentials, or if the KV binding is absent.
      await loadLoginConfig(this.env);
      const path = new URL(request.url).pathname;
      if (path === '/authorize') return await consent(request, this.env);
      if (path === '/callback') return await finishGithub(request, this.env);
      return await provider.fetch(request, this.env, this.ctx);
    } catch (error) {
      if (error instanceof AuthorizationError) {
        if (error.redirectTo) return Response.redirect(error.redirectTo, 302);
        return new Response('Authorization request invalid', {status: 400});
      }
      // No sensitive error messages, tokens or personally identifying data.
      return new Response('Authorization unavailable', {status: 503});
    }
  }

  async validateToken(resource, token) {
    await loadLoginConfig(this.env);
    return provider.validateToken(resource, token, this.env);
  }
}
