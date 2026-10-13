import { createGitClient, type GitClientFactory } from "@cloudflare/computer/git";
import type { Workspace } from "@cloudflare/computer";
import { intentCommitMessage } from "./integration-contracts";
import { posix } from "node:path";
import { assignedRepositories, branchFor, checkoutFor, publicationKey, type Assignment, type RepositoryAssignment } from "./contracts";

interface GitHost {
  prepare(): Promise<void>;
  assignment(): Promise<Assignment>;
  frozen(): Promise<boolean>;
  push(repository: RepositoryAssignment): Promise<{ branch: string; commit: string }>;
}

/** Standard Worker-shell Git commands, with credential-safe, assignment-scoped push. */
export function workingGit(host: GitHost): GitClientFactory {
  const factory = createGitClient();
  const reads = new Set(["diff", "status", "log", "show", "rev-parse", "ls-files", "ls-tree", "cat-file"]);
  const commands = new Set([...reads, "add", "rm", "commit", "branch", "checkout", "switch", "config", "remote", "reset", "clean", "stash"]);
  return options => {
    const client = factory({ ...options, defaultIdentity: { name: "Talos", email: "talos@daedalus.invalid" } });
    return new Proxy(client, {
      get(target, property) {
        if (property === "then") return undefined;
        if (property === "cli") return async (input: Parameters<typeof client.cli>[0]) => {
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
            const command = argv.shift();
            const assignment = await host.assignment();
            const root = await target.repoRoot({ dir });
            const repository = assignedRepositories(assignment).find(repo => checkoutFor(assignment, repo) === root);
            if (!repository) throw new Error("Git operations must target an assigned checkout");
            if (command === "push") {
              const branch = branchFor(assignment.requestId);
              const upstream = argv[0] === "-u" || argv[0] === "--set-upstream";
              if (upstream) argv.shift();
              // Never pass model-supplied URLs, refs, flags, or credentials to network Git.
              if (argv.length > 2 || (argv.length > 0 && argv[0] !== "origin") ||
                  (argv.length === 2 && argv[1] !== branch)) {
                throw new Error(`Use git push [-u] origin ${branch}; other remotes, refs and flags are prohibited`);
              }
              const result = await host.push(repository);
              if (upstream) {
                await target.configSet({ dir: root, path: `branch.${branch}.remote`, value: "origin" });
                await target.configSet({ dir: root, path: `branch.${branch}.merge`, value: `refs/heads/${branch}` });
              }
              return { stdout: `Pushed ${result.commit} to ${repository.name}:origin/${result.branch}\n`, stderr: "", exitCode: 0 };
            }
            if (!command || !commands.has(command)) throw new Error("Unsupported Talos Git command; merge and network operations other than scoped push are prohibited");
            if (await host.frozen() && !reads.has(command)) throw new Error("Publication froze all checkouts; only push the frozen commits or finish the report");
            // Computer's Worker-shell commit CLI only supports -m. Adapt the canonical
            // intent file without requiring the expensive sandbox just to commit.
            const fileFlag = command === "commit" ? argv.findIndex(arg => arg === "-F" || arg === "--file") : -1;
            if (fileFlag >= 0) {
              if (argv[fileFlag + 1] !== "/workspace/context/implementation.commit-message") throw new Error("Use the assigned intent commit-message file");
              argv.splice(fileFlag, 2, "-m", intentCommitMessage(assignment.requestId, assignment.intent));
            }
            return await target.cli({ ...input, cwd: dir, argv: [command, ...argv] });
          } catch (error) {
            return { stdout: "", stderr: `${error instanceof Error ? error.message : "Git operation failed"}\n`, exitCode: 1 };
          }
        };
        // Model Git is accessed through argv, not unrestricted RPC methods.
        return async () => { throw new Error("Use standard git commands through exec with backend shell"); };
      },
    });
  };
}

export async function withToken<T>(artifacts: Artifacts, name: string, scope: "read" | "write", run: (url: string, headers: Record<string, string>) => Promise<T>) {
  using repo = await artifacts.get(name);
  const remote = new URL((await repo.info()).remote);
  if (remote.protocol !== "https:" || remote.username || remote.password) throw new Error("Invalid Artifacts remote");
  const token = await repo.createToken(scope, 600);
  try {
    return await run(remote.href, { Authorization: "Basic " + btoa("x:" + token.plaintext) });
  } finally {
    await repo.revokeToken(token.plaintext).catch(() => undefined);
  }
}

