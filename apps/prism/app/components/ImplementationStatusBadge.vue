<script setup lang="ts">
const props = defineProps<{ status: string }>()
const labels: Record<string, string> = {
  completed: 'Implemented',
  implemented: 'Implemented',
  integration_queued: 'Integration queued',
  integrating: 'Integrating',
  merged: 'Merged into main',
  integration_blocked: 'Integration blocked',
  integration_failed: 'Integration failed',
}
const label = computed(() => labels[props.status] ?? props.status)
const color = computed(() => {
  if (props.status === 'merged') return 'success'
  if (['failed', 'integration_failed'].includes(props.status)) return 'error'
  if (['blocked', 'integration_blocked'].includes(props.status)) return 'warning'
  if (['running', 'preparing', 'implementing', 'publishing', 'integrating', 'integration_queued'].includes(props.status)) return 'primary'
  return 'neutral'
})
</script>

<template>
  <UBadge :color="color" variant="subtle" class="capitalize">{{ label }}</UBadge>
</template>
