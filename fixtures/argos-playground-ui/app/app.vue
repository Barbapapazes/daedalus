<script setup lang="ts">
interface Todo {
  id: string
  title: string
  completed: boolean
}

const { data, status, error, refresh } = await useFetch<{ todos: Todo[] }>('/api/todos')
const todos = computed(() => data.value?.todos ?? [])
const remaining = computed(() => todos.value.filter(todo => !todo.completed).length)
const title = ref('')
const editingId = ref<string | null>(null)
const editTitle = ref('')
const saving = ref(false)
const actionError = ref('')

async function mutate(action: () => Promise<unknown>) {
  if (saving.value) return
  saving.value = true
  actionError.value = ''
  try {
    await action()
    await refresh()
  } catch (cause) {
    const failure = cause as { data?: { error?: string }, message?: string }
    actionError.value = failure.data?.error ?? failure.message ?? 'Something went wrong. Please try again.'
  } finally {
    saving.value = false
  }
}

function addTodo() {
  if (!title.value.trim()) return
  return mutate(async () => {
    await $fetch('/api/todos', { method: 'POST', body: { title: title.value } })
    title.value = ''
  })
}

function toggleTodo(todo: Todo, completed: boolean) {
  return mutate(() => $fetch('/api/todos/' + todo.id, { method: 'PATCH', body: { completed } }))
}

function startEditing(todo: Todo) {
  editingId.value = todo.id
  editTitle.value = todo.title
}

function saveTitle(todo: Todo) {
  if (!editTitle.value.trim()) return
  return mutate(async () => {
    await $fetch('/api/todos/' + todo.id, { method: 'PATCH', body: { title: editTitle.value } })
    editingId.value = null
  })
}

function removeTodo(todo: Todo) {
  return mutate(async () => {
    await $fetch('/api/todos/' + todo.id, { method: 'DELETE' })
    if (editingId.value === todo.id) editingId.value = null
  })
}
</script>

<template>
  <UApp>
    <main class="mx-auto max-w-xl px-4 py-16">
      <header class="mb-8">
        <h1 class="text-3xl font-semibold tracking-tight">Todo list</h1>
        <p class="mt-2 text-muted">One thing at a time. {{ remaining }} left to do.</p>
      </header>

      <UCard>
        <form class="flex gap-2" @submit.prevent="addTodo">
          <UInput
            v-model="title"
            class="flex-1"
            placeholder="What needs doing?"
            aria-label="New todo"
            :maxlength="200"
            :disabled="saving"
          />
          <UButton type="submit" :disabled="saving || !title.trim()">Add</UButton>
        </form>

        <UAlert v-if="actionError" class="mt-4" color="error" :title="actionError" />
        <div v-if="error" class="mt-4 space-y-2" role="alert">
          <UAlert color="error" title="Could not load todos. Is the API running?" />
          <UButton variant="soft" :disabled="status === 'pending'" @click="refresh()">Retry</UButton>
        </div>
        <p v-else-if="status === 'pending'" class="py-8 text-center text-muted" role="status">Loading…</p>
        <p v-else-if="!todos.length" class="py-8 text-center text-muted">All clear. Add your first todo above.</p>

        <ul v-if="todos.length" class="mt-4 divide-y divide-default">
          <li v-for="todo in todos" :key="todo.id" class="flex items-center gap-3 py-3">
            <UCheckbox
              :model-value="todo.completed"
              :aria-label="'Mark ' + todo.title + (todo.completed ? ' incomplete' : ' complete')"
              :disabled="saving"
              @update:model-value="toggleTodo(todo, $event === true)"
            />
            <form v-if="editingId === todo.id" class="flex min-w-0 flex-1 flex-wrap gap-2" @submit.prevent="saveTitle(todo)">
              <UInput v-model="editTitle" class="min-w-0 flex-1" aria-label="Edit todo" :maxlength="200" :disabled="saving" />
              <UButton type="submit" size="sm" :disabled="saving || !editTitle.trim()">Save</UButton>
              <UButton size="sm" color="neutral" variant="ghost" :disabled="saving" @click="editingId = null">Cancel</UButton>
            </form>
            <template v-else>
              <span class="min-w-0 flex-1 break-words" :class="{ 'line-through text-muted': todo.completed }">{{ todo.title }}</span>
              <UButton size="sm" color="neutral" variant="ghost" :aria-label="'Edit ' + todo.title" :disabled="saving" @click="startEditing(todo)">Edit</UButton>
            </template>
            <UButton size="sm" color="error" variant="ghost" :aria-label="'Delete ' + todo.title" :disabled="saving" @click="removeTodo(todo)">Delete</UButton>
          </li>
        </ul>
      </UCard>
      <p class="mt-4 text-center text-sm text-muted">Demo only — todos reset when the API restarts.</p>
    </main>
  </UApp>
</template>