export async function provision(artifacts: Artifacts, workspace: Workspace, assignment: Assignment) {
  const git = createGitClient()({ ws: workspace });
  for (const repository of assignedRepositories(assignment)) {
    const dir = checkoutFor(assignment, repository);
    await workspace.fs.rm(dir, { recursive: true, force: true });
    await workspace.fs.mkdir(dir, { recursive: true });
    await withToken(artifacts, repository.name, "read", (url, headers) => git.clone({
      url, headers, dir, singleBranch: false, noTags: false,
    }));
    await git.checkout({ dir, ref: repository.baseCommit, force: true });
    // The agent creates implementation branches explicitly in changed repositories.
  }
}

/** Validate agent-created commits, without staging, committing, or pushing. */
export async function publication(workspace: Workspace, assignment: Assignment, repository: RepositoryAssignment) {
  const git = createGitClient()({ ws: workspace });
  const dir = checkoutFor(assignment, repository);
  const branch = branchFor(assignment.requestId);
  const commit = await git.revParse({ dir, ref: "HEAD" });
  if ((await git.status({ dir })).length) throw new Error(`Commit all changes in ${repository.name} before pushing`);
  const changed = commit !== repository.baseCommit;
  if (!changed) return { repository: repository.name, branch, commit, changed, changedFiles: [] as string[] };
  if (await git.currentBranch({ dir }) !== branch) throw new Error(`Use the assigned implementation branch in ${repository.name}`);
  // Require a linear implementation history rooted at each immutable assigned base.
  let ref = commit;
  while (ref !== repository.baseCommit) {
    const [entry] = await git.log({ dir, ref, depth: 1 });
    if (!entry || entry.parent.length !== 1) throw new Error(`Implementation history in ${repository.name} must descend linearly from its assigned base`);
    if (entry.message.trim() !== intentCommitMessage(assignment.requestId, assignment.intent).trim()) {
      throw new Error(`Use git commit -F /workspace/context/implementation.commit-message in ${repository.name}; every implementation commit must preserve the reference intent`);
    }
    ref = entry.parent[0]!;
  }
  const changedFiles = await git.diffSummary({ dir, ref: repository.baseCommit, to: commit });
  return { repository: repository.name, branch, commit, changed, changedFiles };
}

export async function publications(workspace: Workspace, assignment: Assignment) {
  const results = [];
  for (const repository of assignedRepositories(assignment)) results.push(await publication(workspace, assignment, repository));
  if (!results.some(result => result.changed)) throw new Error("Create an implementation commit in at least one assigned repository");
  return results;
}

/** Freeze the whole cross-repository change set before the first network push. */
export async function publish(artifacts: Artifacts, workspace: Workspace, assignment: Assignment, repository: RepositoryAssignment, storage: DurableObjectStorage) {
  const git = createGitClient()({ ws: workspace });
  const results = await publications(workspace, assignment);
  const result = results.find(item => item.repository === repository.name);
  if (!result?.changed) throw new Error("Unchanged repositories do not need a push");
  const checkpoints: Record<string, string | boolean> = { "publication-frozen": true };
  for (const [index, target] of assignedRepositories(assignment).entries()) {
    const commit = results[index]!.commit;
    const key = publicationKey(assignment, target);
    const checkpoint = await storage.get<string>(key);
    if (checkpoint && checkpoint !== commit) throw new Error("Implementation changed after publication was requested");
    checkpoints[key] = commit;
  }
  // One durable write freezes all bases/commits, including unchanged checkouts.
  await storage.put(checkpoints);
  const { branch, commit } = result;
  try {
    await withToken(artifacts, repository.name, "write", async (url, headers) => {
      const pushed = await git.push({ url, headers, dir: checkoutFor(assignment, repository), ref: branch, remoteRef: branch, force: false });
      if (!pushed.ok || Object.values(pushed.refs).some(ref => !ref.ok)) throw new Error("Artifacts rejected the branch push");
    });
  } catch {
    // Publication is not atomic across remotes. Preserve confirmed per-repo receipts.
    // Never expose transport exceptions that may contain credential-bearing data.
    throw new Error(`Artifacts push for ${repository.name} failed; retry the same git push or report a blocker. Other repositories may already be published.`);
  }
  await storage.put(publicationKey(assignment, repository, true), commit);
  return result;
}

/** Completion requires every changed repository's exact frozen commit to be pushed. */
export async function confirmedPublications(workspace: Workspace, assignment: Assignment, storage: DurableObjectStorage) {
  const results = await publications(workspace, assignment);
  for (const [index, repository] of assignedRepositories(assignment).entries()) {
    const result = results[index]!;
    if (await storage.get<string>(publicationKey(assignment, repository)) !== result.commit ||
        (result.changed && await storage.get<string>(publicationKey(assignment, repository, true)) !== result.commit)) {
      throw new Error(`Run git push successfully for every changed repository before reporting ready (${repository.name})`);
    }
  }
  return results;
}
