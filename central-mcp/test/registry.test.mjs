import test from 'node:test';
import assert from 'node:assert/strict';
import {loadAuthorizedSites} from '../src/registry.mjs';
import {AccessDenied} from '../src/site-policy.mjs';

const actor = {verified: true, tenantId: 'team-webactueel', subject: 'user-admin', scopes: ['sites:read']};
const row = {
  id: 'site-acdrive', tenant_id: actor.tenantId,
  canonical_origin: 'https://acdrive.concept-webactueel-7.com', label: 'ACDrive',
  status: 'active', origin_verified: 1, revoked_at: null,
  subject: actor.subject, read_granted: 1, write_granted: 0, grant_revoked_at: null,
};

test('queries only the exact tenant and subject in D1 using bind parameters', async () => {
  const calls = [];
  const db = {
    prepare(sql) {
      assert.match(sql, /s\.tenant_id = \? AND g\.subject = \?/);
      return {bind(...params) {calls.push(params); return {async all(){ return {results:[row]}; }}; }};
    },
  };
  const result = await loadAuthorizedSites(db, actor);
  assert.deepEqual(calls, [[actor.tenantId, actor.subject]]);
  assert.deepEqual(result, [{site_id: row.id, site_url:row.canonical_origin, label:row.label,access:'read'}]);
});

test('does not query the database without a verified OAuth principal', async () => {
  let queried = false;
  const db = {prepare() { queried = true; throw new Error('not allowed'); }};
  await assert.rejects(loadAuthorizedSites(db, {...actor, verified: false}), AccessDenied);
  await assert.rejects(loadAuthorizedSites(db, {...actor, scopes: []}), AccessDenied);
  assert.equal(queried, false);
});

test('fails closed when D1 is missing or returns invalid rows', async () => {
  await assert.rejects(loadAuthorizedSites(null, actor), AccessDenied);
  const db = {prepare(){return{bind(){return{async all(){return {results:{}};}};}};}};
  await assert.rejects(loadAuthorizedSites(db, actor), AccessDenied);
});

test('omits unapproved, revoked, other-tenant, and other-user site rows', async () => {
  const db = {prepare(){return{bind(){return{async all(){return {results:[
    row,
    {...row,id:'other',tenant_id:'other-tenant'},
    {...row,id:'other-user',subject:'user-other'},
    {...row,id:'revoked',grant_revoked_at:'now'},
    {...row,id:'unverified',origin_verified:0},
  ]};}};}};}};
  const visible = await loadAuthorizedSites(db, actor);
  assert.deepEqual(visible.map(x=>x.site_id),['site-acdrive']);
});
