export interface IntentRecord {
  id: string
  intent: {
    goal: string
    scope: string
    constraints: string[]
    acceptanceCriteria: string[]
  }
  repositories: { name: string; baseRef: string; baseCommit: string }[]
  status: ImplementationStatus
  integration: IntegrationSummary | null
  updated_at: string
}

export type ImplementationStatus = 'received' | 'queued' | 'dispatched' | 'running' | 'completed' | 'blocked' | 'failed'

export type IntegrationStatus = 'queued' | 'running' | 'integrated' | 'blocked' | 'failed'

export interface IntegrationSummary {
  id: string
  status: IntegrationStatus
  dispatch_error: string | null
  updated_at: string
}

export interface IntegrationPublication {
  repository: string
  sourceCommit: string
  baseCommit: string
  commit: string
  targetBranch: string
}

export interface IntentEvent {
  event_id: string
  sequence: number
  type: string
  payload: Record<string, unknown>
  occurred_at: string
}

export interface IntegrationDetail extends IntegrationSummary {
  repositories: { name: string; targetBranch: string; sourceCommit: string }[]
  publications: IntegrationPublication[]
  unconfirmedPublications: IntegrationPublication[]
  report: string | null
  mergedAt: string | null
  events: IntentEvent[]
}

export interface IntentDetail {
  requestId: string
  conversationId: string
  status: ImplementationStatus
  referenceIntent: IntentRecord['intent']
  repositories: IntentRecord['repositories']
  branch: string
  dispatchError: string | null
  integration: IntegrationDetail | null
  events: IntentEvent[]
}

export interface IntentPage {
  data: IntentRecord[]
  total: number
  current_page: number
  last_page: number
}
