import { bindings, defineConfig } from "cf/config";

export default defineConfig({
  worker: {
    name: "webactueel-central-mcp",
    entrypoint: "./src/worker.mjs",
    compatibilityDate: "2026-11-22",
    compatibilityFlags: ["nodejs_compat"],
    env: {
      REGISTRY: bindings.d1({
        name: "webactueel-central-mcp-registry",
        id: "23eaa16d-61f1-4c69-bf15-fb189fee6e7d",
      }),
    },
  },
});
