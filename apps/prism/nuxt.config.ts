export default defineNuxtConfig({
  compatibilityDate: '2026-10-04',
  ssr: false,
  devServer: {
    // Nuxt types this as a number, but its listener supports port discovery options.
    port: { port: 3000, random: false, alternativePortRange: [] } as unknown as number,
  },
  runtimeConfig: {
    apiUrl: 'http://localhost:8000',
    apiAccessClientId: '',
    apiAccessClientSecret: '',
    talosUrl: 'http://localhost:5174',
    talosAccessClientId: '',
    talosAccessClientSecret: '',
    public: {
      argosHost: 'http://localhost:5173',
    },
  },
  modules: ['@comark/nuxt', '@nuxt/ui', '@pinia/nuxt'],
  css: ['~/assets/css/main.css'],
  app: {
    head: {
      title: 'Daedalus',
      meta: [{ name: 'description', content: 'Shape ideas and build projects.' }],
    },
  },
})
