'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'plugin/wordpressconnector/assets/connection-doctor.js'), 'utf8');
const handlers = {};
let intercepted = [];
const elements = {
    'wpconnector-test-mcp': { disabled: false, addEventListener: (event, fn) => { handlers['test:' + event] = fn; } },
    'wpconnector-copy-mcp': { addEventListener: (event, fn) => { handlers['copy:' + event] = fn; } },
    'wpconnector-copy-oauth-mcp': { addEventListener: (event, fn) => { handlers['oauth-copy:' + event] = fn; } },
    'wpconnector-oauth-mcp-address': {
        value: 'https://example.invalid/wp-json/mcp/mcp-oauth-server',
        focus: () => {},
        select: () => { intercepted.push('select-oauth'); },
    },
    'wpconnector-mcp-doctor-result': { textContent: '' },
    'wpconnector-mcp-address': {
        value: 'https://example.invalid/wp-json/webactueel-wordpress-connector/v1/mcp',
        focus: () => {},
        select: () => { intercepted.push('select'); },
    },
};
const conf = {
    url: elements['wpconnector-mcp-address'].value,
    nonce: 'test-nonce',
    checking: 'Checking',
    success: 'Connected in browser, not ChatGPT',
    failed: 'Failed',
    copied: 'Copied',
    copyFailed: 'Copy failed',
};
let fetcher = async (url, options) => {
    const method = JSON.parse(options.body).method;
    if (method === 'tools/list') {
        return {
            ok: true,
            json: async () => ({ result: { tools: [
                'wordpress_connector_actions', 'wordpress_connector_discover', 'wordpress_connector_execute'
            ].map((name) => ({ name })) } }),
        };
    }
    assert.equal(method, 'server/discover');
    return {
        ok: true,
        json: async () => ({
            result: {
                supportedVersions: ['2026-07-28'],
                _meta: { 'io.modelcontextprotocol/serverInfo': { name: 'webactueel-wordpress-connector' } },
            },
        }),
    };
};
vm.runInNewContext(source, {
    window: { WPConnectorDoctor: conf },
    document: { getElementById: (id) => elements[id] },
    navigator: { clipboard: { writeText: async (x) => { intercepted.push(x); } } },
    fetch: (...args) => fetcher(...args),
});
assert.equal(typeof handlers['test:click'], 'function');
assert.equal(typeof handlers['copy:click'], 'function');
assert.equal(typeof handlers['oauth-copy:click'], 'function');

(async () => {
    await handlers['copy:click']();
    assert.equal(intercepted[0], conf.url);
    await handlers['oauth-copy:click']();
    assert.equal(intercepted[1], elements['wpconnector-oauth-mcp-address'].value);
    assert.equal(elements['wpconnector-mcp-doctor-result'].textContent, 'Copied');
    // A site without the optional official Adapter must keep the legacy tool usable.
    const withoutAdapter = { ...elements };
    delete withoutAdapter['wpconnector-copy-oauth-mcp'];
    delete withoutAdapter['wpconnector-oauth-mcp-address'];
    vm.runInNewContext(source, {
        window: { WPConnectorDoctor: conf },
        document: { getElementById: (id) => withoutAdapter[id] },
        navigator: { clipboard: { writeText: async (x) => { intercepted.push(x); } } },
        fetch: (...args) => fetcher(...args),
    });
    assert.equal(typeof handlers['copy:click'], 'function');
    assert.equal(typeof handlers['test:click'], 'function');
    await handlers['test:click']();
    assert.equal(elements['wpconnector-test-mcp'].disabled, false);
    assert.equal(elements['wpconnector-mcp-doctor-result'].textContent, conf.success);

    fetcher = async (url, options) => {
        assert.equal(url, conf.url);
        assert.equal(options.method, 'POST');
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers['X-WP-Nonce'], 'test-nonce');
        const params = JSON.parse(options.body);
        assert.equal(params.method, 'server/discover');
        assert.deepEqual(params.params._meta['io.modelcontextprotocol/clientCapabilities'], {});
        return { ok: false, json: async () => ({ code: 'rest_forbidden' }) };
    };
    await handlers['test:click']();
    assert.match(elements['wpconnector-mcp-doctor-result'].textContent, /^Failed/);
    assert.equal(elements['wpconnector-test-mcp'].disabled, false);

    fetcher = async (url, options) => {
        const method = JSON.parse(options.body).method;
        if (method === 'tools/list') return { ok: true, json: async () => ({ result: { tools: [] } }) };
        return { ok: true, json: async () => ({
            result: { supportedVersions: ['2026-07-28'],
                _meta: { 'io.modelcontextprotocol/serverInfo': { name: 'webactueel-wordpress-connector' } } },
        }) };
    };
    await handlers['test:click']();
    assert.match(elements['wpconnector-mcp-doctor-result'].textContent, /^Failed/);

    fetcher = async () => { throw new Error('Network blocked'); };
    await handlers['test:click']();
    assert.match(elements['wpconnector-mcp-doctor-result'].textContent, /Network blocked/);
    assert.equal(elements['wpconnector-test-mcp'].disabled, false);
    console.log('MCP browser doctor JS: success, 401 and WAF failure paths OK');
})().catch((error) => { console.error(error); process.exitCode = 1; });
