import { useRef, useState } from 'react'

export default function useLogout(token) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const sending = useRef(false)

  async function logout() {
    if (sending.current) return
    sending.current = true
    setBusy(true)
    setError('')

    try {
      const response = await fetch('/api/logout', {
        method: 'POST',
        body: new URLSearchParams({ _csrf_token: token }),
      })
      if (!response.ok) throw new Error('Déconnexion impossible')
      window.location.assign('/connexion')
    } catch {
      setError('La déconnexion n’a pas pu être confirmée. Réessayez.')
      sending.current = false
      setBusy(false)
    }
  }

  return { logout, busy, error }
}
