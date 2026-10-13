import { Type } from "@earendil-works/pi-ai";
import { defineTool } from "@earendil-works/pi-durable";
import type { RepositorySnapshot } from "./repository";
import { apiRequest, type RepositoryRecord } from "./api";

export function createImplementFeatureTool(
  getConversationId: () => string,
  getMessages: () => Promise<unknown>,
  env: Pick<Env, "DAEDALUS_API_URL" | "CF_ACCESS_CLIENT_ID" | "CF_ACCESS_CLIENT_SECRET">,
  getRepositories: () => Promise<RepositorySnapshot[]>,
) {
  return defineTool({
    name: "implement_feature",
    description: "Request implementation of one agreed feature, using its intent and this conversation as context for automated implementation and review. Only call after summarizing the intended scope and receiving explicit user approval to implement it. In conversation, ask whether the user wants to implement the feature, not whether they want to submit an intent; the handoff is an implementation detail. Include a self-contained goal, scope, constraints, and acceptance criteria; do not invent requirements. Select one to eight ready loaded Artifacts repositories, explicitly agreed with the user; each inspected commit becomes its immutable implementation base. For cross-repository work, summarize and obtain approval for all target projects together. Stores the request in Daedalus and queues Talos to implement it on a dedicated branch. Describe the returned tracking status accurately; queued does not mean execution or review has begun.",
    parameters: Type.Object({
      repositories: Type.Optional(Type.Array(Type.String({ minLength: 1, maxLength: 128 }), { minItems: 1, maxItems: 8, description: "Exact names of all ready loaded Artifacts repositories approved by the user. Use this for single-project and cross-project work." })),
      repository: Type.Optional(Type.String({ minLength: 1, maxLength: 128, description: "Legacy single-project selection. Provide repositories instead; never provide both." })),
      intent: Type.Object({
        goal: Type.String({ minLength: 1 }),
        scope: Type.String({ minLength: 1 }),
        constraints: Type.Array(Type.String({ minLength: 1 })),
        acceptanceCriteria: Type.Array(Type.String({ minLength: 1 }), { minItems: 1 }),
      }),
    }),
    // The ID and snapshot are durable, and Laravel deduplicates every delivery.
    replay: "safe",
    executionMode: "sequential",
    execute: async ({ intent, repository, repositories }, api, context) => {
      // Resolve the named Agent identity after lifecycle startup, not its opaque DO ID.
      const conversationId = getConversationId();
      const requestId = await api.memo<string>("implementation-request-id", context)
        ?? await api.memo("implementation-request-id", crypto.randomUUID(), context);
      // Recovery must resend the original conversation, not a newer snapshot.
      let body = await api.memo<string>("implementation-request-body", context);

      try {
        if (!body) {
          if (repository && repositories) throw new Error("Provide repositories, not both selection formats");
          const names = repositories ?? (repository ? [repository] : []);
          if (!names.length || names.length > 8 || new Set(names.map(name => name.toLowerCase())).size !== names.length) {
            throw new Error("Select one to eight distinct, explicitly approved repositories");
          }
          const loaded = await getRepositories();
          const assignments = await Promise.all(names.map(async name => {
            const snapshot = loaded.find(item => item.name === name && item.ready);
            if (!snapshot) throw new Error(`Load the agreed repository ${name} successfully before requesting implementation`);
            const project = await apiRequest<RepositoryRecord>(env, "repositories/by-name/" + encodeURIComponent(snapshot.name));
            if (project.status !== "ready") throw new Error(`Repository ${name} is not ready`);
            return { name: snapshot.name, baseRef: snapshot.ref, baseCommit: snapshot.commit, projectContext: JSON.stringify({ description: project.description, context: project.context }) };
          }));
          body = await api.memo("implementation-request-body", JSON.stringify({
            requestId, conversationId, toolCallId: api.callId, toolTaskId: String(api.taskId), intent,
            repositories: assignments,
            messages: await getMessages(),
          }), context);
        }
        const url = new URL("/internal/implementation-requests", env.DAEDALUS_API_URL);
        const local = ["localhost", "127.0.0.1", "[::1]"].includes(url.hostname);
        if (!local && (url.protocol !== "https:" || !env.CF_ACCESS_CLIENT_ID || !env.CF_ACCESS_CLIENT_SECRET)) {
          throw new Error("Production handoffs require HTTPS and Cloudflare Access credentials");
        }
        const headers: Record<string, string> = {
          "Content-Type": "application/json",
          Accept: "application/json",
        };
        if (env.CF_ACCESS_CLIENT_ID && env.CF_ACCESS_CLIENT_SECRET) {
          headers["CF-Access-Client-ID"] = env.CF_ACCESS_CLIENT_ID;
          headers["CF-Access-Client-Secret"] = env.CF_ACCESS_CLIENT_SECRET;
        }

        let response: Response | undefined;
        // A lost acknowledgement may already have persisted the request. Retry the same body/ID.
        for (let attempt = 0; attempt < 3; attempt++) {
          try {
            response = await fetch(url, {
              method: "POST", headers, body, redirect: "manual",
              signal: AbortSignal.timeout(15_000),
            });
          } catch (error) {
            if (attempt === 2) throw error;
            continue;
          }
          if (response.status < 500 && response.status !== 429) break;
          if (attempt < 2) await response.body?.cancel();
        }
        if (!response || !response.ok) {
          throw new Error(`Implementation intake returned HTTP ${response?.status ?? "unknown"}`);
        }
        const result: unknown = await response.json();
        if (!result || typeof result !== "object" || !("requestId" in result) ||
            result.requestId !== requestId || !("accepted" in result) || result.accepted !== true ||
            !("status" in result) || typeof result.status !== "string" ||
            !["received", "queued", "running", "completed", "blocked", "failed"].includes(result.status)) {
          throw new Error("Implementation intake returned an invalid acknowledgement");
        }
        console.log("argos:implement_feature", JSON.stringify({ requestId, conversationId, status: result.status }));
        return {
          content: [{ type: "text", text: JSON.stringify({
            requestId,
            status: result.status,
            note: "Daedalus confirmed receipt of the approved intent and all repository assignments. Report the returned tracking status; do not imply review, merge, or deployment.",
          }) }],
        };
      } catch (error) {
        console.error("argos:implement_feature handoff failed", { requestId, conversationId, error });
        return {
          isError: true,
          content: [{ type: "text", text: JSON.stringify({
            requestId,
            status: "handoff_unconfirmed",
            note: "Could not confirm receipt. The request may already be stored under this tracking ID. Do not claim implementation has started or submit a new request automatically.",
          }) }],
        };
      }
    },
  });
}
