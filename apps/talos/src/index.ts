import { assignmentSchema } from "./contracts";
import type { Talos } from "./talos";
export { Talos } from "./talos";
import { integrationAssignmentSchema } from "./integration-contracts";
import type { Integrator } from "./integrator";
export { Integrator } from "./integrator";
export { WorkspaceServiceProxy, WorkspaceProxy } from "@cloudflare/computer";

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const path = new URL(request.url).pathname;
    if (path === "/health" && request.method === "GET") {
      return Response.json({ name: "Talos", status: "ok" });
    }
    if (path === "/internal/integrations" || /^\/internal\/integrations\/[0-9a-f-]{36}$/i.test(path)) {
      return integrationIngress(request, env, path);
    }
    // Read-only observation uses the same Access-protected internal ingress.
    const match = /^\/internal\/implementations\/([0-9a-f-]{36})$/i.exec(path);
    if (match) {
      if (request.method !== "GET") return Response.json({ error: "Method not allowed" }, { status: 405, headers: { Allow: "GET" } });
      const requestId = assignmentSchema.shape.requestId.safeParse(match[1]);
      if (!requestId.success) return Response.json({ error: "Invalid request ID" }, { status: 400 });
      try {
        const agent = env.Talos.getByName(requestId.data) as unknown as Pick<Talos, "implementation">;
        const snapshot = await agent.implementation();
        return snapshot
          ? Response.json(snapshot, { headers: { "Cache-Control": "no-store" } })
          : Response.json({ error: "Implementation not found" }, { status: 404 });
      } catch (error) {
        console.error("talos:observation failed", { requestId: requestId.data, error });
        return Response.json({ error: "Implementation could not be loaded" }, { status: 502 });
      }
    }
    // No general agent routing, chat, streaming or public Computer access.
    if (path !== "/internal/implementations") return Response.json({ error: "Not found" }, { status: 404 });
    if (request.method !== "POST") return Response.json({ error: "Method not allowed" }, { status: 405, headers: { Allow: "POST" } });
    // Production ingress must be protected by a Cloudflare Access service-token policy,
    // including alternate Worker URLs. Local development does not require Access.
    let input: unknown;
    try {
      input = JSON.parse(await boundedBody(request));
    } catch (error) {
      return Response.json({ error: error instanceof RangeError ? "Assignment too large" : "Expected JSON" }, {
        status: error instanceof RangeError ? 413 : 400,
      });
    }
    const parsed = assignmentSchema.safeParse(input);
    if (!parsed.success) return Response.json({ error: "Invalid assignment", issues: parsed.error.issues }, { status: 422 });
    try {
      const agent = env.Talos.getByName(parsed.data.requestId) as unknown as Pick<Talos, "receive">;
      const result = await agent.receive(parsed.data);
      return Response.json(result, { status: 202 });
    } catch (error) {
      console.error("talos:intake failed", { requestId: parsed.data.requestId, error });
      return Response.json({ error: "Assignment could not be accepted" }, { status: 502 });
    }
  },
} satisfies ExportedHandler<Env>;

async function integrationIngress(request: Request, env: Env, path: string): Promise<Response> {
  const id = path.slice("/internal/integrations/".length);
  const observation = path !== "/internal/integrations";
  const method = observation ? "GET" : "POST";
  if (request.method !== method) return Response.json({ error: "Method not allowed" }, { status: 405, headers: { Allow: method } });
  try {
    if (observation) {
      const parsed = integrationAssignmentSchema.shape.requestId.safeParse(id);
      if (!parsed.success) return Response.json({ error: "Invalid request ID" }, { status: 400 });
      const agent = env.Integrator.getByName(parsed.data) as unknown as Pick<Integrator, "integration">;
      const snapshot = await agent.integration();
      return snapshot ? Response.json(snapshot, { headers: { "Cache-Control": "no-store" } }) : Response.json({ error: "Integration not found" }, { status: 404 });
    }
    let input: unknown;
    try { input = JSON.parse(await boundedBody(request)); }
    catch (error) { return Response.json({ error: "Invalid or oversized assignment" }, { status: error instanceof RangeError ? 413 : 400 }); }
    const parsed = integrationAssignmentSchema.safeParse(input);
    if (!parsed.success) return Response.json({ error: "Invalid assignment", issues: parsed.error.issues }, { status: 422 });
    const agent = env.Integrator.getByName(parsed.data.requestId) as unknown as Pick<Integrator, "receive">;
    return Response.json(await agent.receive(parsed.data), { status: 202 });
  } catch (error) {
    console.error("integration:ingress failed", { error });
    return Response.json({ error: "Integration could not be loaded or accepted" }, { status: 502 });
  }
}

async function boundedBody(request: Request): Promise<string> {
  const limit = 2 * 1024 * 1024 - 4096;
  if (Number(request.headers.get("Content-Length")) > limit) throw new RangeError("Assignment too large");
  if (!request.body) throw new SyntaxError("Missing body");
  const reader = request.body.getReader();
  const chunks: Uint8Array[] = [];
  let size = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > limit) {
        await reader.cancel();
        throw new RangeError("Assignment too large");
      }
      chunks.push(value);
    }
  } finally {
    reader.releaseLock();
  }
  const bytes = new Uint8Array(size);
  let offset = 0;
  for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.byteLength; }
  return new TextDecoder("utf-8", { fatal: true, ignoreBOM: false }).decode(bytes);
}
