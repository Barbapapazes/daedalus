import type { H3Event } from 'h3'

export async function intentApi<T>(event: H3Event, path: string): Promise<T> {
  const config = useRuntimeConfig(event)
  const url = new URL('/internal/implementation-requests' + path, config.apiUrl)
  const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
  if (!local && (url.protocol !== 'https:' || !config.apiAccessClientId || !config.apiAccessClientSecret)) {
    throw createError({ statusCode: 503, statusMessage: 'Intent API is not configured' })
  }
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (config.apiAccessClientId && config.apiAccessClientSecret) {
    headers['CF-Access-Client-ID'] = config.apiAccessClientId
    headers['CF-Access-Client-Secret'] = config.apiAccessClientSecret
  }
  setResponseHeader(event, 'Cache-Control', 'no-store')
  try {
    return await $fetch(url.toString(), { headers, redirect: 'manual', timeout: 15000, retry: 0 }) as T
  } catch (cause) {
    const status = (cause as { response?: { status?: number } }).response?.status
    throw createError({
      statusCode: status === 404 ? 404 : status === 422 ? 422 : 502,
      statusMessage: status === 404 ? 'Intent not found' : 'Could not load intents',
    })
  }
}
