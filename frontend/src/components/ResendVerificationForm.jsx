import { useRef, useState } from 'react'

export default function ResendVerificationForm({ initialEmail = '' }) {
  const [email, setEmail] = useState(initialEmail)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const sending = useRef(false)

  async function resend(event) {
    event.preventDefault()
    if (sending.current) return
    sending.current = true
    setBusy(true)
    setMessage('')
    setError('')

    try {
      const response = await fetch('/api/resend-verification', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email }),
      })
      const data = await response.json()
      if (!response.ok) {
        setError(
          data.message ||
            'Le mail n’a pas pu être envoyé. Réessayez plus tard.',
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
    <form
      className="account-form resend-form"
      onSubmit={resend}
      aria-label="Renvoyer le mail d’activation"
    >
      <h2>Recevoir un nouveau lien</h2>
      <label htmlFor="resend-email">Adresse mail</label>
      <input
        id="resend-email"
        type="email"
        autoComplete="email"
        required
        maxLength={180}
        value={email}
        onChange={(event) => setEmail(event.target.value)}
      />
      <button className="primary-button" disabled={busy}>
        {busy ? 'Envoi en cours…' : 'Renvoyer le mail'}
      </button>
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
    </form>
  )
}
