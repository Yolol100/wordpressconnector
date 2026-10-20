(function () {
    'use strict';

    const config = window.WPConnectorDoctor;
    const testButton = document.getElementById('wpconnector-test-mcp');
    const copyButton = document.getElementById('wpconnector-copy-mcp');
    const result = document.getElementById('wpconnector-mcp-doctor-result');
    const address = document.getElementById('wpconnector-mcp-address');
    if (!config || !testButton || !copyButton || !result || !address) {
        return;
    }

    function show(message) {
        result.textContent = message;
    }

    copyButton.addEventListener('click', async function () {
        try {
            if (!navigator.clipboard || !navigator.clipboard.writeText) {
                throw new Error('Clipboard API unavailable');
            }
            await navigator.clipboard.writeText(address.value);
            show(config.copied);
        } catch (error) {
            address.focus();
            address.select();
            show(config.copyFailed);
        }
    });

    async function readOnlyRequest(method) {
        if (method !== 'server/discover' && method !== 'tools/list') {
            throw new Error('Unsupported MCP diagnostic method');
        }
        const envelope = {
            jsonrpc: '2.0',
            id: 'wpconnector-browser-doctor-' + method,
            method: method,
            params: {
                _meta: {
                    'io.modelcontextprotocol/protocolVersion': '2026-07-28',
                    'io.modelcontextprotocol/clientCapabilities': {}
                }
            }
        };
        const response = await fetch(config.url, {
                method: 'POST',
                mode: 'same-origin',
                credentials: 'same-origin',
                redirect: 'error',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': config.nonce,
                    'MCP-Protocol-Version': '2026-07-28',
                    'Mcp-Method': method
                },
                body: JSON.stringify(envelope)
            });
        if (!response.ok) {
            throw new Error('Authenticated MCP diagnostic request was rejected.');
        }
        return response.json();
    }

    testButton.addEventListener('click', async function () {
        testButton.disabled = true;
        show(config.checking);
        try {
            const data = await readOnlyRequest('server/discover');
            const resultData = data && data.result;
            const versions = resultData && resultData.supportedVersions;
            const isConnector = resultData && resultData._meta &&
                resultData._meta['io.modelcontextprotocol/serverInfo'] &&
                resultData._meta['io.modelcontextprotocol/serverInfo'].name ===
                    'webactueel-wordpress-connector';
            if (!Array.isArray(versions) ||
                versions.indexOf('2026-07-28') === -1 || !isConnector) {
                throw new Error('MCP discovery did not match the expected authenticated server.');
            }
            const listing = await readOnlyRequest('tools/list');
            const tools = listing && listing.result && listing.result.tools;
            const names = Array.isArray(tools) ? tools.map((tool) => tool.name) : [];
            if (!['wordpress_connector_actions', 'wordpress_connector_discover', 'wordpress_connector_execute']
                .every((name) => names.includes(name))) {
                throw new Error('MCP tool discovery did not match the expected Connector tools.');
            }
            show(config.success);
        } catch (error) {
            show(config.failed + ' ' + (
                error && typeof error.message === 'string' ?
                    error.message.slice(0, 140) : ''
            ));
        } finally {
            testButton.disabled = false;
        }
    });
}());
