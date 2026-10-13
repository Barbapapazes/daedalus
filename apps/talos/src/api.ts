import type { ImplementationEvent } from "./contracts";

export async function deliverEvent(env: Env, requestId: string, event: ImplementationEvent, kind: "implementation" | "integration" = "implementation") {
  const url = new URL(`/internal/${kind}-requests/${requestId}/events`, env.DAEDALUS_API_URL);
  const local = ["localhost", "127.0.0.1", "[::1]"].includes(url.hostname);
  if (url.username || url.password || (!local && (url.protocol !== "https:" ||
      !env.CF_ACCESS_CLIENT_ID || !env.CF_ACCESS_CLIENT_SECRET))) {
    throw new Error("Callbacks require HTTPS and Cloudflare Access credentials outside localhost");
  }
  const headers: Record<string, string> = { "Content-Type": "application/json", Accept: "application/json" };
  if (env.CF_ACCESS_CLIENT_ID && env.CF_ACCESS_CLIENT_SECRET) {
    headers["CF-Access-Client-ID"] = env.CF_ACCESS_CLIENT_ID;
    headers["CF-Access-Client-Secret"] = env.CF_ACCESS_CLIENT_SECRET;
  }
  const response = await fetch(url, {
    method: "POST", headers, body: JSON.stringify(event), redirect: "manual",
    signal: AbortSignal.timeout(15_000),
  });
  if (!response.ok) throw new Error("Callback returned HTTP " + response.status);
  const receipt = await response.json() as { eventId?: string; sequence?: number };
  if (receipt.eventId !== event.eventId || receipt.sequence !== event.sequence) throw new Error("Invalid callback acknowledgement");
}
