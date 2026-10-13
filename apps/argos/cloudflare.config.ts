import { bindings, defineConfig, exports } from "cf/config";
import * as entrypoint from "./src/index" with { type: "cf-worker" };

export default defineConfig({
  worker: {
    name: "argos",
    entrypoint,
    workersDev: false,
    previewUrls: false,
    compatibilityDate: "2026-10-04",
    compatibilityFlags: ["nodejs_compat", "experimental"],
    env: {
      AI: bindings.ai({ dev: { remote: true } }),
      ARTIFACTS: bindings.artifacts({ namespace: "daedalus", dev: { remote: true } }),
      LOADER: bindings.workerLoader(),
      DAEDALUS_API_URL: bindings.secret(),
      CF_ACCESS_CLIENT_ID: bindings.secret(),
      CF_ACCESS_CLIENT_SECRET: bindings.secret(),
      Argos: bindings.durableObject({ worker: "argos", exportName: "Argos" }),
    },
    exports: {
      Argos: exports.durableObject({ storage: "sqlite" }),
      WorkspaceServiceProxy: exports.worker(),
    },
  },
});
