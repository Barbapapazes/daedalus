export interface RepositoryRecord {
  id: string
  name: string
  description: string
  context: string
  artifacts_namespace: string
  artifacts_name: string
  default_branch: string
  initial_commit: string | null
  status: 'provisioning' | 'ready' | 'failed'
  provisioning_error: string | null
  created_at: string
}
export interface RepositoryPage {
  data: RepositoryRecord[]
  total: number
  current_page: number
  last_page: number
}
