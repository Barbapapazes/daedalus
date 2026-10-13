<script setup lang="ts">
import type { IntentDetail } from '../../shared/types/intent'
import { intentWorkflowStatus } from '~/utils/intentWorkflow'

const props = defineProps<{ intent: IntentDetail }>()
const { implementation, loading, error, updatedAt } = useTalos(props.intent.requestId)
const phase = computed(() => implementation.value?.phase ?? props.intent.status)
const workflowStatus = computed(() => intentWorkflowStatus(phase.value, props.intent.integration))
const latestEvent = computed(() => props.intent.events.at(-1))
</script>

<template>
  <aside class="space-y-5 p-4 sm:p-6" aria-label="Implementation status">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-semibold text-highlighted">Intent delivery</h2>
      <ImplementationStatusBadge :status="workflowStatus" />
    </div>
    <IntentDeliveryProgress :implementation-status="phase" :integration="intent.integration" />
    <IntentIntegrationPanel v-if="intent.integration" :integration="intent.integration" />
    <p v-else-if="phase === 'completed'" class="text-sm text-muted" role="status">Implementation is published. Waiting for the API to start integration; it has not been confirmed merged.</p>
    <UButton :to="'/talos/' + intent.requestId" icon="i-lucide-messages-square" color="neutral" variant="soft" block>
      View implementation chat
    </UButton>
    <p class="text-xs text-muted">Read-only activity, decisions, and execution history. Integration merges automatically; deployment is separate.</p>
    <p v-if="loading" class="text-sm text-muted" role="status">Loading live progress…</p>
    <p v-if="error" class="text-sm text-warning" role="status">{{ error }} Showing the last known status; live progress will retry automatically.</p>
    <p v-if="updatedAt" class="text-xs text-dimmed">Updated {{ updatedAt.toLocaleTimeString() }}</p>
    <p v-if="intent.dispatchError" class="text-sm text-error" role="alert">{{ intent.dispatchError }}</p>

    <section class="space-y-2">
      <h3 class="text-sm font-medium text-highlighted">Approved intent</h3>
      <p class="break-words text-sm font-medium">{{ intent.referenceIntent.goal }}</p>
      <p class="whitespace-pre-wrap break-words text-sm text-muted">{{ intent.referenceIntent.scope }}</p>
    </section>
    <section v-if="intent.referenceIntent.acceptanceCriteria.length" class="space-y-2">
      <h3 class="text-sm font-medium text-highlighted">Acceptance criteria</h3>
      <ul class="list-disc space-y-1 pl-4 text-sm text-muted">
        <li v-for="(criterion, index) in intent.referenceIntent.acceptanceCriteria" :key="index" class="break-words">{{ criterion }}</li>
      </ul>
    </section>
    <section class="space-y-2">
      <h3 class="text-sm font-medium text-highlighted">Repositories</h3>
      <p v-for="repository in intent.repositories" :key="repository.name" class="break-words text-sm text-muted">{{ repository.name }} · {{ repository.baseRef }}</p>
      <p class="break-all text-xs text-dimmed">Branch {{ intent.branch }}</p>
    </section>
    <section v-if="implementation?.report" class="space-y-2">
      <h3 class="text-sm font-medium text-highlighted">Implementation report</h3>
      <p class="whitespace-pre-wrap break-words text-sm text-muted">{{ implementation.report.summary }}</p>
      <ul v-if="implementation.report.blockers.length" class="list-disc space-y-1 pl-4 text-sm text-warning">
        <li v-for="(blocker, index) in implementation.report.blockers" :key="index" class="break-words">{{ blocker }}</li>
      </ul>
    </section>
    <p v-if="latestEvent" class="text-xs text-dimmed">Latest activity: {{ latestEvent.type }} · {{ new Date(latestEvent.occurred_at).toLocaleString() }}</p>
  </aside>
</template>
