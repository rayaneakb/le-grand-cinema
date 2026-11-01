export async function adminRequest(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    headers: { Accept: 'application/json', ...options.headers },
    signal: options.signal || AbortSignal.timeout(20000),
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new Error(
      response.status === 401
        ? 'Votre session a expiré. Reconnectez-vous.'
        : response.status === 403
          ? 'Vous n’avez pas les droits pour cette action.'
          : data.message || 'Le service est indisponible. Réessayez.',
    )
  }
  return data
}

export function isStaff(user) {
  return (
    user?.roles?.some((role) =>
      ['ROLE_EMPLOYEE', 'ROLE_DIRECTOR'].includes(role),
    ) === true
  )
}
