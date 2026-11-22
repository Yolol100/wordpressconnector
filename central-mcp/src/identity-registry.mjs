import { AccessDenied } from './site-policy.mjs';

const USER_ENDPOINT = 'https://api.github.com/user';
const SAFE_ID = /^[a-zA-Z0-9][a-zA-Z0-9_-]{2,79}$/;
const MAX_PROFILE_BYTES = 16384;

async function readProfile(response) {
  if (response?.status !== 200 || response.redirected === true ||
      !response.headers?.get('content-type')?.toLowerCase().includes('application/json') ||
      Number(response.headers.get('content-length') || 0) > MAX_PROFILE_BYTES ||
      !response.body?.getReader) {
    throw new AccessDenied('Identity provider verification failed');
  }
  const reader = response.body.getReader();
  const chunks = [];
  let total = 0;
  try {
    for (;;) {
      const {done, value} = await reader.read();
      if (done) break;
      total += value.byteLength;
      if (total > MAX_PROFILE_BYTES) {
        await reader.cancel();
        throw new AccessDenied('Identity provider response too large');
      }
      chunks.push(value);
    }
  } finally {
    reader.releaseLock();
  }
  const data = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) {
    data.set(chunk, offset);
    offset += chunk.byteLength;
  }
  let profile;
  try {
    profile = JSON.parse(new TextDecoder('utf-8', {fatal: true}).decode(data));
  } catch {
    throw new AccessDenied('Invalid identity provider response');
  }
  if (!profile || !Number.isSafeInteger(profile.id) || profile.id <= 0) {
    throw new AccessDenied('Invalid verified GitHub identity');
  }
  return String(profile.id);
}

// Use only the token exchanged directly with GitHub by the authorization
// server. Never accept a profile ID, tenant, or WordPress user from a tool.
export async function resolveGitHubPrincipal(db, githubToken, fetchImpl = fetch) {
  if (!db || typeof db.prepare !== 'function' ||
      typeof githubToken !== 'string' ||
      !/^[A-Za-z0-9._-]{12,2048}$/.test(githubToken) ||
      typeof fetchImpl !== 'function') {
    throw new AccessDenied('Identity provider not available');
  }
  let response;
  try {
    response = await fetchImpl(USER_ENDPOINT, {
      method: 'GET',
      redirect: 'error',
      signal: AbortSignal.timeout(8000),
      headers: {
        Authorization: 'Bearer ' + githubToken,
        Accept: 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28',
        'User-Agent': 'Webactueel-Central-MCP',
      },
    });
  } catch {
    throw new AccessDenied('Identity provider verification failed');
  }
  const providerSubject = await readProfile(response);
  const result = await db.prepare(`
    SELECT tenant_id, subject
    FROM central_identity_links
    WHERE provider = ? AND provider_subject = ?
      AND status = 'active' AND approved_at IS NOT NULL AND revoked_at IS NULL
    ORDER BY tenant_id LIMIT 2
  `).bind('github', providerSubject).all();
  if (!result || !Array.isArray(result.results) || result.results.length !== 1) {
    throw new AccessDenied('No approved central identity link');
  }
  const [member] = result.results;
  if (!SAFE_ID.test(member?.tenant_id ?? '') || !SAFE_ID.test(member?.subject ?? '')) {
    throw new AccessDenied('Invalid central identity link');
  }
  return Object.freeze({tenantId: member.tenant_id, userId: member.subject});
}
