export default defineEventHandler(async (event) => {
  const id = getRouterParam(event, 'id')
  if (!id || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id)) {
    throw createError({ statusCode: 400, statusMessage: 'Invalid implementation request ID' })
  }

  const config = useRuntimeConfig(event)
  const url = new URL(`/internal/implementations/${id.toLowerCase()}`, config.talosUrl)
  const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)
  if (!local && (url.protocol !== 'https:' || !config.talosAccessClientId || !config.talosAccessClientSecret)) {
    throw createError({ statusCode: 503, statusMessage: 'Talos observation is not configured' })
  }

  // Credentials remain on the server, never in public runtime config or model tools.
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (config.talosAccessClientId && config.talosAccessClientSecret) {
    headers['CF-Access-Client-ID'] = config.talosAccessClientId
    headers['CF-Access-Client-Secret'] = config.talosAccessClientSecret
  }
  setResponseHeader(event, 'Cache-Control', 'no-store')
  try {
    return await $fetch(url.toString(), { headers, redirect: 'manual', timeout: 15_000, retry: 0 })
  } catch (cause) {
    const status = (cause as { response?: { status?: number } }).response?.status
    throw createError({
      statusCode: status === 404 ? 404 : 502,
      statusMessage: status === 404 ? 'Implementation not found or not yet dispatched' : 'Could not load Talos implementation',
    })
  }
})
