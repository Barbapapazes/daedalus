import type { ChatMessagesProps } from '@nuxt/ui'
import type { PiSession } from 'agents/harness/pi'
import { piMessages } from '~/utils/piMessages'
import { useMutation } from '@pinia/colada'
import { AgentClient } from 'agents/client'
import { useRuntimeConfig } from '#imports'
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue'

type Conversation = Awaited<ReturnType<PiSession['messages']>>
type ChatMessage = NonNullable<ChatMessagesProps['messages']>[number]
type SessionEvents = Awaited<ReturnType<PiSession['events']>>
type AgentEvent = Parameters<Parameters<SessionEvents['start']>[0]>[0][number]
type AssistantMessage = NonNullable<SessionEvents['snapshot']['generation']>['message']
type MessageMode = 'steer' | 'followUp'
interface QueuedMessage {
  id: string
  text: string
  mode: MessageMode
}

interface RepositoryCreation {
  id: string
  name: string
  status: 'provisioning' | 'ready' | 'failed' | 'unconfirmed'
}

interface RepositorySnapshot {
  name: string
  ref: string
  commit: string
  path: string
  ready: boolean
}

export function useArgos(id: string, initialRepository?: string, initialPrompt?: string) {
  const config = useRuntimeConfig()
  const prompt = ref('')
  const conversation = shallowRef<Conversation>([])
  const pendingMessage = shallowRef<ChatMessage>()
  const partialMessage = shallowRef<AssistantMessage>()
  const repositories = shallowRef<RepositorySnapshot[]>([])
  const creations = shallowRef<RepositoryCreation[]>([])
  let initialIntentSubmitted = false
  const repositoryName = ref('')
  const repositoryRef = ref('HEAD')
  const connected = ref(false)
  const loading = ref(false)
  const error = ref('')
  const activeRequests = ref(0)
  const sessionBusy = ref(false)
  const sending = computed(() => activeRequests.value > 0 || sessionBusy.value)
  const queuedMessages = shallowRef<QueuedMessage[]>([])
  const messages = computed<ChatMessage[]>(() => {
    const history = piMessages(conversation.value, partialMessage.value)
    return pendingMessage.value ? [...history, pendingMessage.value] : history
  })
  let client: AgentClient | undefined
  let creationPoll: ReturnType<typeof setInterval> | undefined
  let refreshingCreations = false

  function receiveEvents(chunk: unknown) {
    if (!Array.isArray(chunk)) return
    for (const event of chunk as AgentEvent[]) {
      if (event.type === 'snapshot') {
        conversation.value = [...event.entries]
        partialMessage.value = event.generation?.message
        sessionBusy.value = !!event.run
      } else if (event.type === 'run_start') {
        sessionBusy.value = true
      } else if (event.type === 'submission' && event.record.status !== 'queued') {
        queuedMessages.value = queuedMessages.value.filter(message => message.id !== event.record.requestId)
      } else if (event.type === 'message_start' && event.message.role === 'assistant') {
        partialMessage.value = event.message
      } else if (event.type === 'message_update' && partialMessage.value) {
        // shallowRef requires a new value for Vue to render each streamed update.
        const message = structuredClone(partialMessage.value)
        for (const change of event.changes) {
          if (change.type === 'message') {
            message.content = change.message.content
          } else if ('block' in change) {
            message.content[change.contentIndex] = change.block
          } else if (change.type === 'text_delta' || change.type === 'thinking_delta') {
            const block = message.content[change.contentIndex]
            if (change.type === 'text_delta' && block?.type === 'text') block.text += change.delta
            if (change.type === 'thinking_delta' && block?.type === 'thinking') block.thinking += change.delta
          }
        }
        partialMessage.value = message
      } else if (event.type === 'message_end' || event.type === 'entry_appended') {
        const entry = event.entry
        conversation.value = [...conversation.value.filter(item => item.id !== entry.id), entry]
        if (entry.model?.some(message => message.role === 'user')) pendingMessage.value = undefined
        if (event.type === 'message_end' && entry.model?.some(message => message.role === 'assistant')) {
          partialMessage.value = undefined
        }
      } else if (event.type === 'auto_retry_start' || event.type === 'run_end') {
        partialMessage.value = undefined
        if (event.type === 'run_end') sessionBusy.value = false
      }
    }
  }

  async function loadMessages() {
    if (!client) return
    loading.value = true
    try {
      const [history, snapshots, created] = await Promise.all([
        client.call<Conversation>('messages'),
        client.call<RepositorySnapshot[]>('repositories'),
        client.call<RepositoryCreation[]>('creations'),
      ])
      conversation.value = history
      repositories.value = snapshots
      creations.value = created
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Could not load the conversation.'
    } finally {
      loading.value = false
    }
  }

  const { mutate: ask } = useMutation({
    mutation: (message: QueuedMessage) => {
      if (!client || !connected.value) throw new Error('Not connected. Please wait while the connection is restored.')
      // One socket observer renders the session; concurrent RPCs only submit/wait.
      // This avoids applying each generation delta once per queued request.
      return client.call<{ status: string; text?: string }>('ask', [message.text, message.mode, message.id], { timeout: 0 })
    },
    onMutate: (message) => {
      error.value = ''
      if (!sending.value) {
        pendingMessage.value = {
          id: message.id,
          role: 'user',
          parts: [{ type: 'text', text: message.text }],
        }
      } else {
        queuedMessages.value = [...queuedMessages.value, message]
      }
      activeRequests.value++
      // Clear on submission, not on completion: don't erase the next draft.
      prompt.value = ''
    },
    onSuccess: (result) => {
      sessionStorage.removeItem('argos:intent:' + id)
      if (result.status !== 'done') error.value = `Could not complete the response (${result.status}).`
    },
    onError: (cause, message) => {
      error.value = cause instanceof Error ? cause.message : 'Could not send the message.'
      if (!prompt.value) prompt.value = message.text
    },
    onSettled: async (_result, _cause, message) => {
      activeRequests.value--
      queuedMessages.value = queuedMessages.value.filter(item => item.id !== message.id)
      if (!sending.value) {
        await loadMessages()
        pendingMessage.value = undefined
        partialMessage.value = undefined
      }
    },
  })

  const { mutate: attachRepository, isLoading: attaching } = useMutation({
    mutation: () => {
      if (!client || !connected.value) throw new Error('Not connected. Please wait while the connection is restored.')
      return client.call<RepositorySnapshot>('attachRepository', [repositoryName.value.trim(), repositoryRef.value.trim()], { timeout: 0 })
    },
    onMutate: () => { error.value = '' },
    onSuccess: () => {
      repositoryName.value = ''
      repositoryRef.value = 'HEAD'
    },
    onError: (cause) => {
      error.value = cause instanceof Error ? cause.message : 'Could not attach the repository.'
    },
    onSettled: () => loadMessages(),
  })

  function sendMessage(mode: MessageMode = 'steer') {
    const message = prompt.value.trim()
    if (!client || !connected.value || loading.value || attaching.value || !message) return

    ask({ id: crypto.randomUUID(), text: message, mode })
  }

  function onPromptKeydown(event: KeyboardEvent) {
    if (event.isComposing || event.keyCode === 229 || event.repeat) return
    // Option on macOS and Alt on Windows/Linux both set KeyboardEvent.altKey.
    const followUp = event.key === 'Enter' && event.altKey && !event.shiftKey && !event.ctrlKey && !event.metaKey
    if (!followUp) return
    event.preventDefault()
    event.stopPropagation()
    sendMessage('followUp')
  }

  onMounted(() => {
    creationPoll = setInterval(async () => {
      if (!client || !connected.value || refreshingCreations || !creations.value.some(creation => creation.status === 'provisioning' || creation.status === 'unconfirmed')) return
      refreshingCreations = true
      try {
        const created = await client.call<RepositoryCreation[]>('creations')
        if (connected.value) creations.value = created
      } catch {
        // Keep the last known status; transient failures must not imply provisioning failed.
      } finally {
        refreshingCreations = false
      }
    }, 5000)
    try {
      client = new AgentClient({
        agent: 'Argos',
        name: id,
        host: config.public.argosHost,
      })
      client.addEventListener('message', (event) => {
        if (typeof event.data !== 'string') return
        try {
          const payload = JSON.parse(event.data)
          if (payload.type === 'argos-events') receiveEvents(payload.events)
        } catch {
          // Other SDK frames aren't part of the conversation event protocol.
        }
      })
      client.addEventListener('open', () => {
        connected.value = true
        error.value = ''
        void loadMessages().then(() => {
          if (!error.value && !initialIntentSubmitted && !initialRepository && !sending.value) {
            const intent = sessionStorage.getItem('argos:intent:' + id) ?? initialPrompt
            if (intent && !conversation.value.length) {
              initialIntentSubmitted = true
              prompt.value = intent
              // A refresh or lost acknowledgement must not submit the intent twice.
              ask({ id: id + ':initial', text: intent, mode: 'followUp' })
            } else if (intent) {
              // A restored conversation may already contain the initial submission.
              sessionStorage.removeItem('argos:intent:' + id)
            }
          }
          if (initialRepository && !repositories.value.some(repository => repository.name === initialRepository && repository.ready) && !sending.value && !attaching.value) {
            repositoryName.value = initialRepository
            repositoryRef.value = 'HEAD'
            attachRepository()
          }
        })
      })
      client.addEventListener('close', () => {
        connected.value = false
        sessionBusy.value = false
        partialMessage.value = undefined
      })
      client.addEventListener('error', () => {
        error.value = 'Could not connect. Please try again shortly.'
      })
    } catch (cause) {
      error.value = cause instanceof Error ? cause.message : 'Could not initialize the conversation.'
    }
  })

  onBeforeUnmount(() => {
    clearInterval(creationPoll)
    connected.value = false
    client?.close()
    client = undefined
  })

  return {
    prompt, conversation, connected, loading, sending, error, messages, sendMessage,
    queuedMessages, onPromptKeydown,
    repositories, creations, repositoryName, repositoryRef, attaching, attachRepository,
    streaming: computed(() => sending.value && !!partialMessage.value),
  }
}
