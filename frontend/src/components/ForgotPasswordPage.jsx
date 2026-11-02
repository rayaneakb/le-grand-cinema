import { useRef, useState } from 'react'

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const sending = useRef(false)

  async function requestLink(event) {
    event.preventDefault()
    if (sending.current) return
    sending.current = true
    setBusy(true)
    setMessage('')
    setError('')
    try {
      const response = await fetch('/api/forgot-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email }),
      })
      const data = await response.json()
      if (!response.ok) {
        setError(
          data.message || 'La demande n’a pas pu être envoyée. Réessayez.',
        )
        return
      }
      setMessage(data.message)
    } catch {
      setError('Le service est indisponible. Réessayez plus tard.')
    } finally {
      sending.current = false
      setBusy(false)
    }
  }

  return (
    <main id="compte" className="account-page">
      <section className="account-panel" aria-labelledby="forgot-title">
        <p className="eyebrow">Votre espace cinéma</p>
        <h1 id="forgot-title">Mot de passe oublié</h1>
        <p>
          Indiquez l’adresse mail de votre compte pour recevoir un lien de
          réinitialisation.
        </p>
        <form className="account-form" onSubmit={requestLink}>
          <label htmlFor="forgot-email">Adresse mail</label>
          <input
            id="forgot-email"
            type="email"
            autoComplete="email"
            required
            maxLength={180}
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
          <button className="primary-button" disabled={busy}>
            {busy ? 'Envoi en cours…' : 'Recevoir un lien'}
          </button>
        </form>
        {message && (
          <p role="status" className="account-success">
            {message}
          </p>
        )}
        {error && (
          <p role="alert" className="field-error">
            {error}
          </p>
        )}
        <a className="account-back" href="/connexion">
          Revenir à la connexion
        </a>
      </section>
    </main>
  )
}
