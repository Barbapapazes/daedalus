<script setup lang="ts">
definePageMeta({ layout: 'chat', key: route => String(route.params.id) })
const route = useRoute()
const { data: intent, error, status, refresh } = await useIntent(String(route.params.id))
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col overflow-hidden">
    <header class="flex shrink-0 items-center gap-3 border-b border-default px-4 py-2 sm:px-6">
      <UButton to="/" color="neutral" variant="ghost" icon="i-lucide-arrow-left" aria-label="Back to home" />
      <h1 class="min-w-0 truncate font-semibold text-highlighted">{{ intent?.referenceIntent.goal || 'Intent' }}</h1>
    </header>
    <div v-if="!intent" class="space-y-3 p-6">
      <p v-if="status === 'pending'" class="text-muted" role="status">Loading intent…</p>
      <template v-else-if="error">
        <p class="text-error" role="alert">{{ error.statusCode === 404 ? 'Intent not found.' : 'Could not load the intent.' }}</p>
        <UButton color="neutral" variant="soft" @click="refresh()">Retry</UButton>
      </template>
    </div>
    <template v-else>
      <p v-if="error" class="shrink-0 px-4 py-2 text-sm text-warning" role="alert">Could not refresh the intent status. Showing the last known information.</p>
      <div class="flex min-h-0 flex-1 flex-col overflow-y-auto lg:flex-row lg:overflow-hidden">
        <div class="flex h-[75dvh] shrink-0 flex-col lg:h-auto lg:min-h-0 lg:min-w-0 lg:flex-1 lg:shrink">
          <ArgosConversation :key="intent.conversationId" :conversation-id="intent.conversationId" />
        </div>
        <IntentImplementationPanel :intent="intent" class="shrink-0 border-t border-default lg:w-80 lg:overflow-y-auto lg:border-l lg:border-t-0 xl:w-96" />
      </div>
    </template>
  </div>
</template>
