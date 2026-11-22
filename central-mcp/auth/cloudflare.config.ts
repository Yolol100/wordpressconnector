import { bindings, defineConfig } from "cf/config";

// Staging-ready configuration; never deploy before GitHub OAuth credentials,
// administrator enrollment, and a reviewed authorization flow are present.
export default defineConfig({
  worker: {
    name: "webactueel-central-mcp-auth",
    entrypoint: "../src/authorization-server.mjs",
    compatibilityDate: "2026-11-22",
    compatibilityFlags: ["nodejs_compat", "global_fetch_strictly_public"],
    env: {
      ISSUER: bindings.text("https://webactueel-central-mcp-auth.andrew-baeten.workers.dev"),
      MCP_SERVER_URL: bindings.text("https://webactueel-central-mcp.andrew-baeten.workers.dev/mcp"),
      OAUTH_KV: bindings.kv({id: "9112d5d0c4b34e0ba8e28ae6737506e4"}),
      REGISTRY: bindings.d1({
        name: "webactueel-central-mcp-registry",
        id: "23eaa16d-61f1-4c69-bf15-fb189fee6e7d",
      }),
      GITHUB_CLIENT_ID: bindings.secret(),
      GITHUB_CLIENT_SECRET: bindings.secret(),
    },
  },
});
