import { Type } from "@earendil-works/pi-ai";
import type { ToolRegistration } from "@earendil-works/pi-durable";
import type { Workspace } from "@cloudflare/computer";
import { createPiTools } from "@cloudflare/computer/tools/pi-ai";

/** Computer owns schemas and execution; Pi Durable owns tool recovery and transcripts. */
export function createComputerTools(workspace: Workspace, prepare: () => Promise<void>): ToolRegistration[] {
  const computer = createPiTools({
    workspace,
    read: { maxBytes: 32 * 1024, maxLines: 800, includeLineNumbers: true },
    shell: {
      defaultBackend: "shell",
      maxBytes: 32 * 1024,
      backends: {
        shell: {
          description: "Worker shell (just-bash) with text commands, pipes, file mutations, curl and jq. Includes git for repository inspection: log -n 30 --oneline, show <commit> or <commit>:<path>, diff, status, rev-parse, ls-files, ls-tree, cat-file. Publishing changes upstream is unavailable. Not Linux: no npm or native binaries. Multiple repositories may be loaded, with one checkout per repository. Use the exact workspace paths from load_repository or the repository context; use git -C <path> to inspect each checkout.",
        },
      },
    },
  });

  return computer.tools.map(tool => ({
    ...tool,
    // Computer supplies JSON Schema, while Pi Durable requires a TypeBox schema.
    parameters: Type.Unsafe<Record<string, unknown>>(tool.parameters),
    replay: ["read", "ls", "find", "grep"].includes(tool.name) ? "safe" : "unsafe",
    executionMode: "sequential",
    execute: async (args, api, context) => {
      await prepare();
      return computer.execute(
        { id: api.callId, name: tool.name, arguments: args },
        { abortSignal: context.abortSignal },
      );
    },
  }));
}
