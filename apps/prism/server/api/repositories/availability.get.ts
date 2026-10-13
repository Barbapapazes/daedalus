export default defineEventHandler(async (event) => {
  const name = getQuery(event).name
  if (typeof name !== 'string' || !/^[a-z0-9][a-z0-9._-]{0,127}$/.test(name)) throw createError({ statusCode: 400, statusMessage: 'Invalid repository name' })
  setResponseHeader(event, 'Cache-Control', 'no-store')
  return repositoryApi<{ available: boolean }>(event, '/availability?name=' + encodeURIComponent(name))
})
