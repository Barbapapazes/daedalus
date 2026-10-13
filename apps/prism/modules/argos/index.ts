import { addImports, createResolver, defineNuxtModule } from 'nuxt/kit'

export default defineNuxtModule({
  meta: {
    name: 'prism-argos',
  },
  moduleDependencies: {
    '@pinia/nuxt': {
      version: '^1.0.2',
    },
  },
  setup() {
    const resolver = createResolver(import.meta.url)

    addImports({
      name: 'useArgos',
      from: resolver.resolve('./runtime/app/composables/useArgos'),
    })
  },
})
