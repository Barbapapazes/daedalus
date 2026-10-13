<script setup lang="ts">
const route = useRoute()
</script>

<template>
  <UDashboardNavbar class="shrink-0 border-b-0" :ui="{ left: 'min-w-0' }">
    <template #left>
      <NuxtLink to="/" class="flex items-center gap-2 rounded-md font-semibold text-highlighted focus-visible:outline-2 focus-visible:outline-primary" aria-label="Daedalus home">
        <UIcon name="i-lucide-origami" class="size-6 text-primary" />
        <span>Daedalus</span>
      </NuxtLink>
    </template>
    <template #right>
      <UTooltip v-if="route.path !== '/'" text="New conversation">
        <UButton to="/" icon="i-lucide-circle-plus" color="neutral" variant="ghost" aria-label="New conversation" />
      </UTooltip>
    </template>
  </UDashboardNavbar>
</template>
