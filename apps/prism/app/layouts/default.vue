<script setup lang="ts">
const route = useRoute()
</script>

<template>
  <div class="min-h-0 flex-1 overflow-y-auto">
    <UContainer class="py-8 sm:py-12" :class="{ 'max-w-none': route.meta.fullWidth }">
      <slot />
    </UContainer>
  </div>
</template>
