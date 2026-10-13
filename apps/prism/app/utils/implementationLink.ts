export function implementationLink(output: unknown): string | undefined {
  if (typeof output !== 'string') return
  try {
    const result = JSON.parse(output)
    if (typeof result?.requestId === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(result.requestId)) {
      return `/talos/${result.requestId.toLowerCase()}`
    }
  } catch {
    // Only structured implementation handoff results become navigation links.
  }
}
