import type { IntentDetail, IntegrationSummary } from '../../shared/types/intent'

export function intentWorkflowStatus(implementationStatus: string, integration?: IntegrationSummary | null): string {
  if (integration) {
    switch (integration.status) {
      case 'integrated': return 'merged'
      case 'running': return 'integrating'
      case 'queued': return 'integration_queued'
      case 'blocked': return 'integration_blocked'
      case 'failed': return 'integration_failed'
    }
  }
  return implementationStatus === 'completed' ? 'implemented' : implementationStatus
}

export function intentWorkflowSettled(intent: IntentDetail): boolean {
  if (intent.integration) return ['integrated', 'blocked', 'failed'].includes(intent.integration.status)
  // Completion is only branch publication. Keep observing the API handoff to integration.
  return ['blocked', 'failed'].includes(intent.status)
}
