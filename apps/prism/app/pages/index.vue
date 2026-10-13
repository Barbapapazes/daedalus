<script setup lang="ts">
import type { IntentPage } from '../../shared/types/intent'
import { intentWorkflowStatus } from '~/utils/intentWorkflow'
import type { RepositoryPage } from '../../shared/types/repository'

definePageMeta({ layout: 'chat' })

const intent = ref('')
const starting = ref(false)
const startError = ref('')
const { remember } = useRecentConversations()

const greeting = computed(() => {
  const hour = new Date().getHours()
  return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'
})

const suggestions = [
  { label: 'Build something new', icon: 'i-lucide-sparkles', prompt: 'I want to build a new app. Help me shape the idea.' },
  { label: 'Explore a feature', icon: 'i-lucide-lightbulb', prompt: 'I have a feature idea I would like to explore.' },
  { label: 'Find a project', icon: 'i-lucide-folder-search', prompt: 'Help me find a repository to work on.' },
]

const { data: repositories, status: projectsStatus, error: projectsError } = await useFetch<RepositoryPage>('/api/repositories', { lazy: true })
const projects = computed(() => repositories.value?.data.slice(0, 6) ?? [])
const { data: intentPage, status: intentsStatus, error: intentsError, refresh: refreshIntents } = await useFetch<IntentPage>('/api/intents', { lazy: true })
const intents = computed(() => intentPage.value?.data.slice(0, 6) ?? [])
let intentTimer: ReturnType<typeof setTimeout> | undefined
let stopped = false
function scheduleIntents() {
  if (stopped) return
  intentTimer = setTimeout(async () => {
    if (intentsStatus.value !== 'pending') await refreshIntents()
    scheduleIntents()
  }, 5000)
}
onMounted(scheduleIntents)
onBeforeUnmount(() => {
  stopped = true
  clearTimeout(intentTimer)
})

async function startConversation(text = intent.value) {
  const prompt = text.trim()
  if (!prompt || starting.value) return
  intent.value = prompt
  starting.value = true
  startError.value = ''
  try {
    const id = crypto.randomUUID()
    // Keep the intent out of URLs and retain it until Argos accepts it.
    sessionStorage.setItem('argos:intent:' + id, prompt)
    remember(id, prompt)
    await navigateTo('/argos/' + id)
  } catch {
    startError.value = 'Could not start the conversation. Please try again.'
  } finally {
    starting.value = false
  }
}
</script>

<template>
  <div class="min-h-0 flex-1 overflow-y-auto [container-type:size]">
    <UContainer class="pb-8">
      <div class="flex min-h-[max(0px,calc(100cqh-7.25rem))] flex-col justify-center gap-4 py-8 sm:gap-6">
        <header class="space-y-2">
          <h1 class="text-4xl font-bold text-highlighted sm:text-5xl">{{ greeting }}</h1>
          <p class="text-lg text-muted">What do you want to build or work on?</p>
        </header>

        <UChatPrompt
          v-model="intent"
          aria-label="Your message"
          :ui="{ footer: 'justify-end' }"
          placeholder="Share an idea, explore a feature, or ask a question…"
          :maxlength="16000"
          :disabled="starting"
          required
          @submit="startConversation()"
        >
          <template #footer>
            <UChatPromptSubmit aria-label="Start conversation" :loading="starting" :disabled="starting || !intent.trim()" />
          </template>
        </UChatPrompt>

        <p v-if="startError" class="text-sm text-error" role="alert">{{ startError }}</p>

        <div class="-mt-2 flex flex-wrap gap-2 sm:-mt-4" aria-label="Suggested conversations">
          <UButton
            v-for="suggestion in suggestions"
            :key="suggestion.label"
            :label="suggestion.label"
            :icon="suggestion.icon"
            size="sm"
            color="neutral"
            variant="link"
            :disabled="starting"
            @click="startConversation(suggestion.prompt)"
          />
        </div>
      </div>

      <section aria-labelledby="intents-heading" :aria-busy="intentsStatus === 'pending'" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <h2 id="intents-heading" class="flex items-center gap-2 text-xl font-semibold text-highlighted sm:col-span-3">
          <UIcon name="i-lucide-lightbulb" class="size-5 text-primary" />
          Intents
        </h2>
        <template v-if="intentsStatus === 'pending' && !intentPage">
          <span class="sr-only" role="status">Loading intents…</span>
          <div v-for="index in 6" :key="index" class="space-y-3 rounded-xl border border-default bg-default p-4" aria-hidden="true">
            <USkeleton class="h-6 w-3/4" />
            <USkeleton class="h-12 w-full" />
          </div>
        </template>
        <p v-else-if="intentsError && !intentPage" class="text-sm text-error sm:col-span-3" role="alert">Could not load intents.</p>
        <p v-else-if="!intents.length" class="text-sm text-muted sm:col-span-3">No intents yet.</p>
        <template v-else>
          <NuxtLink
            v-for="item in intents"
            :key="item.id"
            :to="'/intents/' + item.id"
            class="flex min-w-0 flex-col items-start rounded-xl border border-default bg-default p-4 transition-colors hover:border-primary focus-visible:outline-2 focus-visible:outline-primary"
          >
            <ImplementationStatusBadge :status="intentWorkflowStatus(item.status, item.integration)" class="mb-3" />
            <h3 class="line-clamp-2 break-words text-lg font-semibold text-highlighted">{{ item.intent.goal }}</h3>
            <p class="mt-2 line-clamp-2 break-words text-sm text-muted">{{ item.intent.scope }}</p>
            <p v-if="item.repositories.length" class="mt-3 line-clamp-2 break-words text-xs text-dimmed">{{ item.repositories.map(repository => repository.name).join(' · ') }}</p>
          </NuxtLink>
        </template>
        <p v-if="intentsError && intentPage" class="text-sm text-warning sm:col-span-3" role="status">Could not refresh intents. Showing the last known delivery status.</p>
      </section>

      <section aria-labelledby="projects-heading" :aria-busy="projectsStatus === 'pending'" class="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <h2 id="projects-heading" class="flex items-center gap-2 text-xl font-semibold text-highlighted sm:col-span-3">
          <UIcon name="i-lucide-folder" class="size-5 text-primary" />
          Projects
        </h2>
        <template v-if="projectsStatus === 'pending' && !repositories">
          <span class="sr-only" role="status">Loading projects…</span>
          <div v-for="index in 6" :key="index" class="space-y-3 rounded-xl border border-default bg-default p-4" aria-hidden="true">
            <USkeleton class="h-6 w-3/4" />
            <USkeleton class="h-12 w-full" />
          </div>
        </template>
        <p v-else-if="projectsError" class="text-sm text-error sm:col-span-3" role="alert">Could not load projects.</p>
        <p v-else-if="!projects.length" class="text-sm text-muted sm:col-span-3">No projects yet.</p>
        <template v-else>
          <NuxtLink
            v-for="project in projects"
            :key="project.id"
            :to="'/repositories/' + project.id"
            class="flex min-w-0 flex-col items-start rounded-xl border border-default bg-default p-4 transition-colors hover:border-primary focus-visible:outline-2 focus-visible:outline-primary"
          >
            <h3 class="line-clamp-2 break-words text-lg font-semibold text-highlighted">{{ project.name }}</h3>
            <p class="mt-2 line-clamp-2 break-words text-sm text-muted">{{ project.description }}</p>
          </NuxtLink>
        </template>
      </section>
    </UContainer>
  </div>
</template>
