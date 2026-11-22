import {
  AuthorizationError, OAuthAuthorizationServer, authorizationErrorRedirect,
} from '@cloudflare/workers-oauth-provider';
import { WorkerEntrypoint, env as bindings } from 'cloudflare:workers';
import { resolveGitHubPrincipal } from './identity-registry.mjs';

const READ_SCOPE = 'sites:read';
const SUPPORTED_SCOPES = [READ_SCOPE, 'offline_access'];

const authorizationServer = new OAuthAuthorizationServer({
  issuer: bindings.ISSUER,
  resources: [bindings.MCP_SERVER_URL],
  scopesSupported: SUPPORTED_SCOPES,
  clientIdMetadataDocumentEnabled: true,
  clientRegistrationEndpoint: '/oauth/register',
});

function ready(env) {
  return typeof env.GITHUB_CLIENT_ID === 'string' && env.GITHUB_CLIENT_ID.length > 4 &&
    typeof env.GITHUB_CLIENT_SECRET === 'string' && env.GITHUB_CLIENT_SECRET.length > 20 &&
    env.REGISTRY?.prepare && env.OAUTH_KV?.get &&
    typeof env.ISSUER === 'string' &&
    /^https:\/\/[a-z0-9.-]+$/.test(env.ISSUER) &&
    typeof env.MCP_SERVER_URL === 'string' &&
    /^https:\/\/[a-z0-9.-]+\/mcp$/.test(env.MCP_SERVER_URL);
}

