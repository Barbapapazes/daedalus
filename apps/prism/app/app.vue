<template>
  <UApp>
    <UDashboardGroup class="h-dvh">
      <div class="m-2 flex min-h-0 min-w-0 flex-1 overflow-hidden rounded-xl bg-default/75 shadow-sm ring ring-default sm:m-4">
        <UDashboardPanel id="prism" class="min-h-0" :ui="{ body: 'min-h-0 gap-0 overflow-hidden p-0 sm:p-0' }">
          <template #header>
            <AppNavbar />
          </template>
          <template #body>
            <NuxtLayout>
              <NuxtPage />
            </NuxtLayout>
          </template>
        </UDashboardPanel>
      </div>
    </UDashboardGroup>
  </UApp>
</template>
