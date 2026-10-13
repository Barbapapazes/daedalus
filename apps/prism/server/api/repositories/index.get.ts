import type { RepositoryPage } from '../../../shared/types/repository'

export default defineEventHandler(async (event) => {
  const page = Number(getQuery(event).page ?? 1)
  if (!Number.isSafeInteger(page) || page < 1) throw createError({ statusCode: 400, statusMessage: 'Invalid page' })
  setResponseHeader(event, 'Cache-Control', 'no-store')
  return repositoryApi<RepositoryPage>(event, '?limit=50&page=' + page)
})
