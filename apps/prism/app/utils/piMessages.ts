import type { ChatMessagesProps } from '@nuxt/ui'
import type { PiSession } from 'agents/harness/pi'

export type Conversation = Awaited<ReturnType<PiSession['messages']>>
export type ChatMessage = NonNullable<ChatMessagesProps['messages']>[number]
export type SessionSnapshot = Awaited<ReturnType<PiSession['events']>>['snapshot']
export type AssistantMessage = NonNullable<SessionSnapshot['generation']>['message']

export function piMessages(conversation: Readonly<Conversation>, partialMessage?: AssistantMessage): ChatMessage[] {
  const entries = partialMessage
    ? [...conversation, { id: 'streaming', model: [partialMessage] }]
    : conversation
  const modelMessages = entries.flatMap(entry => entry.model ?? [])
  const results = new Map(modelMessages
    .filter(message => message.role === 'toolResult')
    .map(message => [message.toolCallId, message]))

  const history = entries.flatMap(entry => (entry.model ?? []).flatMap<ChatMessage>((message, index) => {
    if (message.role !== 'user' && message.role !== 'assistant') return []

    const parts: ChatMessage['parts'] = typeof message.content === 'string'
      ? [{ type: 'text', text: message.content, state: entry.id === 'streaming' ? 'streaming' : 'done' }]
      : message.content.flatMap<ChatMessage['parts'][number]>((part) => {
          if (part.type === 'text' && part.text) return [{ type: 'text', text: part.text, state: entry.id === 'streaming' ? 'streaming' : 'done' }]
          if (part.type === 'thinking' && part.thinking) return [{ type: 'reasoning', text: part.thinking, state: entry.id === 'streaming' ? 'streaming' : 'done' }]
          if (part.type !== 'toolCall') return []

          const result = results.get(part.id)
          const output = result?.content.map(content => content.type === 'text' ? content.text : '[Image output]').join('\n') ?? ''
          const tool = {
            type: 'dynamic-tool' as const,
            toolCallId: part.id,
            toolName: part.name,
            input: part.arguments,
          }

          if (!result) return [{ ...tool, state: 'input-available' }]
          if (result.isError) return [{ ...tool, state: 'output-error', errorText: output || 'Tool execution failed.' }]
          return [{ ...tool, state: 'output-available', output }]
        })

    // Keep messages without displayable content out of the chat.
    return parts.length ? [{ id: `${entry.id}-${index}`, role: message.role, parts }] : []
  }))

  return history
}
