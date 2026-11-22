import { defineConfig } from "cf/config";

export default defineConfig({
  worker: {
    name: "webactueel-central-mcp",
    entrypoint: "./src/worker.mjs",
    compatibilityDate: "2026-11-22",
    compatibilityFlags: ["nodejs_compat"],
  },
});
