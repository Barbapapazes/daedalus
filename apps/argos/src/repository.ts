import type { Workspace } from "@cloudflare/computer";
import type { GitClient } from "@cloudflare/computer/git";

const REPOSITORIES_PATH = "/workspace/repos";

export interface RepositorySnapshot {
  name: string;
  ref: string;
  commit: string;
  path: string;
  ready: boolean;
}

export function validateRepository(name: string, ref: string) {
  if (typeof name !== "string" || !/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/.test(name)) {
    throw new Error("Invalid Artifacts repository name");
  }
  if (typeof ref !== "string" || !ref.trim() || ref.length > 256) {
    throw new Error("ref must be a non-empty string of at most 256 characters");
  }
}

export async function resolveRepository(artifacts: Artifacts, name: string, ref: string): Promise<RepositorySnapshot> {
  validateRepository(name, ref);
  using repo = await artifacts.get(name);
  // Artifacts can advertise a default branch while its HEAD ref is unresolved.
  const resolvedRef = ref === "HEAD" ? (await repo.info()).defaultBranch : ref;
  const [commit] = await repo.log({ ref: resolvedRef, limit: 1 });
  if (!commit) throw new Error(`Repository ${name} ref ${resolvedRef} does not exist or has no commits`);
  if (!/^[a-f0-9]{40,64}$/i.test(commit.hash)) throw new Error("Invalid repository commit hash");
  return {
    name, ref, commit: commit.hash,
    path: `${REPOSITORIES_PATH}/${name}`, ready: false,
  };
}

/** Replace the local checkout, discarding any existing files and edits. */
export async function cloneRepository(
  artifacts: Artifacts,
  fs: Workspace["fs"],
  git: GitClient,
  snapshot: RepositorySnapshot,
): Promise<void> {
  using repo = await artifacts.get(snapshot.name);
  const remote = new URL((await repo.info()).remote);
  if (remote.protocol !== "https:" || remote.username || remote.password) {
    throw new Error("Artifacts must provide an HTTPS remote without embedded credentials");
  }
  const token = await repo.createToken("read", 600);
  try {
    await fs.mkdir(REPOSITORIES_PATH, { recursive: true });
    await fs.rm(snapshot.path, { recursive: true, force: true });
    await git.clone({
      url: remote.href,
      dir: snapshot.path,
      singleBranch: false,
      noTags: false,
      // Never persist credentials in the remote URL or Git config.
      headers: { Authorization: `Basic ${btoa(`x:${token.plaintext}`)}` },
    });
    await git.checkout({ dir: snapshot.path, ref: snapshot.commit, force: true });
  } catch (error) {
    // Remove partial clones so the next load starts cleanly.
    await Promise.allSettled([fs.rm(snapshot.path, { recursive: true, force: true })]);
    throw error;
  } finally {
    // Preserve the original failure if revocation fails; the token also expires.
    await Promise.allSettled([repo.revokeToken(token.plaintext)]);
  }
}
