import {bindings, defineConfig} from 'cf/config';

export default defineConfig({
  worker: {
    name: 'webactueel-central-auth',
    entrypoint: './src/worker.mjs',
    compatibilityDate: '2026-11-22',
    compatibilityFlags: ['nodejs_compat', 'global_fetch_strictly_public'],
    env: {
      OAUTH_KV: bindings.kv({id: '9112d5d0c4b34e0ba8e28ae6737506e4'}),
      ISSUER: bindings.text('https://webactueel-central-auth.andrew-baeten.workers.dev'),
      MCP_SERVER_URL: bindings.text('https://webactueel-central-mcp.andrew-baeten.workers.dev/mcp'),
      TENANT_ID: bindings.text('team-webactueel'),
      GITHUB_ALLOWED_USER_ID: bindings.text('22932777'),
      GITHUB_CLIENT_ID: bindings.secret(),
      GITHUB_CLIENT_SECRET: bindings.secret(),
    },
  },
});
