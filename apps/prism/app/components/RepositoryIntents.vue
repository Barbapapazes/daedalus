<script setup lang="ts">
import type { IntentPage } from '../../shared/types/intent'
import { intentWorkflowStatus } from '~/utils/intentWorkflow'

const props = defineProps<{ name: string }>()
const page = ref(1)
const limit = 20
const { data, status, error, refresh } = await useFetch<IntentPage>('/api/intents', {
  query: computed(() => ({ repository: props.name, page: page.value, limit })),
  lazy: true,
})
let timer: ReturnType<typeof setTimeout> | undefined
let stopped = false
function schedule() {
  if (stopped) return
  timer = setTimeout(async () => {
    if (status.value !== 'pending') await refresh()
    schedule()
  }, 5000)
}
onMounted(schedule)
onBeforeUnmount(() => {
  stopped = true
  clearTimeout(timer)
})
</script>

<template>
  <section class="space-y-4" aria-labelledby="repository-intents-heading" :aria-busy="status === 'pending'">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <h2 id="repository-intents-heading" class="text-xl font-semibold text-highlighted">Intents</h2>
        <span v-if="data" class="text-sm text-muted">{{ data.total }} total</span>
      </div>
      <slot name="actions" />
    </div>
    <p v-if="status === 'pending' && !data" class="text-sm text-muted" role="status">Loading intents…</p>
    <div v-if="error" class="space-y-2">
      <p class="text-sm text-error" role="alert">Could not load repository intents.</p>
      <UButton color="neutral" variant="soft" size="sm" @click="refresh()">Retry</UButton>
    </div>
    <p v-else-if="data && !data.data.length" class="text-sm text-muted">No intents for this repository yet.</p>
    <NuxtLink
      v-for="intent in data?.data ?? []"
      :key="intent.id"
      :to="'/intents/' + intent.id"
      class="block space-y-2 rounded-xl border border-default p-4 transition-colors hover:border-primary focus-visible:outline-2 focus-visible:outline-primary"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <h3 class="min-w-0 flex-1 break-words font-semibold text-highlighted">{{ intent.intent.goal }}</h3>
        <ImplementationStatusBadge :status="intentWorkflowStatus(intent.status, intent.integration)" />
      </div>
      <p class="line-clamp-2 break-words text-sm text-muted">{{ intent.intent.scope }}</p>
      <p class="text-xs text-dimmed">Updated {{ new Date(intent.integration?.updated_at ?? intent.updated_at).toLocaleString() }}</p>
    </NuxtLink>
    <UPagination v-if="data && data.last_page > 1" v-model:page="page" :total="data.total" :items-per-page="limit" :disabled="status === 'pending'" />
  </section>
</template>
