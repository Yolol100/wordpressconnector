// Storage-only one-use pairing challenges. This module does NOT verify
// WordPress administrators or activate sites; a trusted verifier must do so
// before an integration may issue or redeem a challenge.
import { AccessDenied } from './site-policy.mjs';

const IDENTIFIER = /^[a-zA-Z0-9][a-zA-Z0-9_-]{2,79}$/;
const HEX = /^[a-f0-9]{64}$/;
const TTL_SECONDS = 300;

function assertId(value) {
  if (typeof value !== 'string' || !IDENTIFIER.test(value)) {
    throw new AccessDenied('Invalid pairing identity');
  }
}

function assertClock(now) {
  if (!Number.isSafeInteger(now) || now <= 0) {
    throw new AccessDenied('Invalid pairing clock');
  }
}

function assertDb(db) {
  if (!db || typeof db.prepare !== 'function') {
    throw new AccessDenied('Pairing registry unavailable');
  }
}

async function sha256(value, cryptoProvider) {
  const data = new TextEncoder().encode(value);
  const hash = await cryptoProvider.subtle.digest('SHA-256', data);
  return Array.from(new Uint8Array(hash), b => b.toString(16).padStart(2, '0')).join('');
}

// Not connected to any public MCP tool. Only call after independently
// verifying the real WordPress administrator and exact site origin.
export async function reservePairingChallenge(db, {siteId, tenantId, subject, now}, cryptoProvider = globalThis.crypto) {
  assertDb(db);
  assertId(siteId);
  assertId(tenantId);
  assertId(subject);
  assertClock(now);
  if (!cryptoProvider || typeof cryptoProvider.getRandomValues !== 'function' ||
      typeof cryptoProvider.subtle?.digest !== 'function') {
    throw new AccessDenied('Secure pairing entropy unavailable');
  }
  const bytes = cryptoProvider.getRandomValues(new Uint8Array(32));
  const secret = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  const secretHash = await sha256(secret, cryptoProvider);
  const expiry = now + TTL_SECONDS;
  const result = await db.prepare(`
    INSERT INTO central_pairing_challenges (site_id, tenant_id, subject, secret_hash, expires_at)
    SELECT id, tenant_id, ?, ?, ?
    FROM central_sites
    WHERE id = ? AND tenant_id = ? AND status = 'pending' AND revoked_at IS NULL
    ON CONFLICT(site_id) DO NOTHING
    RETURNING site_id
  `).bind(subject, secretHash, expiry, siteId, tenantId).first();
  if (result?.site_id !== siteId) {
    throw new AccessDenied('Pairing unavailable');
  }
  return {secret, expires_at: expiry};
}

// Atomically consume exactly once. The caller must independently verify
// WordPress admin consent and DNS/origin before activating any site.
export async function consumePairingChallenge(db, {siteId, tenantId, subject, secret, now}, cryptoProvider = globalThis.crypto) {
  assertDb(db);
  assertId(siteId);
  assertId(tenantId);
  assertId(subject);
  assertClock(now);
  if (typeof secret !== 'string' || !HEX.test(secret) ||
      !cryptoProvider || typeof cryptoProvider.subtle?.digest !== 'function') {
    throw new AccessDenied('Invalid pairing challenge');
  }
  const secretHash = await sha256(secret, cryptoProvider);
  const result = await db.prepare(`
    UPDATE central_pairing_challenges
    SET consumed_at = ?
    WHERE site_id = ? AND tenant_id = ? AND subject = ?
      AND secret_hash = ? AND consumed_at IS NULL AND expires_at > ?
      AND EXISTS (
        SELECT 1 FROM central_sites AS s
        WHERE s.id = central_pairing_challenges.site_id
          AND s.tenant_id = central_pairing_challenges.tenant_id
          AND s.status = 'pending' AND s.revoked_at IS NULL
      )
    RETURNING site_id
  `).bind(now, siteId, tenantId, subject, secretHash, now).first();
  if (result?.site_id !== siteId) {
    throw new AccessDenied('Pairing unavailable');
  }
  return {consumed: true};
}
