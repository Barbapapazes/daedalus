import type { IntentDetail } from '../../../shared/types/intent'

export default defineEventHandler(async (event) => {
  const id = getRouterParam(event, 'id')
  if (!id || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) {
    throw createError({ statusCode: 400, statusMessage: 'Invalid intent ID' })
  }
  return intentApi<IntentDetail>(event, '/' + id.toLowerCase())
})
