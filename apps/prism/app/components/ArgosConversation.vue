<script setup lang="ts">
import { implementationLink } from '~/utils/implementationLink'

const props = defineProps<{
  conversationId: string
  initialRepository?: string
  initialPrompt?: string
}>()
const {
  prompt, connected, loading, sending, streaming, error, messages, sendMessage,
  queuedMessages, onPromptKeydown,
  repositories, creations, repositoryName, repositoryRef, attaching, attachRepository,
} = useArgos(
  props.conversationId,
  props.initialRepository,
  props.initialPrompt,
)

const hasConnected = ref(false)
watch(connected, value => {
  if (value) hasConnected.value = true
})
const connectionLost = computed(() => !connected.value && (hasConnected.value || !!error.value))

const { remember } = useRecentConversations()
onMounted(() => remember(props.conversationId))
const conversationTitle = computed(() => {
  const part = messages.value.find(message => message.role === 'user')?.parts.find((part: { type: string; text?: string }) => part.type === 'text')
  return part?.type === 'text' ? part.text ?? '' : ''
})
watch(conversationTitle, title => {
  if (title) remember(props.conversationId, title)
})

const latestImplementation = computed(() => {
  for (const message of [...messages.value].reverse()) {
    for (const part of [...message.parts].reverse()) {
      if (part.type !== 'dynamic-tool' || part.toolName !== 'implement_feature') continue
      const link = implementationLink(part.state === 'output-available' ? part.output : part.state === 'output-error' ? part.errorText : undefined)
      if (link) return link
    }
  }
})
</script>