function escapeHtml(text) {
  return String(text).replace(/[&<>"']/g, char => '&#' + char.charCodeAt(0) + ';');
}

function consentPage(description, handle) {
  const name = escapeHtml(description.clientName);
  const publisher = description.clientDomain
    ? 'Published by ' + escapeHtml(description.clientDomain)
    : 'This client registered itself. Its name is not verified.';
  const host = escapeHtml(description.redirectHost);
  const loopback = description.redirectIsLoopback
    ? '<p><strong>Warning: your local computer will receive the authorization response.</strong></p>' : '';
  const offline = description.scope.includes('offline_access')
    ? '<label><input type="checkbox" name="scope" value="offline_access"> Stay connected</label>' : '';
  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Approve read access</title></head><body>
<main><h1>Allow ${name} to read your approved WordPress sites?</h1>
<p>${publisher}</p><p>Redirect destination: <strong>${host}</strong></p>${loopback}
<p>Access applies only to websites separately approved by an administrator.</p>
<form method="post" action="/authorize">
<input type="hidden" name="handle" value="${escapeHtml(handle)}">
<label><input type="checkbox" name="scope" value="sites:read" checked required> Read approved websites</label>
${offline}
<p><button name="decision" value="approve">Allow</button>
<button name="decision" value="deny" formnovalidate>Deny</button></p>
</form></main></body></html>`;
}

function redirect(location, headers) {
  headers.set('Location', location);
  headers.set('Referrer-Policy', 'no-referrer');
  headers.set('Cache-Control', 'no-store');
  return new Response(null, {status: 302, headers});
}

async function sha256Base64Url(text) {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
  return btoa(String.fromCharCode(...new Uint8Array(digest)))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

async function githubAccessToken(env, code, verifier) {
  if (!/^[A-Za-z0-9_-]{1,1024}$/.test(code ?? '') ||
      !/^[A-Za-z0-9_-]{43,128}$/.test(verifier ?? '')) {
    throw new Error('Invalid upstream authorization response');
  }
  const response = await fetch('https://github.com/login/oauth/access_token', {
    method: 'POST',
    redirect: 'error',
    signal: AbortSignal.timeout(8000),
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded',
      'User-Agent': 'Webactueel-Central-MCP',
    },
    body: new URLSearchParams({
      client_id: env.GITHUB_CLIENT_ID,
      client_secret: env.GITHUB_CLIENT_SECRET,
      code,
      redirect_uri: env.ISSUER + '/callback',
      code_verifier: verifier,
    }),
  });
  if (response.status !== 200 || response.redirected ||
      !response.headers.get('content-type')?.includes('application/json') ||
      Number(response.headers.get('content-length') || 0) > 4096) {
    throw new Error('GitHub code exchange failed');
  }
  const text = await response.text();
  if (text.length > 4096) throw new Error('GitHub response too large');
  let data;
  try { data = JSON.parse(text); } catch { throw new Error('Invalid upstream response'); }
  if (data?.token_type?.toLowerCase() !== 'bearer' ||
      typeof data.access_token !== 'string' ||
      !/^[A-Za-z0-9._-]{12,2048}$/.test(data.access_token)) {
    throw new Error('Upstream bearer token unavailable');
  }
  return data.access_token;
}

async function handleAuthorize(request, env) {
  const api = authorizationServer.getOAuthApi(env);
  if (request.method === 'GET') {
    const auth = await api.parseAuthRequest(request);
    if (!auth.scope.includes(READ_SCOPE)) {
      return new Response('Read scope is required', {status: 400});
    }
    const description = await api.describeConsent(auth);
    const consent = await api.beginConsent(auth);
    consent.headers.set('Content-Type', 'text/html; charset=utf-8');
    consent.headers.set('Referrer-Policy', 'no-referrer');
    return new Response(consentPage(description, consent.handle), {headers: consent.headers});
  }
  if (request.method !== 'POST') {
    return new Response('Method not allowed', {status:405,headers:{Allow:'GET, POST'}});
  }
  const form = await request.formData();
  const handle = String(form.get('handle') ?? '');
  const selected = form.getAll('scope').map(String).filter(s => SUPPORTED_SCOPES.includes(s));
  if (form.get('decision') !== 'approve' || !selected.includes(READ_SCOPE)) {
    const denied = await api.denyConsent(request, handle);
    return new Response(null, {status: 302, headers: denied.headers});
  }
  const approved = await api.approveConsent(request, handle, {scope: selected});
  const verifier = await sha256Base64Url(crypto.randomUUID() + crypto.randomUUID());
  const upstream = await api.beginUpstream(approved.request, {
    data: {verifier, grantedScopes: selected},
    headers: approved.headers,
  });
  const destination = new URL('https://github.com/login/oauth/authorize');
  destination.search = new URLSearchParams({
    client_id: env.GITHUB_CLIENT_ID,
    redirect_uri: env.ISSUER + '/callback',
    state: upstream.state,
    scope: 'read:user',
    code_challenge: await sha256Base64Url(verifier),
    code_challenge_method: 'S256',
    allow_signup: 'false',
  }).toString();
  return redirect(destination.toString(), upstream.headers);
}

async function handleCallback(request, env) {
  const api = authorizationServer.getOAuthApi(env);
  const upstream = await api.finishUpstream(request);
  const params = new URL(request.url).searchParams;
  if (params.has('error')) {
    return redirect(authorizationErrorRedirect(upstream.request, 'access_denied'), upstream.headers);
  }
  const granted = upstream.data?.grantedScopes;
  if (!Array.isArray(granted) || !granted.includes(READ_SCOPE) ||
      !granted.every(s => SUPPORTED_SCOPES.includes(s) && upstream.request.scope.includes(s))) {
    throw new Error('Invalid upstream authorization scope');
  }
  const token = await githubAccessToken(env, params.get('code'), upstream.data?.verifier);
  const member = await resolveGitHubPrincipal(env.REGISTRY, token);
  const {redirectTo} = await api.completeAuthorization({
    request: upstream.request,
    userId: member.userId,
    metadata: {provider: 'github'},
    scope: granted,
    props: {tenantId: member.tenantId, userId: member.userId},
  });
  return redirect(redirectTo, upstream.headers);
}

export default class WebactueelAuthServer extends WorkerEntrypoint {
  async fetch(request) {
    if (!ready(this.env)) return new Response('Authorization server not configured', {status: 503});
    try {
      const path = new URL(request.url).pathname;
      if (path === '/authorize') return await handleAuthorize(request, this.env);
      if (path === '/callback') return await handleCallback(request, this.env);
      return await authorizationServer.fetch(request, this.env, this.ctx);
    } catch (error) {
      if (error instanceof AuthorizationError) {
        if (error.redirectTo) return Response.redirect(error.redirectTo, 302);
        return new Response('Invalid authorization request', {status: 400});
      }
      // No authorization codes, GitHub access tokens, or user identifiers in errors.
      return new Response('Authorization unavailable', {status: 503});
    }
  }

  validateToken(resource, token) {
    if (!ready(this.env)) throw new Error('Authorization unavailable');
    return authorizationServer.validateToken(resource, token, this.env);
  }
}
