interface RecentConversation {
  id: string
  title: string
  updatedAt: number
}

const storageKey = 'daedalus:recent-conversations'

export function useRecentConversations() {
  // Only navigation metadata is stored locally; chat history remains in Argos.
  const conversations = useState<RecentConversation[]>('recent-conversations', () => [])

  onMounted(() => {
    try {
      const stored: unknown = JSON.parse(localStorage.getItem(storageKey) ?? '[]')
      if (Array.isArray(stored)) {
        conversations.value = stored.filter((item): item is RecentConversation =>
          item && typeof item.id === 'string' && /^[0-9a-f-]{36}$/i.test(item.id)
          && typeof item.title === 'string' && typeof item.updatedAt === 'number',
        ).slice(0, 12)
      }
    } catch {
      // Storage may be unavailable; navigation and conversations still work.
    }
  })

  function remember(id: string, title?: string) {
    const existing = conversations.value.find(item => item.id === id)
    conversations.value = [
      { id, title: title?.trim().slice(0, 100) || existing?.title || 'New conversation', updatedAt: Date.now() },
      ...conversations.value.filter(item => item.id !== id),
    ].slice(0, 12)
    try {
      localStorage.setItem(storageKey, JSON.stringify(conversations.value))
    } catch {
      // Keep the in-memory list when browser storage is unavailable.
    }
  }

  return { conversations, remember }
}
