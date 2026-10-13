<script setup lang="ts">
import type { IntegrationDetail } from '../../shared/types/intent'
import { intentWorkflowStatus } from '~/utils/intentWorkflow'

const props = defineProps<{ integration: IntegrationDetail }>()
const status = computed(() => intentWorkflowStatus('completed', props.integration))
const publications = computed(() => new Map(props.integration.publications.map(publication => [publication.repository, publication])))
const unconfirmed = computed(() => new Set(props.integration.unconfirmedPublications.map(publication => publication.repository)))
const latestEvent = computed(() => props.integration.events.at(-1))
const description = computed(() => {
  switch (props.integration.status) {
    case 'queued': return 'Implementation is published. The integration agent is queued to squash and merge it into main.'
    case 'running': return 'The integration agent is squashing changes, resolving conflicts, and validating the intent before merging into main.'
    case 'integrated': return 'This intent has been merged into main in every changed repository. Merge does not imply deployment.'
    case 'blocked': return 'Integration is blocked. Any confirmed merges below are retained; the remaining work has not been merged.'
    case 'failed': return 'Integration could not finish. Check the report and activity below; a published implementation is not proof of a merge.'
  }
})
</script>

<template>
  <section class="space-y-3 rounded-xl border border-default p-4" aria-label="Integration status" aria-live="polite">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="font-semibold text-highlighted">Integration</h3>
      <ImplementationStatusBadge :status="status" />
    </div>
    <p class="text-sm text-muted">{{ description }}</p>
    <p v-if="integration.dispatch_error" class="break-words text-sm text-error" role="alert">{{ integration.dispatch_error }}</p>
    <p v-if="integration.mergedAt" class="text-xs text-success">Merged {{ new Date(integration.mergedAt).toLocaleString() }}</p>
    <p v-if="integration.publications.length && integration.status !== 'integrated'" class="text-sm text-warning">
      Partial integration: {{ integration.publications.length }} of {{ integration.repositories.length }} changed repositories merged.
    </p>
    <p v-if="integration.unconfirmedPublications.length" class="text-sm text-warning" role="alert">Some pushes are unconfirmed. They may have reached main, but are not counted as confirmed merges.</p>

    <ul class="space-y-3" aria-label="Repository merge results">
      <li v-for="repository in integration.repositories" :key="repository.name" class="space-y-1">
        <p class="flex flex-wrap items-center gap-2 text-sm font-medium">
          <UIcon :name="publications.has(repository.name) ? 'i-lucide-git-merge' : 'i-lucide-git-pull-request'" class="size-4" :class="publications.has(repository.name) ? 'text-success' : 'text-muted'" />
          <span class="break-words">{{ repository.name }} → {{ repository.targetBranch }}</span>
          <span class="text-xs" :class="publications.has(repository.name) ? 'text-success' : 'text-muted'">{{ publications.has(repository.name) ? 'Merged' : unconfirmed.has(repository.name) ? 'Unconfirmed' : 'Not merged' }}</span>
        </p>
        <p v-if="publications.has(repository.name)" class="break-all text-xs text-muted">
          Squash commit <code :title="publications.get(repository.name)?.commit">{{ publications.get(repository.name)?.commit.slice(0, 12) }}</code>
        </p>
        <p class="break-all text-xs text-dimmed">Source <code :title="repository.sourceCommit">{{ repository.sourceCommit.slice(0, 12) }}</code></p>
      </li>
    </ul>

    <div v-if="integration.report" class="space-y-1">
      <h4 class="text-sm font-medium text-highlighted">Integration report</h4>
      <p class="whitespace-pre-wrap break-words text-sm text-muted">{{ integration.report }}</p>
    </div>
    <p v-if="latestEvent" class="text-xs text-dimmed">Latest activity: {{ latestEvent.type }} · {{ new Date(latestEvent.occurred_at).toLocaleString() }}</p>
    <details v-if="integration.events.length" class="text-sm">
      <summary class="cursor-pointer font-medium text-highlighted">Integration activity ({{ integration.events.length }})</summary>
      <div class="mt-3 space-y-2">
        <details v-for="event in integration.events" :key="event.event_id" class="rounded-lg border border-default p-3">
          <summary class="cursor-pointer break-words text-xs font-medium">{{ event.sequence }} · {{ event.type }} · {{ new Date(event.occurred_at).toLocaleTimeString() }}</summary>
          <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap break-all text-xs">{{ JSON.stringify(event.payload, null, 2) }}</pre>
        </details>
      </div>
    </details>
    <p class="break-all text-xs text-dimmed">Integration {{ integration.id }}</p>
  </section>
</template>
