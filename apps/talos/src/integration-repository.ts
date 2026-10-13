import { createGitClient, type GitClient, type GitClientFactory } from "@cloudflare/computer/git";
import type { Workspace } from "@cloudflare/computer";
import { posix } from "node:path";
import { withToken } from "./repository";
import {
  integrationBranch, integrationCheckout, integrationCommitMessage, integrationMessagePath,
  type IntegrationAssignment, type IntegrationReceipt, type IntegrationRepository,
} from "./integration-contracts";

export const receiptKey = (repo: IntegrationRepository) => `integrated:${repo.name}`;
const pendingKey = (repo: IntegrationRepository) => `pending:${repo.name}`;
const baseKey = (repo: IntegrationRepository) => `trunk:${repo.name}`;

/** Offline local Git only. Squash merging uses native sandbox Git, which has no credentials. */
export function integrationGit(host: { prepare(): Promise<void>; assignment(): IntegrationAssignment }): GitClientFactory {
  const factory = createGitClient();
  const commands = new Set(["diff", "status", "log", "show", "rev-parse", "ls-files", "ls-tree", "cat-file", "add", "rm", "commit", "branch", "checkout", "switch", "reset", "clean", "config"]);
  return options => {
    const client = factory({ ...options, defaultIdentity: { name: "Daedalus Integration", email: "integration@daedalus.invalid" } });
    return new Proxy(client, {
      get(target, property) {
        if (property === "then") return undefined;
        if (property !== "cli") return async () => { throw new Error("Use exec for local Git operations"); };
        return async (input: Parameters<GitClient["cli"]>[0]) => {
          try {
            await host.prepare();
            const argv = [...input.argv];
            let dir = input.cwd ?? "/";
            while (argv[0] === "-C") {
              argv.shift();
              const path = argv.shift();
              if (!path) throw new Error("git -C requires a directory");
              dir = posix.resolve(dir, path);
            }
            const root = await target.repoRoot({ dir });
            if (!host.assignment().repositories.some(repo => integrationCheckout(repo) === root)) throw new Error("Use an assigned checkout");
            if (!commands.has(argv[0] ?? "")) throw new Error("Use sandbox for git merge --squash; use refresh_repository and publish_integration for authenticated operations");
            return await target.cli({ ...input, cwd: dir, argv });
          } catch (error) {
            return { stdout: "", stderr: `${error instanceof Error ? error.message : "Local Git failed"}\n`, exitCode: 1 };
          }
        };
      },
    });
  };
}

async function ancestor(git: GitClient, dir: string, commit: string, head: string, stopAt?: string): Promise<boolean> {
  const queue = [head];
  const visited = new Set<string>();
  while (queue.length) {
    const ref = queue.pop()!;
    if (ref === commit) return true;
    if (ref === stopAt || visited.has(ref)) continue;
    visited.add(ref);
    const [entry] = await git.log({ dir, ref, depth: 1 });
    if (entry) queue.push(...entry.parent);
  }
  return false;
}

async function remoteMain(artifacts: Artifacts, git: GitClient, repo: IntegrationRepository): Promise<string> {
  try {
    return await withToken(artifacts, repo.name, "read", async (url, headers) => {
      const fetched = await git.fetch({ url, headers, dir: integrationCheckout(repo), ref: "main", remoteRef: "main", singleBranch: true, tags: false });
      if (!fetched.fetchHead) throw new Error("Missing main");
      return fetched.fetchHead;
    });
  } catch {
    throw new Error(`Could not inspect ${repo.name}:main; retry without changing a pending candidate`);
  }
}

export async function integrationReceipts(assignment: IntegrationAssignment, storage: DurableObjectStorage) {
  const receipts: IntegrationReceipt[] = [];
  for (const repo of assignment.repositories) {
    const receipt = await storage.get<IntegrationReceipt>(receiptKey(repo));
    if (receipt) receipts.push(receipt);
  }
  return receipts;
}

export async function hasPendingPublication(assignment: IntegrationAssignment, storage: DurableObjectStorage) {
  for (const repo of assignment.repositories) if (await storage.get(pendingKey(repo))) return true;
  return false;
}

