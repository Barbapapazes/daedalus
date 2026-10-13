import { handleRequest } from "./http";
export { Argos } from "./argos";
export { WorkspaceServiceProxy } from "@cloudflare/computer";

export default {
  fetch: handleRequest,
} satisfies ExportedHandler<Env>;
