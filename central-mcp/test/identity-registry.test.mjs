import test from 'node:test';
import assert from 'node:assert/strict';
import {resolveGitHubPrincipal} from '../src/identity-registry.mjs';
import {AccessDenied} from '../src/site-policy.mjs';

const token = 'gho_' + 'x'.repeat(32);
const approved = {tenant_id: 'team-webactueel', subject: 'user-admin'};
const ok = (data = {id: 22932777}, headers = {'content-type': 'application/json'}) =>
  new Response(JSON.stringify(data), {status: 200, headers});

function dbFor(rows = [approved]) {
  const history = [];
  return {
    history,
    db: {
      prepare(sql) {
        history.push({sql});
        assert.match(sql, /provider\s*=\s*\?/);
        assert.match(sql, /provider_subject\s*=\s*\?/);
        assert.match(sql, /approved_at IS NOT NULL/);
        assert.match(sql, /revoked_at IS NULL/);
        return {bind(...params) {
          history.push({params});
          return {async all() {return {results: rows};}};
        }};
      },
    },
  };
}

test('only verified GitHub API /user identity can resolve membership', async () => {
  const {db, history} = dbFor();
  let calls = 0;
  const result = await resolveGitHubPrincipal(db, token, async (url, init) => {
    calls++;
    assert.equal(url, 'https://api.github.com/user');
    assert.equal(init.redirect, 'error');
    assert.equal(init.method, 'GET');
    assert.equal(init.headers.Authorization, 'Bearer ' + token);
    return ok();
  });
  assert.equal(calls, 1);
  assert.deepEqual(result, {tenantId: approved.tenant_id, userId: approved.subject});
  assert.deepEqual(history[1].params, ['github', '22932777']);
  assert.equal(JSON.stringify(history).includes(token), false);
});

test('rejects missing token or storage without provider call', async () => {
  const {db} = dbFor();
  let called = 0;
  const stub = async () => {called++; return ok();};
  for (const candidate of [null, '', 'not valid', 'gho_a']) {
    await assert.rejects(resolveGitHubPrincipal(db, candidate, stub), AccessDenied);
  }
  await assert.rejects(resolveGitHubPrincipal(null, token, stub), AccessDenied);
  assert.equal(called, 0);
});

test('rejects upstream HTTP failures, redirects, and non-JSON replies', async () => {
  const {db} = dbFor();
  for (const response of [
    new Response('{}', {status:401, headers:{'content-type':'application/json'}}),
    new Response('{}', {status:200, headers:{'content-type':'text/plain'}}),
    ok({id: 0}),
    ok({id: '22932777'}),
    ok({id: 2.5}),
    new Response('bad JSON', {status:200,headers:{'content-type':'application/json'}}),
  ]) {
    await assert.rejects(resolveGitHubPrincipal(db, token, async () => response), AccessDenied);
  }
});

test('rejects oversized provider response without querying site membership', async () => {
  const {db,history} = dbFor();
  await assert.rejects(resolveGitHubPrincipal(db, token, async () =>
    new Response(JSON.stringify({id:22932777, filler:'x'.repeat(17000)}), {headers:{'content-type':'application/json'}})
  ), AccessDenied);
  assert.equal(history.length, 0);
});

test('refuses unknown, unapproved, revoked and ambiguous links', async () => {
  for (const rows of [[], [approved, {...approved,tenant_id:'other-team'}], [{...approved,tenant_id:'bad/tenant'}]]) {
    const {db} = dbFor(rows);
    await assert.rejects(resolveGitHubPrincipal(db, token, async () => ok()), AccessDenied);
  }
});

test('provider network errors fail closed', async () => {
  const {db} = dbFor();
  await assert.rejects(resolveGitHubPrincipal(db,token,async () => {throw new Error('offline');}),AccessDenied);
});
