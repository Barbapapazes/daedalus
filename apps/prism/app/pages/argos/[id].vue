<script setup lang="ts">
definePageMeta({ layout: 'chat', key: route => String(route.params.id) })
const route = useRoute()
const initialPrompt = typeof route.query.recover === 'string'
  ? `Check the creation status and original brief for repository ${JSON.stringify(route.query.recover)}. Help me recover it if needed, but ask for approval before retrying creation.`
  : typeof route.query.name === 'string'
    ? `I want to create a repository named ${JSON.stringify(route.query.name)}. Help me clarify the project brief before creating anything.`
    : undefined
</script>

<template>
  <ArgosConversation
    :conversation-id="String(route.params.id)"
    :initial-repository="typeof route.query.repository === 'string' ? route.query.repository : undefined"
    :initial-prompt="initialPrompt"
  />
</template>
