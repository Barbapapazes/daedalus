import type { Workspace } from "@cloudflare/computer";
import { createPiTools } from "@cloudflare/computer/tools/pi-ai";
import { Type } from "@earendil-works/pi-ai";
import { defineTool, type ToolRegistration } from "@earendil-works/pi-durable";
import type { EventLog } from "./events";

interface ComputerHost {
  prepare(): Promise<void>;
  defaultCwd(): Promise<string>;
  prepareExec(command: string, backend: "shell" | "sandbox", cwd?: string): Promise<void>;
  sandboxActive(active: boolean): Promise<void>;
  sourceSynced(): Promise<void>;
  log: EventLog;
  execDescription?: string;
}

/** Cheap file tools and shell by default; full Linux is explicitly opt-in per execution. */
export function createComputerTools(workspace: Workspace, host: ComputerHost): ToolRegistration[] {
  const computer = createPiTools({
    workspace,
    assets: false,
    read: { maxBytes: 32 * 1024, maxLines: 800, includeLineNumbers: true },
  });
  const fileTools: ToolRegistration[] = computer.tools.map(tool => ({
    ...tool,
    parameters: Type.Unsafe<Record<string, unknown>>(tool.parameters),
    replay: ["read", "ls", "find", "grep"].includes(tool.name) ? "safe" : "unsafe",
    executionMode: "sequential",
    execute: async (args, api, context) => {
      await host.prepare();
      return computer.execute(
        { id: api.callId, name: tool.name, arguments: args },
        { abortSignal: context.abortSignal },
      );
    },
  }));
  return [...fileTools, defineTool({
    name: "exec",
    description: host.execDescription ?? "Run a foreground command in the assigned checkout. Prefer backend shell: cheap offline just-bash, text commands, pipes, edits and local read/write Git; no Node/npm/pnpm/native binaries and no access to sandbox-installed dependencies. Use sandbox for every command invoking Node/npm/pnpm or inspecting installed node_modules. Select sandbox for package installation, native tools, builds and checks: full Linux with Node/npm/pnpm, Python and compilers, started lazily. Dependencies are container-local and may need reinstalling after idle shutdown. Create only the assigned talos/<requestId> branch and commit your changes. Use standard git checkout -b, git add, git commit -F /workspace/context/implementation.commit-message, and git push commands. Authenticated git push must use backend shell, as a standalone command after all edits and checks; the adapter restricts publication to the assigned branch and keeps credentials internal. Native sandbox Git supports local operations only. Finish edits and checks in ALL assigned repositories before the first push freezes the complete change set. Then only standalone shell pushes of the remaining frozen commits, push retries, or finish_implementation are allowed. Set cwd to the exact assigned checkout for each command. Never modify .git directly, force push, merge, deploy, start background processes, or send source to external services. Both backends share source through Computer sync; Artifacts and Laravel credentials are never provided to either shell.",
    parameters: Type.Object({
      command: Type.String({ minLength: 1, maxLength: 16_000 }),
      backend: Type.Union([Type.Literal("shell"), Type.Literal("sandbox")]),
      cwd: Type.Optional(Type.String()),
    }),
    replay: "unsafe",
    executionMode: "sequential",
    execute: async ({ command, backend, cwd }, api, context) => {
      const directory = cwd ?? await host.defaultCwd();
      await host.prepareExec(command, backend, directory);
      if (context.abortSignal?.aborted) throw new Error("Execution aborted");
      if (backend === "sandbox") await host.sandboxActive(true);
      try {
        using run = await workspace.runtime.exec(command, {
          backend, cwd: directory, encoding: "utf8", id: api.callId,
          timeoutMs: 300_000, sync: "wait",
        });
        const abort = () => { void run.kill().catch(() => undefined); };
        context.abortSignal?.addEventListener("abort", abort, { once: true });
        if (context.abortSignal?.aborted) abort();
        try {
          const result = await run.result();
          const output = {
            command, backend, cwd: directory, exitCode: result.exitCode,
            stdout: result.stdout.slice(0, 32 * 1024), stderr: result.stderr.slice(0, 32 * 1024),
            truncated: result.stdout.length > 32 * 1024 || result.stderr.length > 32 * 1024,
            sync: result.sync.status,
          };
          await host.log.append("exec:" + api.callId, "execution", output);
          if (result.sync.status !== "complete") throw new Error("Execution finished but source synchronization failed; do not continue or publish");
          if (backend === "sandbox") await host.sourceSynced();
          return { content: [{ type: "text", text: JSON.stringify(output) }], isError: result.exitCode !== 0 };
        } finally {
          context.abortSignal?.removeEventListener("abort", abort);
        }
      } finally {
        if (backend === "sandbox") await host.sandboxActive(false).catch(error => console.error("talos:sandbox idle policy", error));
      }
    },
  })];
}
