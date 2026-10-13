import { Type } from "@earendil-works/pi-ai";
import { defineTool } from "@earendil-works/pi-durable";
import { apiRequest, ApiRequestError, type RepositoryRecord } from "./api";

export interface RepositoryCreation {
  id: string;
  requestId?: string;
  name: string;
  description: string;
  context: string;
}

export function createRepositoryCreationTool(env: Env, storage: DurableObjectStorage) {
  return defineTool({
    name: "create_repository",
    description: "Request creation of an agreed repository. The API stores the brief and queues background provisioning of an empty main branch. Only call after summarizing its name, description and project context and receiving explicit approval to create it. Provisioning does not implement software. Report the returned status: provisioning is not ready. Check completion with get_repository, then use load_repository when ready. For a failed or unconfirmed request, retry the same name and original brief after approval; never change a frozen brief or create another repository automatically.",
    parameters: Type.Object({
      name: Type.String({ minLength: 1, maxLength: 128, pattern: "^[a-z0-9][a-z0-9._-]*$" }),
      description: Type.String({ minLength: 1, maxLength: 500 }),
      context: Type.String({ minLength: 1, maxLength: 16000, description: "Self-contained brief: purpose, intended users, initial scope, known constraints and open questions. Distinguish undecided choices from requirements." }),
    }),
    replay: "safe",
    executionMode: "sequential",
    execute: async ({ name, description, context }) => {
      description = description.trim();
      context = context.trim();
      if (!description || !context) throw new Error("Description and project context must not be blank");
      const key = "argos:creation:" + name;
      let creation = await storage.get<RepositoryCreation>(key);
      if (!creation) {
        const requestId = crypto.randomUUID();
        creation = { id: requestId, requestId, name, description, context };
        // Freeze the request key before sending so lost acknowledgements can replay safely.
        await storage.put(key, creation);
      }
      const request = {
        ...(creation.requestId ? { requestId: creation.requestId } : { id: creation.id }),
        name, description, context,
      };
      try {
        const repository = await apiRequest<RepositoryRecord>(env, "repositories", "POST", request);
        await storage.put(key, { ...creation, requestId: creation.requestId ?? creation.id, id: repository.id, name, description, context });
        return { content: [{ type: "text", text: JSON.stringify({
          repository,
          note: repository.status === "ready"
            ? "Repository is ready. Use load_repository to load it. No implementation has started."
            : "Creation request accepted. The API is provisioning the repository in the background; it is not ready yet. Use get_repository to check status, then load_repository once ready. No implementation has started.",
        }) }] };
      } catch (error) {
        if (error instanceof ApiRequestError && error.status === 409) {
          return {
            isError: true,
            content: [{ type: "text", text: JSON.stringify({
              name, status: "creation_conflict",
              note: "The repository name or request key already has a different brief. Use get_repository to retrieve the original brief. Do not change it or create another repository automatically.",
            }) }],
          };
        }
        console.error("argos:creation_request_unconfirmed", { requestId: creation.requestId ?? creation.id });
        return {
          isError: true,
          content: [{ type: "text", text: JSON.stringify({
            requestId: creation.requestId ?? creation.id, name, status: "creation_request_unconfirmed",
            note: "API receipt could not be confirmed. Use get_repository to check status first: provisioning means background work is pending, and ready means creation is complete. Retry the same repository and original brief only if needed and approved; do not create another repository automatically.",
          }) }],
        };
      }
    },
  });
}
