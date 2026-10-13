import type { RepositoryRecord } from '../../../shared/types/repository'

export default defineEventHandler(async (event) => {
  const id = getRouterParam(event, 'id')
  if (!id || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) throw createError({ statusCode: 400, statusMessage: 'Invalid repository ID' })
  setResponseHeader(event, 'Cache-Control', 'no-store')
  return repositoryApi<RepositoryRecord>(event, '/' + id.toLowerCase())
})
