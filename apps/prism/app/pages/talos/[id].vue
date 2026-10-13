<script setup lang="ts">
import { intentWorkflowStatus } from '~/utils/intentWorkflow'

definePageMeta({ key: route => String(route.params.id), fullWidth: true })

const route = useRoute()
const { implementation, repositories, loading, error, updatedAt, messages, sending, streaming } = useTalos(String(route.params.id))
const { data: intent, error: intentError } = await useIntent(String(route.params.id))
const phase = computed(() => implementation.value?.phase ?? intent.value?.status ?? 'queued')
const workflowStatus = computed(() => intentWorkflowStatus(phase.value, intent.value?.integration))
</script>

<template>
  <div class="space-y-6">
    <header class="space-y-2">
      <div class="flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold text-highlighted">Implementation</h1>
        <UBadge color="neutral" variant="subtle">Read-only</UBadge>
        <ImplementationStatusBadge :status="workflowStatus" />
      </div>
      <p class="text-muted">Observe implementation and autonomous integration of the approved intent. Confirmed merges are tracked below; deployment is separate.</p>
      <p class="break-all text-sm text-muted">Request {{ route.params.id }}</p>
      <UButton v-if="implementation" :to="`/intents/${encodeURIComponent(implementation.requestId)}`" color="neutral" variant="link" icon="i-lucide-arrow-left">
        Back to intent
      </UButton>
    </header>

    <p class="text-sm text-muted" role="status">
      {{ loading ? 'Loading implementation…' : updatedAt ? `Last updated ${updatedAt.toLocaleTimeString()}` : 'Waiting for implementation…' }}
    </p>
    <p v-if="error" class="text-error" role="alert">{{ error }}</p>
    <p v-if="intentError" class="text-sm text-warning" role="alert">Could not refresh integration status. Showing the last known information; merge is not inferred from Talos completion.</p>
    <IntentDeliveryProgress :implementation-status="phase" :integration="intent?.integration" class="max-w-md" />
    <IntentIntegrationPanel v-if="intent?.integration" :integration="intent.integration" />
    <p v-else-if="phase === 'completed'" class="text-sm text-muted" role="status">Implementation is published. Waiting for integration to appear in the API; no merge is confirmed yet.</p>

    <div v-if="implementation" class="grid items-start gap-6 lg:grid-cols-3">
      <UCard class="min-w-0">
        <template #header>
          <h2 class="text-xl font-semibold text-highlighted">Implementation context</h2>
        </template>

        <div class="space-y-6">
          <section class="space-y-3" aria-label="Reference intent">
            <h3 class="font-semibold text-highlighted">Reference intent</h3>
            <p class="whitespace-pre-wrap break-words">{{ implementation.intent.goal }}</p>
            <div>
              <h4 class="text-sm font-medium text-muted">Scope</h4>
              <p class="whitespace-pre-wrap break-words">{{ implementation.intent.scope }}</p>
            </div>
            <div v-if="implementation.intent.constraints.length">
              <h4 class="text-sm font-medium text-muted">Constraints</h4>
              <ul class="list-disc space-y-1 pl-5">
                <li v-for="(constraint, index) in implementation.intent.constraints" :key="index" class="whitespace-pre-wrap break-words">{{ constraint }}</li>
              </ul>
            </div>
            <div>
              <h4 class="text-sm font-medium text-muted">Acceptance criteria</h4>
              <ul class="list-disc space-y-1 pl-5">
                <li v-for="(criterion, index) in implementation.intent.acceptanceCriteria" :key="index" class="whitespace-pre-wrap break-words">{{ criterion }}</li>
              </ul>
            </div>
          </section>

          <section class="space-y-3" aria-label="Repository assignments">
            <h3 class="font-semibold text-highlighted">Repository assignments</h3>
            <div v-for="repository in repositories" :key="repository.name" class="space-y-1">
              <p class="break-words">{{ repository.name }} · {{ repository.baseRef }}</p>
              <p class="break-all text-sm text-muted">Base commit {{ repository.baseCommit }}</p>
            </div>
            <p class="break-all text-sm text-muted">Branch {{ implementation.branch }}</p>
          </section>

          <section v-if="implementation.report" class="space-y-3" aria-label="Implementation report">
            <h3 class="font-semibold text-highlighted">Implementation report</h3>
            <p class="whitespace-pre-wrap break-words">{{ implementation.report.summary }}</p>
            <div v-for="(criterion, index) in implementation.report.acceptanceCriteria" :key="index" class="space-y-1">
              <p class="break-words font-medium">{{ criterion.criterion }} <UBadge color="neutral" variant="subtle">{{ criterion.status }}</UBadge></p>
              <p class="whitespace-pre-wrap break-words text-sm text-muted">{{ criterion.evidence }}</p>
            </div>
            <ul v-if="implementation.report.blockers.length" class="list-disc space-y-1 pl-5 text-warning" aria-label="Blockers">
              <li v-for="(blocker, index) in implementation.report.blockers" :key="index" class="whitespace-pre-wrap break-words">{{ blocker }}</li>
            </ul>
          </section>

          <section class="space-y-3" aria-label="Decisions and execution history">
            <h3 class="font-semibold text-highlighted">Decisions and execution history</h3>
            <details v-for="event in implementation.events" :key="event.eventId" class="rounded-lg border border-default p-3">
              <summary class="cursor-pointer break-words text-sm font-medium">
                {{ event.sequence }} · {{ event.type }} · {{ new Date(event.createdAt).toLocaleString() }}
              </summary>
              <pre class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap break-all text-sm">{{ JSON.stringify(event.payload, null, 2) }}</pre>
            </details>
            <p v-if="!implementation.events.length" class="text-sm text-muted">No recorded events yet.</p>
          </section>
        </div>
      </UCard>

      <UCard class="min-w-0 lg:sticky lg:top-6 lg:col-span-2" :ui="{ body: 'p-0 sm:p-0' }">
        <template #header>
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold text-highlighted">Talos chat</h2>
            <UBadge color="neutral" variant="subtle">Read-only</UBadge>
          </div>
        </template>

        <section class="h-[70dvh] min-h-64 overflow-y-auto p-4 sm:p-6" aria-label="Implementation chat">
          <AgentMessages v-if="messages.length || sending" :messages="messages" :sending="sending" :streaming="streaming" />
          <p v-else class="py-8 text-center text-muted">No agent activity yet.</p>
        </section>
      </UCard>
    </div>
  </div>
</template>
