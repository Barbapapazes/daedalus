<script setup lang="ts">
import type { ChatMessage } from '~/utils/piMessages'
import { implementationLink } from '~/utils/implementationLink'
import { repositoryLink } from '~/utils/repositoryLink'

defineProps<{ messages: ChatMessage[]; sending: boolean; streaming: boolean }>()

</script>

<template>
  <UChatMessages
    :messages="messages"
    :should-auto-scroll="true"
    :status="sending ? (streaming ? 'streaming' : 'submitted') : 'ready'"
    :assistant="{ ui: { content: 'break-words' } }"
    :user="{ ui: { content: 'whitespace-pre-wrap break-words' } }"
  >
    <template #content="{ message }">
      <template v-for="(part, index) in message.parts" :key="index">
        <template v-if="part.type === 'text'">
          <Suspense v-if="message.role === 'assistant'">
            <ChatComark :value="part.text" :streaming="part.state === 'streaming'" />
            <template #fallback>{{ part.text }}</template>
          </Suspense>
          <span v-else>{{ part.text }}</span>
        </template>
        <UChatReasoning
          v-else-if="part.type === 'reasoning'"
          :text="part.text"
          :streaming="part.state === 'streaming'"
          chevron="leading"
          class="my-2"
        >
          <Suspense>
            <ChatComark :value="part.text" :streaming="part.state === 'streaming'" />
            <template #fallback>{{ part.text }}</template>
          </Suspense>
        </UChatReasoning>
        <UChatTool
          v-else-if="part.type === 'dynamic-tool'"
          :text="part.toolName"
          :suffix="part.state === 'output-error' ? 'Failed' : part.state === 'output-available' ? 'Completed' : sending ? 'Running' : 'No result'"
          :icon="part.state === 'output-error' ? 'i-lucide-circle-alert' : 'i-lucide-terminal'"
          :loading="sending && part.state === 'input-available'"
          variant="card"
          class="my-2"
        >
          <div class="space-y-3 text-sm">
            <div>
              <p class="mb-1 font-medium text-muted">Input</p>
              <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-all">{{ JSON.stringify(part.input, null, 2) }}</pre>
            </div>
            <div v-if="part.state === 'output-available'">
              <p class="mb-1 font-medium text-muted">Output</p>
              <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all">{{ part.output || 'No output.' }}</pre>
              <UButton
                v-if="part.toolName === 'implement_feature' && implementationLink(part.output)"
                :to="implementationLink(part.output)"
                color="neutral"
                variant="subtle"
                icon="i-lucide-eye"
                class="mt-2"
              >
                View implementation
              </UButton>
            </div>
            <div v-else-if="part.state === 'output-error'" role="alert">
              <p class="mb-1 font-medium text-error">Error</p>
              <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all">{{ part.errorText }}</pre>
            </div>
          </div>
        </UChatTool>
        <UButton
          v-if="part.type === 'dynamic-tool' && part.toolName === 'create_repository' && part.state === 'output-available' && repositoryLink(part.output)"
          :to="repositoryLink(part.output)"
          color="neutral"
          variant="subtle"
          icon="i-lucide-folder-git-2"
          class="my-2"
        >
          View project
        </UButton>
      </template>
    </template>
  </UChatMessages>
</template>
