import type { IntentPage } from '../../../shared/types/intent'

export default defineEventHandler(async (event) => {
  const query = getQuery(event)
  const params = new URLSearchParams()
  for (const key of ['limit', 'page'] as const) {
    const value = query[key]
    if (value === undefined) continue
    if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value) || !Number.isSafeInteger(Number(value)) || (key === 'limit' && Number(value) > 200)) {
      throw createError({ statusCode: 400, statusMessage: 'Invalid intent pagination' })
    }
    params.set(key, value)
  }
  if (query.repository !== undefined) {
    if (typeof query.repository !== 'string' || !/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/.test(query.repository)) {
      throw createError({ statusCode: 400, statusMessage: 'Invalid repository name' })
    }
    params.set('repository', query.repository)
  }
  return intentApi<IntentPage>(event, '?' + params.toString())
})
