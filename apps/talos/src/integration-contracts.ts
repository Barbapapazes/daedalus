import { z } from "zod";
import { assignmentSchema, canonicalize, repositoryAssignmentSchema } from "./contracts";

export const integrationAssignmentSchema = z.object({
  requestId: assignmentSchema.shape.requestId,
  implementationRequestId: assignmentSchema.shape.requestId,
  intent: assignmentSchema.shape.intent,
  repositories: z.array(repositoryAssignmentSchema.extend({
    sourceBranch: z.string().min(1),
    sourceCommit: z.string().regex(/^[a-f0-9]{40}$/),
    targetBranch: z.literal("main"),
  }).strict()).min(1).max(8),
  report: z.object({ summary: z.string(), acceptanceCriteria: z.array(z.unknown()) }).strict(),
  decisions: z.array(z.unknown()),
}).strict().superRefine((assignment, ctx) => {
  const names = assignment.repositories.map(repo => repo.name.toLowerCase());
  if (new Set(names).size !== names.length) ctx.addIssue({ code: "custom", message: "Repositories must be distinct" });
  for (const repo of assignment.repositories) {
    if (repo.sourceBranch !== `talos/${assignment.implementationRequestId}` || repo.sourceCommit === repo.baseCommit) {
      ctx.addIssue({ code: "custom", message: "Only changed, assigned implementation branches can be integrated" });
    }
  }
});

export type IntegrationAssignment = z.infer<typeof integrationAssignmentSchema>;
export type IntegrationRepository = IntegrationAssignment["repositories"][number];
export interface IntegrationReceipt {
  repository: string;
  sourceCommit: string;
  baseCommit: string;
  commit: string;
  targetBranch: "main";
}
export const integrationCheckout = (repo: IntegrationRepository) => `/workspace/repos/${repo.name}`;
export const integrationBranch = (assignment: IntegrationAssignment) => `integration/${assignment.requestId}`;
export const integrationMessagePath = (repo: IntegrationRepository) => `/workspace/context/${repo.name}.commit-message`;

/** The complete reference intent is losslessly encoded, not paraphrased by a model. */
export function intentCommitMessage(requestId: string, intent: IntegrationAssignment["intent"]) {
  return `Implement: ${intent.goal.replace(/\s+/g, " ").slice(0, 120)}\n\nReference intent (JSON):\n${JSON.stringify(canonicalize(intent))}\n\nImplementation-Request: ${requestId}\n`;
}

export function integrationCommitMessage(assignment: IntegrationAssignment, repo: IntegrationRepository) {
  return intentCommitMessage(assignment.implementationRequestId, assignment.intent)
    + `Implementation-Commit: ${repo.sourceCommit}\nIntegration-Request: ${assignment.requestId}\n`;
}
