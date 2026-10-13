export interface RepositoryRecord {
  id: string;
  name: string;
  description: string;
  context: string;
  artifacts_namespace: string;
  artifacts_name: string;
  artifacts_id: string | null;
  default_branch: string;
  initial_commit: string | null;
  status: "provisioning" | "ready" | "failed";
  provisioning_error: string | null;
  created_at: string;
}

export class ApiRequestError extends Error {
  constructor(readonly status: number) {
    super(status === 409 ? "Repository name or onboarding brief conflicts with an existing reservation" : "Repository API returned HTTP " + status);
    this.name = "ApiRequestError";
  }
}

export async function apiRequest<T>(env: Pick<Env, "DAEDALUS_API_URL" | "CF_ACCESS_CLIENT_ID" | "CF_ACCESS_CLIENT_SECRET">, path: string, method = "GET", body?: unknown): Promise<T> {
  let base: URL;
  try {
    if (typeof env.DAEDALUS_API_URL !== "string" || !env.DAEDALUS_API_URL.trim()) throw new Error();
    base = new URL(env.DAEDALUS_API_URL.trim());
    if (!["http:", "https:"].includes(base.protocol) || base.username || base.password) throw new Error();
  } catch {
    throw new Error("Configure DAEDALUS_API_URL as an absolute HTTP(S) URL. For local development, set DAEDALUS_API_URL=http://localhost:8000 in this Worker's .env file and restart it.");
  }
  const url = new URL("/internal/" + path, base);
  const local = ["localhost", "127.0.0.1", "[::1]"].includes(url.hostname);
  if (!local && (url.protocol !== "https:" || !env.CF_ACCESS_CLIENT_ID || !env.CF_ACCESS_CLIENT_SECRET)) {
    throw new Error("API access requires HTTPS and Cloudflare Access credentials");
  }
  const headers: Record<string, string> = { Accept: "application/json", "Content-Type": "application/json" };
  if (env.CF_ACCESS_CLIENT_ID && env.CF_ACCESS_CLIENT_SECRET) {
    headers["CF-Access-Client-ID"] = env.CF_ACCESS_CLIENT_ID;
    headers["CF-Access-Client-Secret"] = env.CF_ACCESS_CLIENT_SECRET;
  }
  const response = await fetch(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), redirect: "manual", signal: AbortSignal.timeout(15_000) });
  if (!response.ok) {
    // Do not expose upstream responses: they may contain credentials or infrastructure details.
    throw new ApiRequestError(response.status);
  }
  return await response.json() as T;
}
