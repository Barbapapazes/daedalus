<script setup lang="ts">
import type { RepositoryRecord } from '../../../shared/types/repository'
definePageMeta({ key: route => String(route.params.id), fullWidth: true })
const route = useRoute()
const { data: repository, error, status, refresh } = await useFetch<RepositoryRecord>('/api/repositories/' + String(route.params.id))
const argosId = crypto.randomUUID()
let poll: ReturnType<typeof setInterval> | undefined
let refreshing = false
onMounted(() => {
  poll = setInterval(async () => {
    if (repository.value?.status !== 'provisioning' || refreshing) return
    refreshing = true
    try { await refresh() } finally { refreshing = false }
  }, 5000)
})
onBeforeUnmount(() => clearInterval(poll))
</script>

<template>
  <div class="space-y-6">
    <p v-if="status === 'pending' && !repository" role="status">Loading repository…</p>
    <div v-else-if="error" role="alert">
      <p class="text-error">Could not load the repository.</p>
      <UButton class="mt-2" @click="refresh()">Retry</UButton>
    </div>
    <template v-else-if="repository">
      <header class="space-y-3">
        <div class="flex flex-wrap items-center gap-3">
          <h1 class="min-w-0 break-words text-2xl font-semibold text-highlighted">{{ repository.name }}</h1>
          <UBadge :color="repository.status === 'ready' ? 'success' : 'warning'">{{ repository.status }}</UBadge>
        </div>
        <p v-if="repository.status === 'provisioning'" class="text-muted" role="status">The repository is being provisioned in the background. Status updates automatically.</p>
        <p v-if="repository.provisioning_error" class="text-error" role="alert">{{ repository.provisioning_error }}</p>
      </header>

      <div class="grid items-start gap-6 lg:grid-cols-3">
        <UCard class="min-w-0">
          <template #header>
            <h2 class="text-xl font-semibold text-highlighted">Project context</h2>
          </template>
          <div class="space-y-4">
            <p class="break-words text-muted">{{ repository.description }}</p>
            <p class="whitespace-pre-wrap break-words">{{ repository.context }}</p>
            <p class="break-words text-sm text-muted">{{ repository.artifacts_namespace }}/{{ repository.artifacts_name }} · {{ repository.default_branch }}</p>
          </div>
          <template v-if="repository.status === 'failed'" #footer>
            <UButton :to="{ path: '/argos/' + argosId, query: { recover: repository.name } }">Recover creation</UButton>
          </template>
        </UCard>

        <UCard class="min-w-0 lg:col-span-2">
          <RepositoryIntents :name="repository.name">
            <template #actions>
              <UButton v-if="repository.status === 'ready'" icon="i-lucide-plus" :to="{ path: '/argos/' + argosId, query: { repository: repository.name } }">New intent</UButton>
            </template>
          </RepositoryIntents>
        </UCard>
      </div>
    </template>
  </div>
</template>