<template>
  <div class="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden md:flex-row">
    <aside class="flex max-h-48 shrink-0 flex-col overflow-y-auto border-b border-default md:max-h-none md:w-72 md:border-b-0 md:border-r" aria-label="Conversation context">
    <header class="shrink-0 p-4">
      <div class="flex items-center justify-between gap-3">
        <h1 class="min-w-0 break-words text-sm font-medium text-highlighted">{{ conversationTitle || 'New conversation' }}</h1>
        <div class="flex shrink-0 items-center gap-1">
          <UButton v-if="latestImplementation" :to="latestImplementation" color="neutral" variant="ghost" size="sm" icon="i-lucide-eye" aria-label="View implementation">
            <span class="sr-only">View implementation</span>
          </UButton>
        </div>
      </div>
      <div v-if="connectionLost" class="mt-2 flex items-start gap-2 text-sm text-error" role="alert">
        <UIcon name="i-lucide-unlink" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
        <p>{{ error || 'Connection lost. Reconnecting…' }}</p>
      </div>
    </header>
      <div class="mt-auto p-4">
      <section class="space-y-3 text-sm" aria-labelledby="repository-context-title">
        <div class="flex items-center gap-2">
          <UIcon name="i-lucide-folder-git-2" class="size-4 text-muted" aria-hidden="true" />
          <h2 id="repository-context-title" class="font-medium text-highlighted">Repository context</h2>
          <UBadge v-if="repositories.length" color="neutral" variant="subtle" size="sm">{{ repositories.length }}</UBadge>
        </div>
        <ul v-if="repositories.length" class="max-h-48 space-y-3 overflow-y-auto">
          <li v-for="repository in repositories" :key="repository.path" class="space-y-1 border-l-2 border-default pl-3">
            <p class="break-words font-medium">{{ repository.name }} · {{ repository.ref }}</p>
            <p class="break-all text-xs text-muted">Commit {{ repository.commit }}</p>
            <p class="break-all text-xs text-muted">{{ repository.path }}</p>
            <p v-if="!repository.ready" class="text-warning">Loading incomplete. Ask to retry or load the same name and ref below.</p>
          </li>
        </ul>
        <p class="text-xs text-muted">Find or create a project through the conversation, or load one below. Up to 8 repositories can coexist. Reloading a repository discards local edits.</p>
        <form class="flex flex-col gap-2" @submit.prevent="attachRepository()">
          <UInput v-model="repositoryName" class="min-w-0 flex-1" aria-label="Artifacts repository name" placeholder="Repository name" required :maxlength="128" :disabled="!connected || loading || sending || attaching" />
          <UInput v-model="repositoryRef" class="min-w-0 flex-1" aria-label="Branch, tag or commit" placeholder="Branch, tag or commit" required :maxlength="256" :disabled="!connected || loading || sending || attaching" />
          <UButton type="submit" color="neutral" variant="soft" :loading="attaching" :disabled="!connected || loading || sending || attaching || !repositoryName.trim() || !repositoryRef.trim()">Load repository</UButton>
        </form>
        <p v-if="repositories.length" class="text-xs text-muted">Local copies can be inspected and modified here, but changes cannot be committed or pushed.</p>
      </section>
      </div>
    </aside>

    <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
      <div class="min-h-0 flex-1 overflow-y-auto">
        <UContainer class="min-h-full space-y-5 py-4 sm:py-6">
          <p v-if="error && !connectionLost" class="text-sm text-error" role="alert">{{ error }}</p>
  
          <section v-if="creations.length" class="space-y-3" aria-label="Repository creation status">
            <UCard v-for="creation in creations" :key="creation.id">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="font-medium">{{ creation.name }}</span>
                <UBadge :color="creation.status === 'ready' ? 'success' : 'warning'" variant="subtle">{{ creation.status }}</UBadge>
                <UButton v-if="creation.status !== 'unconfirmed'" :to="'/repositories/' + creation.id" color="neutral" variant="soft" size="sm">View project</UButton>
              </div>
              <p class="mt-2 text-sm text-muted">
                {{ creation.status === 'provisioning' ? 'Provisioning in the background. Status updates automatically.' : creation.status === 'ready' ? 'Repository ready. Implementation requires separate approval.' : 'Creation is not confirmed as ready. Ask to check or retry the original brief.' }}
              </p>
            </UCard>
          </section>
  
          <AgentMessages v-if="messages.length || sending" :messages="messages" :sending="sending" :streaming="streaming" />
          <div v-else-if="connected && !loading" class="py-16 text-center">
            <UIcon name="i-lucide-sparkles" class="mb-3 size-8 text-muted" />
            <h2 class="text-xl font-semibold text-highlighted">What do you want to work on?</h2>
            <p class="mt-2 text-sm text-muted">Share an idea, ask a question, or name a project.</p>
          </div>
        </UContainer>
      </div>

      <div class="shrink-0" @keydown.capture="onPromptKeydown">
        <UContainer class="pb-3 pt-2 sm:pb-4">
          <ul v-if="queuedMessages.length" class="mb-3 max-h-32 space-y-2 overflow-y-auto text-sm" aria-label="Queued messages" aria-live="polite">
            <li v-for="message in queuedMessages" :key="message.id" class="rounded-lg border border-default p-3">
              <span class="font-medium">{{ message.mode === 'steer' ? 'Steering' : 'Follow-up' }} queued:</span>
              <span class="whitespace-pre-wrap break-words"> {{ message.text }}</span>
            </li>
          </ul>
          <UChatPrompt v-model="prompt" aria-label="Your message" :ui="{ footer: 'justify-end' }" :maxlength="16000" required placeholder="Send a message…" :disabled="!connected || loading || attaching" @submit="sendMessage()">
            <template #footer>
              <UChatPromptSubmit :aria-label="sending ? 'Send steering message' : 'Send message'" :disabled="!connected || loading || attaching || !prompt.trim()" />
            </template>
          </UChatPrompt>
          <p class="mt-2 text-center text-xs text-muted">
            {{ sending ? 'Enter to steer' : 'Enter to send' }} · Alt + Enter for follow-up · Shift + Enter for a new line
          </p>
        </UContainer>
      </div>
    </div>
  </div>
</template>
