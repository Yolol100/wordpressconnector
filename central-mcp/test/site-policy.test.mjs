import test from 'node:test';
import assert from 'node:assert/strict';
import {
  AccessDenied, SiteNotFound, canonicalSiteOrigin,
  listVisibleSites, authorizeSiteRead, authorizeSiteMutation,
} from '../src/site-policy.mjs';

const actor = Object.freeze({ verified: true, tenantId: 'team-webactueel', subject: 'user-admin', scopes: ['sites:read'] });
const good = Object.freeze({
  id: 'acdrive-staging', tenantId: actor.tenantId, status: 'active',
  origin: 'https://acdrive.concept-webactueel-7.com', label: 'ACDrive',
  originVerified: true, grants: {'user-admin': {read: true, write: false}},
});
const otherTenant = {...good, id: 'private-customer', tenantId: 'other-tenant'};
const otherOwner = {...good, id: 'other-owner', grants: {'someone-else': {read: true}}};
const revoked = {...good, id: 'revoked-site', revokedAt: '2026-11-22T00:00:00Z'};
const unsafe = {...good, id: 'untrusted-origin', origin: 'https://127.0.0.1'};
const sites = [good, otherTenant, otherOwner, revoked, unsafe];

test('accepts canonical HTTPS root only', () => {
  assert.equal(canonicalSiteOrigin(good.origin), good.origin);
  assert.equal(canonicalSiteOrigin('https://EXAMPLE.com/'), 'https://example.com');
});

for (const invalid of [
  'http://example.com', 'https://localhost', 'https://internal.local',
  'https://127.0.0.1', 'https://[::1]', 'https://example.com:8443',
  'https://user:password@example.com', 'https://example.com?token=secret',
  'https://example.com/#frag', 'https://example.com/wp-admin/',
  'https://example.com.evil.invalid', 'https://-example.com',
  ' https://example.com', '', null,
]) {
  test('rejects untrusted site origin: ' + String(invalid), () => {
    assert.throws(() => canonicalSiteOrigin(invalid), AccessDenied);
  });
}

test('lists only sites granted to exact OAuth subject and tenant', () => {
  assert.deepEqual(listVisibleSites(actor, sites), [{
    site_id: good.id, site_url: good.origin, label: good.label, access: 'read',
  }]);
});

test('requires a provider-verified OAuth principal', () => {
  for (const forged of [null, {}, {...actor, verified: false}, {...actor, scopes: []}, {...actor, subject: 'unknown'}]) {
    if (forged?.subject === 'unknown') {
      assert.deepEqual(listVisibleSites(forged, sites), []);
    } else {
      assert.throws(() => listVisibleSites(forged, sites), AccessDenied);
    }
  }
});

test('allows only explicitly allowlisted read actions on owned site', () => {
  assert.deepEqual(authorizeSiteRead(actor, sites, good.id, 'site.identity.inspect'), {
    site_id: good.id, action: 'site.identity.inspect', payload: {}, dry_run: true, confirm: false,
  });
  assert.equal(authorizeSiteRead(actor, sites, good.id, 'connector.setup.inspect').action, 'connector.setup.inspect');
  assert.throws(() => authorizeSiteRead(actor, sites, good.id, 'filesystem.write_text'), AccessDenied);
  assert.throws(() => authorizeSiteRead(actor, sites, good.id, 'site.identity.inspect', {url: 'https://evil.example'}), AccessDenied);
});

test('cannot enumerate or access other tenants, users, revoked sites or unsafe origins', () => {
  for (const site of sites.slice(1)) {
    assert.throws(() => authorizeSiteRead(actor, sites, site.id, 'site.identity.inspect'), SiteNotFound);
  }
  assert.throws(() => authorizeSiteRead(actor, sites, 'missing-site', 'site.identity.inspect'), SiteNotFound);
});

test('rejects ambiguous duplicate site ids rather than selecting the first record', () => {
  assert.throws(() => authorizeSiteRead(actor, [good, {...good}], good.id, 'site.identity.inspect'), SiteNotFound);
});

test('write route always fails closed even with forged authorization', () => {
  const writeActor = {...actor, scopes: ['sites:read', 'sites:write']};
  const siteWithWrite = {...good, writeEnabled: true, grants: {'user-admin': {read: true, write: true}}};
  const intent = {confirm: true, request_id: 'request-20261122', expected_fingerprint: 'a'.repeat(64)};
  assert.throws(() => authorizeSiteMutation(actor, [siteWithWrite], siteWithWrite.id, 'post.update', intent), AccessDenied);
  assert.throws(() => authorizeSiteMutation(writeActor, [good], good.id, 'post.update', intent), AccessDenied);
  assert.throws(() => authorizeSiteMutation(writeActor, [siteWithWrite], siteWithWrite.id, 'post.update', intent),
    {name: 'AccessDenied', message: 'Central write transport is not enabled'});
  assert.throws(() => authorizeSiteMutation(writeActor, [siteWithWrite], siteWithWrite.id, 'post.update', {...intent, confirm: false}), AccessDenied);
});
