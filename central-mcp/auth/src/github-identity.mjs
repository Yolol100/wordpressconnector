// GitHub is used only to authenticate a central human principal.
// Never forward or persist its token, and never treat it as WordPress permission.
const ID = /^[A-Za-z0-9][A-Za-z0-9_-]{2,79}$/;
const CLIENT = /^[A-Za-z0-9_]{12,128}$/;
const VERIFIER = /^[A-Za-z0-9._~-]{43,128}$/;
const CODE = /^[A-Za-z0-9_-]{8,256}$/;
const STATE = /^[A-Za-z0-9._~-]{16,2048}$/;
export const READ_SCOPE = 'sites:read';

function deny() { throw new Error('Central authentication unavailable'); }
function checkedUrl(input) {
  if (typeof input !== 'string' || !input.startsWith('https://')) deny();
  const url = new URL(input);
  if (url.username || url.password || url.search || url.hash || url.port) deny();
  return url;
}

export function loginConfig(env) {
  if (!env || typeof env !== 'object') deny();
  const issuer = checkedUrl(env.ISSUER);
  const resource = checkedUrl(env.MCP_SERVER_URL);
  if (issuer.pathname !== '/' || resource.pathname !== '/mcp' ||
      issuer.hostname !== 'webactueel-central-auth.andrew-baeten.workers.dev' ||
      resource.hostname !== 'webactueel-central-mcp.andrew-baeten.workers.dev' ||
      !CLIENT.test(env.GITHUB_CLIENT_ID ?? '') ||
      typeof env.GITHUB_CLIENT_SECRET !== 'string' ||
      env.GITHUB_CLIENT_SECRET.length < 20 ||
      env.GITHUB_CLIENT_SECRET.length > 512 ||
      !/^[1-9][0-9]{2,19}$/.test(env.GITHUB_ALLOWED_USER_ID ?? '') ||
      !ID.test(env.TENANT_ID ?? '') ||
      !env.OAUTH_KV || typeof env.OAUTH_KV.get !== 'function' ||
      typeof env.OAUTH_KV.put !== 'function') deny();
  return {
    issuer: env.ISSUER,
    callback: env.ISSUER + '/callback',
    resource: env.MCP_SERVER_URL,
    clientId: env.GITHUB_CLIENT_ID,
    clientSecret: env.GITHUB_CLIENT_SECRET,
    userId: env.GITHUB_ALLOWED_USER_ID,
    tenantId: env.TENANT_ID,
  };
}

// Cloudflare Secrets Store bindings expose an asynchronous get() method.
// Keep the secret inside the Worker and fail closed when not provisioned.
export async function loadLoginConfig(env) {
  if (!env || typeof env.GITHUB_CLIENT_SECRET?.get !== 'function') deny();
  let secret;
  try { secret = await env.GITHUB_CLIENT_SECRET.get(); } catch { deny(); }
  return loginConfig({...env, GITHUB_CLIENT_SECRET: secret});
}

export function newPkceVerifier(cryptoProvider = globalThis.crypto) {
  if (!cryptoProvider || typeof cryptoProvider.getRandomValues !== 'function') deny();
  const bytes = cryptoProvider.getRandomValues(new Uint8Array(32));
  return Array.from(bytes, n => n.toString(16).padStart(2, '0')).join('');
}

export async function githubAuthorizeUrl(config, state, verifier, cryptoProvider = globalThis.crypto) {
  if (!STATE.test(state ?? '') || !VERIFIER.test(verifier ?? '') ||
      !cryptoProvider || typeof cryptoProvider.subtle?.digest !== 'function') deny();
  const hash = new Uint8Array(await cryptoProvider.subtle.digest('SHA-256', new TextEncoder().encode(verifier)));
  const base64 = btoa(String.fromCharCode(...hash)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const url = new URL('https://github.com/login/oauth/authorize');
  url.searchParams.set('client_id', config.clientId);
  url.searchParams.set('redirect_uri', config.callback);
  url.searchParams.set('state', state);
  url.searchParams.set('code_challenge', base64);
  url.searchParams.set('code_challenge_method', 'S256');
  url.searchParams.set('scope', 'read:user');
  url.searchParams.set('allow_signup', 'false');
  return url.toString();
}

async function smallJson(response) {
  if (!response || response.ok !== true) deny();
  const content = await response.text();
  if (content.length < 2 || content.length > 16384) deny();
  try { return JSON.parse(content); } catch { deny(); }
}

export async function verifiedGithubPrincipal(config, code, verifier, fetchFn = fetch) {
  if (!CODE.test(code ?? '') || !VERIFIER.test(verifier ?? '') ||
      typeof fetchFn !== 'function') deny();
  const tokenResponse = await fetchFn('https://github.com/login/oauth/access_token', {
    method: 'POST',
    redirect: 'error',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/x-www-form-urlencoded',
      'User-Agent': 'WebactueelCentralMCP',
    },
    body: new URLSearchParams({
      client_id: config.clientId,
      client_secret: config.clientSecret,
      code,
      redirect_uri: config.callback,
      code_verifier: verifier,
    }),
    signal: AbortSignal.timeout(8000),
  });
  const exchange = await smallJson(tokenResponse);
  const token = exchange.access_token;
  if (typeof token !== 'string' || token.length < 20 || token.length > 1024 ||
      exchange.token_type?.toLowerCase() !== 'bearer') deny();

  const userResponse = await fetchFn('https://api.github.com/user', {
    method: 'GET',
    redirect: 'error',
    headers: {
      Accept: 'application/vnd.github+json',
      Authorization: 'Bearer ' + token,
      'User-Agent': 'WebactueelCentralMCP',
      'X-GitHub-Api-Version': '2022-11-28',
    },
    signal: AbortSignal.timeout(8000),
  });
  const user = await smallJson(userResponse);
  if (!Number.isSafeInteger(user.id) || user.id <= 0 ||
      String(user.id) !== config.userId) deny();
  // The Github token is deliberately not included in OAuth grant props.
  return {userId: String(user.id), tenantId: config.tenantId};
}
