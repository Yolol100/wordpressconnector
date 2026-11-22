import test from 'node:test';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {reservePairingChallenge, consumePairingChallenge} from '../src/pairing-challenge.mjs';
import {AccessDenied} from '../src/site-policy.mjs';

const base = {siteId: 'site-example', tenantId: 'tenant-example', subject: 'admin-example', now: 1000};

function fakeDb() {
  const state = {pending: true, revoked: false, challenge: null, calls: []};
  const db = {
    prepare(sql) {
      return {
        bind(...args) {
          state.calls.push({sql, args});
          return {
            async first() {
              if (sql.includes('INSERT INTO central_pairing_challenges')) {
                const [subject, secretHash, expiry, siteId, tenantId] = args;
                if (!state.pending || state.revoked || state.challenge ||
                    siteId !== base.siteId || tenantId !== base.tenantId) return null;
                state.challenge = {subject, secretHash, expiry, siteId, tenantId, consumed: false};
                return {site_id: siteId};
              }
              if (sql.includes('UPDATE central_pairing_challenges')) {
                const [now, siteId, tenantId, subject, hash, checkTime] = args;
                const c = state.challenge;
                if (!state.pending || state.revoked || !c || c.consumed ||
                    c.siteId !== siteId || c.tenantId !== tenantId ||
                    c.subject !== subject || c.secretHash !== hash ||
                    c.expiry <= checkTime || now !== checkTime) return null;
                c.consumed = true;
                return {site_id: siteId};
              }
              throw new Error('Unexpected query');
            },
          };
        },
      };
    },
  };
  return {db, state};
}

test('reserves 256-bit random challenge and stores only digest', async () => {
  const {db, state} = fakeDb();
  const r = await reservePairingChallenge(db, base, webcrypto);
  assert.match(r.secret, /^[a-f0-9]{64}$/);
  assert.equal(r.expires_at, 1300);
  assert.equal(state.challenge.secretHash.length, 64);
  assert.notEqual(r.secret, state.challenge.secretHash);
  assert.ok(!JSON.stringify(state.calls).includes(r.secret));
});

test('rejects duplicate reservation and mismatched tenant', async () => {
  const {db} = fakeDb();
  await reservePairingChallenge(db, base, webcrypto);
  await assert.rejects(reservePairingChallenge(db, base, webcrypto), AccessDenied);
  const other = fakeDb();
  await assert.rejects(reservePairingChallenge(other.db, {...base, tenantId: 'other-tenant'}, webcrypto), AccessDenied);
});

test('consumes exactly once for matching identity and challenge', async () => {
  const {db} = fakeDb();
  const {secret} = await reservePairingChallenge(db, base, webcrypto);
  assert.deepEqual(await consumePairingChallenge(db, {...base, secret, now: 1001}, webcrypto), {consumed: true});
  await assert.rejects(consumePairingChallenge(db, {...base, secret, now: 1002}, webcrypto), AccessDenied);
});

test('blocks wrong secret, tenant and subject without consuming', async () => {
  const {db, state} = fakeDb();
  const {secret} = await reservePairingChallenge(db, base, webcrypto);
  for (const bad of [
    {...base, secret: 'a'.repeat(64)},
    {...base, secret, tenantId: 'other-tenant'},
    {...base, secret, subject: 'other-admin'},
  ]) {
    await assert.rejects(consumePairingChallenge(db, bad, webcrypto), AccessDenied);
  }
  assert.equal(state.challenge.consumed, false);
});

test('expires after 300 seconds and blocks revoked sites', async () => {
  const {db, state} = fakeDb();
  const {secret} = await reservePairingChallenge(db, base, webcrypto);
  await assert.rejects(consumePairingChallenge(db, {...base, secret, now: 1300}, webcrypto), AccessDenied);
  state.revoked = true;
  await assert.rejects(consumePairingChallenge(db, {...base, secret, now: 1001}, webcrypto), AccessDenied);
});

test('does not issue tokens for nonpending or revoked sites', async () => {
  const {db, state} = fakeDb();
  state.pending = false;
  await assert.rejects(reservePairingChallenge(db, base, webcrypto), AccessDenied);
  state.pending = true;
  state.revoked = true;
  await assert.rejects(reservePairingChallenge(db, base, webcrypto), AccessDenied);
});

test('rejects malformed ids, clocks, token and missing registry', async () => {
  const {db} = fakeDb();
  await assert.rejects(reservePairingChallenge(db, {...base, siteId: '../etc'}, webcrypto), AccessDenied);
  await assert.rejects(reservePairingChallenge(db, {...base, now: NaN}, webcrypto), AccessDenied);
  await assert.rejects(reservePairingChallenge(null, base, webcrypto), AccessDenied);
  await assert.rejects(consumePairingChallenge(db, {...base, secret: 'bad'}, webcrypto), AccessDenied);
});

test('never returns a site grant or activates a site', async () => {
  const {db, state} = fakeDb();
  const {secret} = await reservePairingChallenge(db, base, webcrypto);
  const result = await consumePairingChallenge(db, {...base, secret}, webcrypto);
  assert.deepEqual(result, {consumed: true});
  assert.equal(state.pending, true);
  assert.equal(state.calls.every(c => !/UPDATE central_sites|central_site_grants/i.test(c.sql)), true);
});
