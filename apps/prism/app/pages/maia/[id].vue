<script setup lang="ts">
import type { RepositoryRecord } from '../../../shared/types/repository'

definePageMeta({ key: route => String(route.params.id) })
const route = useRoute()
const { data: repository } = await useFetch<RepositoryRecord>('/api/repositories/' + String(route.params.id))
const name = typeof route.query.name === 'string' ? route.query.name : undefined
// Preserve old links without assuming repository and conversation IDs are the same.
await navigateTo({
  path: '/argos/' + crypto.randomUUID(),
  query: repository.value
    ? repository.value.status === 'ready' ? { repository: repository.value.name } : { recover: repository.value.name }
    : name ? { name } : {},
}, { replace: true })
</script>

<template>
  <p class="text-muted">Opening conversation…</p>
</template>
