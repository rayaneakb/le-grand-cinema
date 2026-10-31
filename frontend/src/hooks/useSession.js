import { useEffect, useState } from 'react'

export default function useSession() {
  const [session, setSession] = useState({ loading: true, user: null })

  useEffect(() => {
    const controller = new AbortController()

    async function loadSession() {
      try {
        const response = await fetch('/api/session', {
          signal: controller.signal,
        })
        if (!response.ok) throw new Error('Session indisponible')
        const data = await response.json()
        setSession({ ...data, loading: false })
      } catch (failure) {
        if (failure.name !== 'AbortError')
          setSession({ loading: false, user: null })
      }
    }

    loadSession()
    window.addEventListener('focus', loadSession)
    return () => {
      controller.abort()
      window.removeEventListener('focus', loadSession)
    }
  }, [])

  useEffect(() => {
    if (!session.user || !session.csrfToken) return
    const controller = new AbortController()
    let activity = false
    let expiresAt = session.expiresAt
      ? session.expiresAt * 1000
      : Date.now() + 600000
    const recordActivity = () => {
      activity = true
      expiresAt = Date.now() + 600000
    }
    const events = ['pointerdown', 'keydown', 'scroll']
    events.forEach((event) =>
      window.addEventListener(event, recordActivity, { passive: true }),
    )

    const timer = setInterval(async () => {
      if (Date.now() >= expiresAt) {
        setSession({ loading: false, user: null })
        return
      }
      if (!activity) return
      activity = false
      try {
        const response = await fetch('/api/activity', {
          method: 'POST',
          headers: { 'X-CSRF-Token': session.csrfToken },
          signal: controller.signal,
        })
        if (response.status === 401) setSession({ loading: false, user: null })
      } catch {
        // Une panne réseau ne doit pas provoquer d’envoi en boucle.
      }
    }, 60000)

    return () => {
      controller.abort()
      clearInterval(timer)
      events.forEach((event) =>
        window.removeEventListener(event, recordActivity),
      )
    }
  }, [session.user, session.csrfToken, session.expiresAt])

  return session
}