/** Refresh is an explicit destructive restart, except when an ambiguous push needs recovery. */
export async function refreshIntegrationRepository(artifacts: Artifacts, workspace: Workspace, assignment: IntegrationAssignment, repo: IntegrationRepository, storage: DurableObjectStorage) {
  const receipt = await storage.get<IntegrationReceipt>(receiptKey(repo));
  if (receipt) return { integrated: true, receipt };
  const git = createGitClient()({ ws: workspace });
  const dir = integrationCheckout(repo);
  const previousBase = await storage.get<string>(baseKey(repo));
  if (previousBase) {
    const head = await remoteMain(artifacts, git, repo);
    const pending = await storage.get<IntegrationReceipt>(pendingKey(repo));
    if (pending) {
      if (await ancestor(git, dir, pending.commit, head, pending.baseCommit)) {
        await storage.put(receiptKey(repo), pending);
        await storage.delete(pendingKey(repo));
        return { integrated: true, receipt: pending };
      }
      if (head === pending.baseCommit) return { pending: true, message: "Retry publish_integration; the same candidate remains frozen" };
      // The candidate was not integrated and trunk moved. Safe to rebuild, never force push.
      await storage.delete(pendingKey(repo));
    }
  }
  await storage.delete(baseKey(repo));
  await workspace.fs.rm(dir, { recursive: true, force: true });
  await workspace.fs.mkdir(dir, { recursive: true });
  let head: string;
  try {
    await withToken(artifacts, repo.name, "read", (url, headers) => git.clone({ url, headers, dir, singleBranch: false, noTags: false }));
    head = await git.revParse({ dir, ref: "refs/remotes/origin/main" });
    if (await git.revParse({ dir, ref: `refs/remotes/origin/${repo.sourceBranch}` }) !== repo.sourceCommit) throw new Error("Source changed");
    if (!await ancestor(git, dir, repo.baseCommit, repo.sourceCommit)) throw new Error("Invalid source ancestry");
    await git.checkout({ dir, ref: head, force: true });
  } catch {
    throw new Error(`Could not prepare ${repo.name}; main and the exact published implementation must exist in Artifacts`);
  }
  await workspace.fs.mkdir("/workspace/context", { recursive: true });
  await workspace.fs.writeFile(integrationMessagePath(repo), integrationCommitMessage(assignment, repo));
  await storage.put(baseKey(repo), head);
  return { repository: repo.name, cwd: dir, baseCommit: head, sourceCommit: repo.sourceCommit, branch: integrationBranch(assignment), commitMessagePath: integrationMessagePath(repo) };
}

/** Check a single squash commit and checkpoint it BEFORE network I/O. Never expose credentials. */
export async function publishIntegration(artifacts: Artifacts, workspace: Workspace, assignment: IntegrationAssignment, repo: IntegrationRepository, storage: DurableObjectStorage) {
  const existing = await storage.get<IntegrationReceipt>(receiptKey(repo));
  if (existing) return existing;
  const git = createGitClient()({ ws: workspace });
  const dir = integrationCheckout(repo);
  const baseCommit = await storage.get<string>(baseKey(repo));
  const commit = await git.revParse({ dir, ref: "HEAD" });
  const [entry] = await git.log({ dir, ref: commit, depth: 1 });
  if (!baseCommit || !entry || entry.parent.length !== 1 || entry.parent[0] !== baseCommit ||
      await git.currentBranch({ dir }) !== integrationBranch(assignment) || (await git.status({ dir })).length) {
    throw new Error("Publish a clean, single squash commit on the assigned integration branch rooted at the prepared main commit");
  }
  if (entry.message.trim() !== integrationCommitMessage(assignment, repo).trim()) throw new Error("Commit using the supplied commit-message file; the reference intent and provenance must remain exact");
  const candidate: IntegrationReceipt = { repository: repo.name, sourceCommit: repo.sourceCommit, baseCommit, commit, targetBranch: "main" };
  const pending = await storage.get<IntegrationReceipt>(pendingKey(repo));
  if (pending && pending.commit !== commit) throw new Error("A pending publication cannot change; retry or refresh_repository to recover");
  await storage.put(pendingKey(repo), candidate);
  const head = await remoteMain(artifacts, git, repo);
  if (await ancestor(git, dir, commit, head, baseCommit)) {
    await storage.put(receiptKey(repo), candidate);
    await storage.delete(pendingKey(repo));
    return candidate;
  }
  if (head !== baseCommit) throw new Error("main moved; call refresh_repository, squash again, resolve conflicts and rerun checks");
  try {
    await withToken(artifacts, repo.name, "write", async (url, headers) => {
      const pushed = await git.push({ url, headers, dir, ref: integrationBranch(assignment), remoteRef: "main", force: false });
      if (!pushed.ok || Object.values(pushed.refs).some(ref => !ref.ok)) throw new Error("Push rejected");
    });
  } catch {
    throw new Error(`Publication of ${repo.name}:main is unconfirmed. Retry publish_integration or refresh_repository; never modify the pending commit. main may have moved.`);
  }
  await storage.put(receiptKey(repo), candidate);
  await storage.delete(pendingKey(repo));
  return candidate;
}
