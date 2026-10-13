// Keep browser requests same-origin; the API needs no CORS configuration.
export default defineEventHandler((event) => {
  const config = useRuntimeConfig(event)
  const path = getRequestURL(event).pathname.slice('/api'.length)
  if (!/^\/todos(?:\/[0-9]+)?$/.test(path)) {
    throw createError({ statusCode: 404, statusMessage: 'Route not found' })
  }
  return proxyRequest(event, new URL(path, config.todoApiUrl).toString())
})
