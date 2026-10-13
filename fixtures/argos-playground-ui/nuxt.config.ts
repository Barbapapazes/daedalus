export default defineNuxtConfig({
  compatibilityDate: '2026-10-04',
  modules: ['@nuxt/ui'],
  css: ['~/assets/css/main.css'],
  runtimeConfig: {
    todoApiUrl: 'http://127.0.0.1:3000',
  },
  app: {
    head: {
      title: 'Todo list',
      meta: [{ name: 'description', content: 'A simple todo list.' }],
    },
  },
})
