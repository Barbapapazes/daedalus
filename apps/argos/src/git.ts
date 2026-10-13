import { posix } from "node:path";
import { createGitClient } from "@cloudflare/computer/git";
import type { GitClientFactory, GitCliInput } from "@cloudflare/computer/git";

/** Bridge revision:path reads that Computer's native `show` does not support. */
export function createWorkspaceGitClient(): GitClientFactory {
  const factory = createGitClient();
  return options => {
    const client = factory(options);
    const cli = client.cli.bind(client);
    client.cli = async (input: GitCliInput) => {
      // Match Computer's global-option parser: only a single leading -C is supported.
      const commandIndex = input.argv[0] === "-C" ? 2 : 0;
      const command = input.argv[commandIndex];
      const spec = input.argv[commandIndex + 1];
      if (command === "show" && input.argv.length === commandIndex + 2 && spec?.includes(":")) {
        const colon = spec.indexOf(":");
        const dir = commandIndex === 2
          ? posix.resolve(input.cwd ?? "/", input.argv[1]!)
          : input.cwd ?? "/";
        try {
          const oid = await client.revParse({ dir, ref: spec.slice(0, colon) || "HEAD" });
          return cli({
            ...input,
            argv: [...input.argv.slice(0, commandIndex), "cat-file", "-p", `${oid}:${spec.slice(colon + 1)}`],
          });
        } catch (error) {
          return { stdout: "", stderr: `git show: ${error instanceof Error ? error.message : String(error)}\n`, exitCode: 128 };
        }
      }
      return cli(input);
    };
    return client;
  };
}
