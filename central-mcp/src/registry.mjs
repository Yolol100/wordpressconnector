import { AccessDenied, listVisibleSites } from './site-policy.mjs';

const SELECT_SITES = `
SELECT s.id, s.tenant_id, s.canonical_origin, s.label,
       s.status, s.origin_verified, s.revoked_at,
       g.subject, g.read_granted, g.write_granted, g.revoked_at AS grant_revoked_at
FROM central_sites AS s
JOIN central_site_grants AS g
  ON s.id = g.site_id AND s.tenant_id = g.tenant_id
WHERE s.tenant_id = ? AND g.subject = ?
  AND s.revoked_at IS NULL AND g.revoked_at IS NULL
ORDER BY s.id
LIMIT 100`;

export async function loadAuthorizedSites(db, actor) {
  if (actor?.verified !== true || typeof actor.tenantId !== 'string' ||
      typeof actor.subject !== 'string' || !Array.isArray(actor.scopes) ||
      !actor.scopes.includes('sites:read')) {
    throw new AccessDenied('Verified OAuth identity required before registry access');
  }
  if (!db || typeof db.prepare !== 'function') {
    throw new AccessDenied('Site registry is unavailable');
  }
  const result = await db.prepare(SELECT_SITES).bind(actor.tenantId, actor.subject).all();
  if (!result || !Array.isArray(result.results)) {
    throw new AccessDenied('Site registry returned an invalid response');
  }
  return listVisibleSites(actor, result.results.map(row => ({
    id: row.id,
    tenantId: row.tenant_id,
    origin: row.canonical_origin,
    label: row.label,
    status: row.status,
    originVerified: row.origin_verified === 1,
    revokedAt: row.revoked_at,
    grants: {
      [row.subject]: {
        read: row.read_granted === 1,
        write: row.write_granted === 1,
        revokedAt: row.grant_revoked_at,
      },
    },
  })));
}
