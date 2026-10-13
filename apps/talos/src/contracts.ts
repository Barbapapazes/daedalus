import { z } from "zod";

export const repositoryAssignmentSchema = z.object({
  name: z.string().regex(/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/),
  baseRef: z.string().min(1).max(256),
  baseCommit: z.string().regex(/^[a-f0-9]{40}$/),
  projectContext: z.string().min(1).max(100000).optional(),
}).strict();

export const assignmentSchema = z.object({
  requestId: z.uuid().transform(value => value.toLowerCase()),
  conversationId: z.string().min(1).max(128),
  intent: z.object({
    goal: z.string().min(1), scope: z.string().min(1),
    constraints: z.array(z.string().min(1)),
    acceptanceCriteria: z.array(z.string().min(1)).min(1),
  }).strict(),
  messages: z.array(z.unknown()),
  repository: repositoryAssignmentSchema.optional(),
  repositories: z.array(repositoryAssignmentSchema).min(1).max(8).optional(),
}).strict().superRefine((assignment, ctx) => {
  if (Boolean(assignment.repository) === Boolean(assignment.repositories)) {
    ctx.addIssue({ code: 'custom', message: 'Provide exactly one repository assignment format' });
  }
  const names = assignment.repositories?.map(repo => repo.name.toLowerCase()) ?? [];
  if (new Set(names).size !== names.length) {
    ctx.addIssue({ code: 'custom', path: ['repositories'], message: 'Repositories must be distinct' });
  }
});

export type Assignment = z.infer<typeof assignmentSchema>;
export type RepositoryAssignment = z.infer<typeof repositoryAssignmentSchema>;

export function assignedRepositories(assignment: Assignment): RepositoryAssignment[] {
  return assignment.repositories ?? (assignment.repository ? [assignment.repository] : []);
}

export function checkoutFor(assignment: Assignment, repository: RepositoryAssignment): string {
  // Preserve existing durable single-repository workspaces and publication checkpoints.
  return assignment.repository ? CHECKOUT : `/workspace/repos/${repository.name}`;
}

export function publicationKey(assignment: Assignment, repository: RepositoryAssignment, published = false): string {
  const prefix = published ? 'published-commit' : 'publication-commit';
  return assignment.repository ? prefix : `${prefix}:${repository.name}`;
}
export type Phase = "preparing" | "implementing" | "publishing" | "completed" | "blocked" | "failed";
export interface Report {
  outcome: "ready" | "blocked";
  summary: string;
  acceptanceCriteria: { criterion: string; status: "met" | "unmet" | "unverified"; evidence: string }[];
  blockers: string[];
}
export interface ImplementationEvent {
  eventId: string;
  sequence: number;
  type: "progress" | "decision" | "execution" | "completed" | "blocked" | "failed";
  payload: Record<string, unknown>;
  createdAt: string;
}
export const CHECKOUT = "/workspace/repo";
export function branchFor(requestId: string): string { return "talos/" + requestId; }

export function canonicalize(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === "object") {
    return Object.fromEntries(Object.entries(value).sort(([a], [b]) => a.localeCompare(b))
      .map(([key, child]) => [key, canonicalize(child)]));
  }
  return value;
}
