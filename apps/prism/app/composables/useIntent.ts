import type { IntentDetail } from '../../shared/types/intent'
import { intentWorkflowSettled } from '~/utils/intentWorkflow'

export function useIntent(id: string) {
  const result = useFetch<IntentDetail>('/api/intents/' + encodeURIComponent(id))
  let timer: ReturnType<typeof setTimeout> | undefined
  let stopped = false

  function schedule() {
    if (stopped || (!result.error.value && result.data.value && intentWorkflowSettled(result.data.value))) return
    timer = setTimeout(async () => {
      await result.refresh()
      schedule()
    }, 3000)
  }

  onMounted(schedule)
  onBeforeUnmount(() => {
    stopped = true
    clearTimeout(timer)
  })
  return result
}
