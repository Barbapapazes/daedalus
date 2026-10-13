import { PiniaColada } from '@pinia/colada'

export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.use(PiniaColada)
})
