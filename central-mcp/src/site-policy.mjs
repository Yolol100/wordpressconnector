// Security policy shared by the proposed central MCP gateway and its tests.
// Authenticated identities must come from the OAuth provider, never tool arguments.

export class AccessDenied extends Error {
  constructor(message = 'Access denied') {
    super(message);
    this.name = 'AccessDenied';
  }
}

export class SiteNotFound extends Error {
  constructor() {
    super('Site not found');
    this.name = 'SiteNotFound';
  }
}

const ID = /^[a-zA-Z0-9][a-zA-Z0-9_-]{2,79}$/;
const SCOPE_READ = 'sites:read';
const SCOPE_WRITE = 'sites:write';
export const READ_ACTIONS = Object.freeze([
  'site.identity.inspect',
  'connector.setup.inspect',
]);

function isRecord(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function hasScope(actor, scope) {
  return Array.isArray(actor.scopes) && actor.scopes.includes(scope);
}

function assertPrincipal(actor) {
  if (!isRecord(actor) || actor.verified !== true ||
      !ID.test(actor.tenantId ?? '') || !ID.test(actor.subject ?? '') ||
      !hasScope(actor, SCOPE_READ)) {
    throw new AccessDenied('Verified OAuth identity with read scope required');
  }
  return actor;
}

export function canonicalSiteOrigin(input) {
  if (typeof input !== 'string' || input !== input.trim() || input.length > 253) {
    throw new AccessDenied('Invalid site origin');
  }
  let url;
  try {
    url = new URL(input);
  } catch {
    throw new AccessDenied('Invalid site origin');
  }
  if (url.protocol !== 'https:' || url.username || url.password ||
      url.port || url.search || url.hash || url.pathname !== '/') {
    throw new AccessDenied('Site must use an HTTPS root URL');
  }
  const host = url.hostname;
  if (!host || !host.includes('.') || host.includes(':') ||
      host.length > 253 || !/^[a-z0-9.-]+$/.test(host) ||
      /(^\.|\.\.|\.$)/.test(host) ||
      /^\d+(?:\.\d+){3}$/.test(host) ||
      host.split('.').some(part => !part || part.length > 63 || part.startsWith('-') || part.endsWith('-')) ||
      /\.(?:local|internal|localhost|test|invalid)$/.test(host)) {
    throw new AccessDenied('Untrusted site hostname');
  }
  return 'https://' + host;
}

function siteGrant(actor, site) {
  if (!isRecord(site) || !ID.test(site.id ?? '') ||
      site.tenantId !== actor.tenantId || site.status !== 'active' ||
      site.revokedAt || site.originVerified !== true ||
      !isRecord(site.grants)) {
    return null;
  }
  const grant = Object.hasOwn(site.grants, actor.subject) ? site.grants[actor.subject] : null;
  if (!isRecord(grant) || grant.read !== true || grant.revokedAt) {
    return null;
  }
  try {
    canonicalSiteOrigin(site.origin);
  } catch {
    return null;
  }
  return grant;
}

export function listVisibleSites(actor, sites) {
  const identity = assertPrincipal(actor);
  if (!Array.isArray(sites)) {
    throw new AccessDenied('Invalid site registry');
  }
  return sites.filter(site => siteGrant(identity, site)).map(site => ({
    site_id: site.id,
    site_url: canonicalSiteOrigin(site.origin),
    label: typeof site.label === 'string' ? site.label.slice(0, 120) : '',
    access: 'read',
  }));
}

export function authorizeSiteRead(actor, sites, siteId, action, payload = {}) {
  const identity = assertPrincipal(actor);
  if (!ID.test(siteId ?? '') || !READ_ACTIONS.includes(action) ||
      !isRecord(payload) || Object.keys(payload).length !== 0 || !Array.isArray(sites)) {
    throw new AccessDenied('Unsupported read action');
  }
  const matches = sites.filter(site => site?.id === siteId);
  if (matches.length !== 1 || !siteGrant(identity, matches[0])) {
    throw new SiteNotFound();
  }
  // Planning is deliberately separate from dispatch. This never forwards
  // inbound ChatGPT bearer tokens or accepts a user-selected arbitrary URL.
  return Object.freeze({
    site_id: siteId,
    action,
    payload: Object.freeze({}),
    dry_run: true,
    confirm: false,
  });
}

export function authorizeSiteMutation(actor, sites, siteId, action, intent) {
  const identity = assertPrincipal(actor);
  const matches = Array.isArray(sites) ? sites.filter(site => site?.id === siteId) : [];
  if (matches.length !== 1) {
    throw new SiteNotFound();
  }
  const grant = siteGrant(identity, matches[0]);
  if (!grant) {
    throw new SiteNotFound();
  }
  if (!hasScope(identity, SCOPE_WRITE) || grant.write !== true ||
      matches[0].writeEnabled !== true ||
      !isRecord(intent) || intent.confirm !== true ||
      typeof intent.request_id !== 'string' || !ID.test(intent.request_id) ||
      typeof intent.expected_fingerprint !== 'string' ||
      !/^[a-f0-9]{64}$/.test(intent.expected_fingerprint) ||
      typeof action !== 'string' || action.length > 100) {
    throw new AccessDenied('Explicit site-scoped write approval required');
  }
  // The central service has no write transport in this first phase.
  // Never infer a site write from an OAuth grant alone.
  throw new AccessDenied('Central write transport is not enabled');
}
