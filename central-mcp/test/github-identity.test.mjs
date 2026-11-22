import test from 'node:test';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {
  loginConfig, loadLoginConfig, newPkceVerifier, githubAuthorizeUrl, verifiedGithubPrincipal,
} from '../auth/src/github-identity.mjs';

const env = {
  ISSUER: 'https://webactueel-central-auth.andrew-baeten.workers.dev',
  MCP_SERVER_URL: 'https://webactueel-central-mcp.andrew-baeten.workers.dev/mcp',
  GITHUB_CLIENT_ID: 'Ov23liExampleClient01',
  GITHUB_CLIENT_SECRET: 'this-is-a-test-secret-not-real',
  GITHUB_ALLOWED_USER_ID: '22932777',
  TENANT_ID: 'team-webactueel',
  OAUTH_KV: {get(){}, put(){}},
};
const config = loginConfig(env);
const verifier = '0'.repeat(64);

function mockFetch(id=22932777, token='gho_test_user_token_1234567890') {
  const calls=[];
  async function fetchFn(url, options) {
    calls.push({url, options});
    if (url.endsWith('/access_token')) {
      return {ok:true, async text(){return JSON.stringify({access_token:token,token_type:'bearer'});}};
    }
    if (url === 'https://api.github.com/user') {
      return {ok:true, async text(){return JSON.stringify({id});}};
    }
    throw Error('Unexpected destination');
  }
  return {fetchFn,calls};
}

test('OAuth configuration fails closed when missing credentials, KV, tenant, URL, identity', () => {
  const invalid = [
    {...env, GITHUB_CLIENT_SECRET:''},
    {...env, GITHUB_ALLOWED_USER_ID:'someone-else'},
    {...env, TENANT_ID:'../wrong'},
    {...env, OAUTH_KV:null},
    {...env, ISSUER:'https://attacker.example'},
    {...env, MCP_SERVER_URL:'http://localhost/mcp'},
    {...env, GITHUB_CLIENT_ID:'?'},
  ];
  for(const candidate of invalid) assert.throws(() => loginConfig(candidate), /unavailable/);
  assert.equal(config.callback, env.ISSUER + '/callback');
});

test('GitHub authorization URL is exact, PKCE-S256, and has minimal scope', async () => {
  const state='very-long-nonce-for-browser-bound-state-123';
  const url = new URL(await githubAuthorizeUrl(config, state, verifier, webcrypto));
  assert.equal(url.origin,'https://github.com');
  assert.equal(url.pathname,'/login/oauth/authorize');
  assert.equal(url.searchParams.get('client_id'),env.GITHUB_CLIENT_ID);
  assert.equal(url.searchParams.get('redirect_uri'),config.callback);
  assert.equal(url.searchParams.get('code_challenge_method'),'S256');
  assert.equal(url.searchParams.get('code_challenge').length,43);
  assert.equal(url.searchParams.get('state'),state);
  assert.equal(url.searchParams.get('scope'),'read:user');
});

test('PKCE verifier uses cryptographically random 32 bytes', async () => {
  const a=newPkceVerifier(webcrypto), b=newPkceVerifier(webcrypto);
  assert.match(a,/^[a-f0-9]{64}$/);
  assert.notEqual(a,b);
  await assert.rejects(githubAuthorizeUrl(config,'invalid',verifier,webcrypto),/unavailable/);
});

test('GitHub user comes from token-protected API and never leaves user token in props', async () => {
  const {fetchFn,calls} = mockFetch();
  const principal=await verifiedGithubPrincipal(config,'authcode-abcdefgh',verifier,fetchFn);
  assert.deepEqual(principal,{userId:'22932777',tenantId:'team-webactueel'});
  assert.equal(calls.length,2);
  assert.equal(calls[0].url,'https://github.com/login/oauth/access_token');
  assert.equal(calls[0].options.body.get('code_verifier'),verifier);
  assert.equal(calls[1].url,'https://api.github.com/user');
  assert.match(calls[1].options.headers.Authorization,/^Bearer gho_/);
  assert.ok(!JSON.stringify(principal).includes('gho_'));
});

test('Wrong GitHub identity and missing codes are rejected', async () => {
  await assert.rejects(verifiedGithubPrincipal(config,'authcode-abcdefgh',verifier,mockFetch(222333444).fetchFn),/unavailable/);
  await assert.rejects(verifiedGithubPrincipal(config,'../../etc',verifier,mockFetch().fetchFn),/unavailable/);
  await assert.rejects(verifiedGithubPrincipal(config,'authcode-abcdefgh','x',mockFetch().fetchFn),/unavailable/);
});

test('Malformed API responses and missing bearer token fail closed', async () => {
  await assert.rejects(verifiedGithubPrincipal(config,'authcode-abcdefgh',verifier,mockFetch(22932777,'short').fetchFn),/unavailable/);
  const fetchFn=async()=>({ok:false,async text(){return 'Unauthorized';}});
  await assert.rejects(verifiedGithubPrincipal(config,'authcode-abcdefgh',verifier,fetchFn),/unavailable/);
  const huge=async()=>({ok:true,async text(){return 'x'.repeat(20000);}});
  await assert.rejects(verifiedGithubPrincipal(config,'authcode-abcdefgh',verifier,huge),/unavailable/);
});


test('Cloudflare Secrets Store binding loads only inside Worker and fails closed', async () => {
  const storedSecret = 'test-only-value-that-must-not-leak';
  const valid = {...env, GITHUB_CLIENT_SECRET: {async get() { return storedSecret; }}};
  const config = await loadLoginConfig(valid);
  assert.equal(config.clientSecret, storedSecret);
  await assert.rejects(loadLoginConfig(env), /unavailable/);
  await assert.rejects(loadLoginConfig({...env, GITHUB_CLIENT_SECRET: null}), /unavailable/);
  await assert.rejects(loadLoginConfig({...env, GITHUB_CLIENT_SECRET: {async get() { throw new Error('internal secret details'); }}}), /unavailable/);
  await assert.rejects(loadLoginConfig({...env, GITHUB_CLIENT_SECRET: {async get() { return 'short'; }}}), /unavailable/);
});
