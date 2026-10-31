import { useEffect, useRef, useState } from 'react'
import ResendVerificationForm from './ResendVerificationForm'

export default function LoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [csrf, setCsrf] = useState('')
  const [error, setError] = useState('')
  const [unverified, setUnverified] = useState(false)
  const [busy, setBusy] = useState(false)
  const sending = useRef(false)

  useEffect(() => {
    const controller = new AbortController()
    async function loadCsrf() {
      try {
        const response = await fetch('/api/csrf', { signal: controller.signal })
        const data = await response.json()
        if (!response.ok || typeof data.csrfToken !== 'string')
          throw new Error('Formulaire indisponible')
        setCsrf(data.csrfToken)
      } catch (failure) {
        if (failure.name !== 'AbortError')
          setError(
            'Le formulaire est indisponible. Rechargez la page pour réessayer.',
          )
      }
    }
    loadCsrf()
    return () => controller.abort()
  }, [])

  async function login(event) {
    event.preventDefault()
    if (sending.current || !csrf) return
    sending.current = true
    setBusy(true)
    setError('')
    setUnverified(false)

    try {
      const response = await fetch('/api/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ email, password }),
      })
      const data = await response.json()
      if (!response.ok) {
        setError(data.message || 'La connexion a échoué. Réessayez.')
        setUnverified(data.status === 'email_not_verified')
        return
      }
      if (data.status !== 'authenticated') throw new Error('Réponse incorrecte')
      window.location.assign('/compte')
    } catch {
      setError('Le service est indisponible. Réessayez plus tard.')
    } finally {
      sending.current = false
      setBusy(false)
    }
  }

  return (
    <main id="compte" className="account-page">
      <section className="account-panel" aria-labelledby="login-title">
        <p className="eyebrow">Votre espace cinéma</p>
        <h1 id="login-title">Se connecter</h1>
        <p>Connectez-vous avec votre adresse mail et votre mot de passe.</p>
        <form className="account-form" onSubmit={login} aria-label="Connexion">
          <label htmlFor="login-email">Adresse mail</label>
          <input
            id="login-email"
            type="email"
            autoComplete="username"
            required
            maxLength={180}
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
          <label htmlFor="login-password">Mot de passe</label>
          <input
            id="login-password"
            type="password"
            autoComplete="current-password"
            required
            maxLength={128}
            value={password}
            onChange={(event) => setPassword(event.target.value)}
          />
          {error && (
            <p role="alert" className="field-error">
              {error}
            </p>
          )}
          <button className="primary-button" disabled={busy || !csrf}>
            {busy ? 'Connexion en cours…' : 'Me connecter'}
          </button>
        </form>
        {unverified && <ResendVerificationForm initialEmail={email} />}
        <a className="account-back" href="/mot-de-passe-oublie">
          Mot de passe oublié ?
        </a>
        <br />
        <a className="account-back" href="/inscription">
          Créer un compte
        </a>
      </section>
    </main>
  )
}
