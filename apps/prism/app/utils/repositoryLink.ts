export function repositoryLink(output: unknown): string | undefined {
  if (typeof output !== 'string') return
  try {
    const result = JSON.parse(output)
    const repository = result?.repository
    if (
      (repository?.status === 'ready' || repository?.status === 'provisioning')
      && typeof repository.id === 'string'
      && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(repository.id)
    ) {
      return `/repositories/${repository.id.toLowerCase()}`
    }
  } catch {
    // Only confirmed repository creation results become navigation links.
  }
}
