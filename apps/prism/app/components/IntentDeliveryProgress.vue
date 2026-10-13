<script setup lang="ts">
import type { IntegrationSummary } from '../../shared/types/intent'

const props = defineProps<{ implementationStatus: string; integration?: IntegrationSummary | null }>()
const implemented = computed(() => !!props.integration || ['completed', 'implemented'].includes(props.implementationStatus))
const merged = computed(() => props.integration?.status === 'integrated')
const integrationStopped = computed(() => ['blocked', 'failed'].includes(props.integration?.status ?? ''))
const implementationStopped = computed(() => !implemented.value && ['blocked', 'failed'].includes(props.implementationStatus))
const steps = computed(() => [
  { label: 'Implement', done: implemented.value, active: !implemented.value && !implementationStopped.value, stopped: implementationStopped.value },
  { label: 'Integrate', done: merged.value, active: implemented.value && !merged.value && !integrationStopped.value, stopped: integrationStopped.value },
  { label: 'Main', done: merged.value, active: false, stopped: false },
])
</script>

<template>
  <ol class="flex items-start gap-2" aria-label="Intent delivery progress">
    <li v-for="(step, index) in steps" :key="step.label" class="flex min-w-0 flex-1 items-start gap-2" :aria-current="step.active ? 'step' : undefined">
      <div class="flex flex-col items-center gap-1 text-center text-xs" :class="step.done ? 'text-success' : step.stopped ? 'text-warning' : step.active ? 'text-primary' : 'text-dimmed'">
        <UIcon :name="step.done ? 'i-lucide-circle-check' : step.stopped ? 'i-lucide-circle-alert' : step.active ? 'i-lucide-loader-circle' : 'i-lucide-circle'" class="size-5 shrink-0" :class="{ 'animate-spin': step.active }" />
        <span>{{ step.label }}</span>
        <span class="sr-only">{{ step.done ? 'complete' : step.stopped ? 'stopped' : step.active ? 'in progress' : 'pending' }}</span>
      </div>
      <span v-if="index < steps.length - 1" class="mt-2.5 h-px flex-1 bg-border" aria-hidden="true" />
    </li>
  </ol>
</template>
