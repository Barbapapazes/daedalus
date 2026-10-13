import { bindings, defineConfig, defineContainer, exports } from "cf/config";
import * as entrypoint from "./src/index" with { type: "cf-worker" };

const sandbox = defineContainer({
  name: "talos-sandbox",
  schedulingPolicy: "durable-object",
  images: { app: { dockerfile: "./Dockerfile" } },
  ssh: { enabled: false },
});

const integrationSandbox = defineContainer({
  name: "integration-sandbox",
  schedulingPolicy: "durable-object",
  images: { app: { dockerfile: "./Dockerfile" } },
  ssh: { enabled: false },
});

export default defineConfig({
  containers: [sandbox, integrationSandbox],
  worker: {
    name: "talos",
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
      Talos: bindings.durableObject({ worker: "talos", exportName: "Talos" }),
      Integrator: bindings.durableObject({ worker: "talos", exportName: "Integrator" }),
    },
    exports: {
      Talos: exports.durableObject({ storage: "sqlite", container: sandbox }),
      Integrator: exports.durableObject({ storage: "sqlite", container: integrationSandbox }),
      WorkspaceServiceProxy: exports.worker(),
      WorkspaceProxy: exports.worker(),
    },
  },
});
