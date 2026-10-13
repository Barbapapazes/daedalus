import { piMessages, type SessionSnapshot } from '~/utils/piMessages'

interface Implementation {
  requestId: string
  conversationId: string
  intent: { goal: string; scope: string; constraints: string[]; acceptanceCriteria: string[] }
  repository?: { name: string; baseRef: string; baseCommit: string }
  repositories?: { name: string; baseRef: string; baseCommit: string }[]
  branch: string
  phase: 'preparing' | 'implementing' | 'publishing' | 'completed' | 'blocked' | 'failed'
  report: {
    outcome: 'ready' | 'blocked'
    summary: string
    acceptanceCriteria: { criterion: string; status: 'met' | 'unmet' | 'unverified'; evidence: string }[]
    blockers: string[]
  } | null
  events: { eventId: string; sequence: number; type: string; payload: Record<string, unknown>; createdAt: string }[]
  session: SessionSnapshot
}

export function useTalos(id: string) {
  const implementation = shallowRef<Implementation>()
  const loading = ref(true)
  const error = ref('')
  const updatedAt = ref<Date>()
  let timer: ReturnType<typeof setTimeout> | undefined
  let controller: AbortController | undefined
  let stopped = false

  async function refresh() {
    controller = new AbortController()
    try {
      implementation.value = await $fetch<Implementation>(`/api/talos/${encodeURIComponent(id)}`, {
        signal: controller.signal,
      })
      error.value = ''
      updatedAt.value = new Date()
    } catch (cause) {
      if (stopped) return
      error.value = (cause as { data?: { statusMessage?: string } }).data?.statusMessage || 'Could not load the implementation.'
    } finally {
      loading.value = false
      // Sequential polling avoids overlapping snapshots. Retry queued/not-yet-dispatched IDs too.
      if (!stopped && (!implementation.value || !['completed', 'blocked', 'failed'].includes(implementation.value.phase))) {
        timer = setTimeout(refresh, 2000)
      }
    }
  }

  onMounted(refresh)
  onBeforeUnmount(() => {
    stopped = true
    clearTimeout(timer)
    controller?.abort()
  })

  return {
    implementation, loading, error, updatedAt,
    repositories: computed(() => implementation.value?.repositories ?? (implementation.value?.repository ? [implementation.value.repository] : [])),
    messages: computed(() => piMessages(implementation.value?.session.entries ?? [], implementation.value?.session.generation?.message)),
    sending: computed(() => !!implementation.value?.session.run),
    streaming: computed(() => !!implementation.value?.session.generation),
  }
}
