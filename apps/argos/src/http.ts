import { routeAgentRequest } from "agents";

interface ChatAgent {
  ask(prompt: string): Promise<{ status: string; text?: string }>;
  messages(): Promise<unknown>;
}

export async function handleRequest(request: Request, env: Env): Promise<Response> {
  const agentResponse = await routeAgentRequest(request, env);
  if (agentResponse) return agentResponse;

  const path = new URL(request.url).pathname;
  if (path === "/health" && request.method === "GET") {
    return Response.json({ name: "Argos", status: "ok" });
  }

  const match = /^\/chats\/([a-zA-Z0-9_-]{1,128})\/messages$/.exec(path);
  if (!match) return Response.json({ error: "Not found" }, { status: 404 });
  if (request.method !== "GET" && request.method !== "POST") {
    return Response.json({ error: "Method not allowed" }, {
      status: 405, headers: { Allow: "GET, POST" },
    });
  }

  let prompt: string | undefined;
  if (request.method === "POST") {
    let body: unknown;
    try {
      body = await request.json();
    } catch {
      return Response.json({ error: "Expected a JSON body" }, { status: 400 });
    }
    if (typeof body !== "object" || body === null || !("prompt" in body) ||
        typeof body.prompt !== "string" || !body.prompt.trim() || body.prompt.length > 16_000) {
      return Response.json({ error: "prompt must be a non-empty string of at most 16000 characters" }, { status: 400 });
    }
    prompt = body.prompt.trim();
  }

  try {
    const agent = env.Argos.getByName(match[1]) as unknown as ChatAgent;
    if (prompt === undefined) {
      return Response.json({ messages: await agent.messages() });
    }
    const result = await agent.ask(prompt);
    return Response.json(result, { status: result.status === "done" ? 200 : 502 });
  } catch (error) {
    console.error("Argos request failed", error);
    return Response.json({ error: "Argos could not complete the request" }, { status: 502 });
  }
}
