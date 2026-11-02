import { useRef, useState } from 'react'

export default function ReservationLinkPage() {
  const [email, setEmail] = useState('')
  const [reference, setReference] = useState('')
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
      const response = await fetch('/api/reservation-link', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, reference }),
        signal: AbortSignal.timeout(20000),
      })
      const data = await response.json()
      if (response.ok) setMessage(data.message)
      else setError(data.message || 'La demande a échoué. Réessayez.')
    } catch {
      setError('Le service est indisponible. Réessayez plus tard.')
    } finally {
      sending.current = false
      setBusy(false)
    }
  }

  return (
    <main id="compte" className="account-page">
      <section className="account-panel" aria-labelledby="reservation-link-title">
        <h1 id="reservation-link-title">Retrouver ma réservation</h1>
        <p>Recevez votre lien personnel à l’adresse mail de votre réservation, même sans compte.</p>
        <form className="account-form" onSubmit={requestLink}>
          <label htmlFor="link-reference">Numéro de réservation</label>
          <input id="link-reference" required maxLength={16} value={reference}
            onChange={(event) => setReference(event.target.value)} />
          <label htmlFor="link-email">Adresse mail de la réservation</label>
          <input id="link-email" type="email" autoComplete="email" required maxLength={180}
            value={email} onChange={(event) => setEmail(event.target.value)} />
          <button className="primary-button" disabled={busy}>
            {busy ? 'Envoi en cours…' : 'Recevoir mon lien'}
          </button>
        </form>
        {message && <p role="status" className="account-success">{message}</p>}
        {error && <p role="alert" className="field-error">{error}</p>}
        <a className="account-back" href="/">Revenir à la programmation</a>
      </section>
    </main>
  )
}
