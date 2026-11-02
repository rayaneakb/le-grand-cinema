import { useState } from 'react'
import { adminRequest } from '../utils/adminRequest'
import Modal from './Modal'

export default function ReservationEmailCorrection({ booking, onUpdated }) {
  const [open, setOpen] = useState(false)
  const [email, setEmail] = useState('')
  const [firstName, setFirstName] = useState('')
  const [lastName, setLastName] = useState('')
  const [verified, setVerified] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  async function save(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      const { csrfToken } = await adminRequest('/api/csrf')
      const data = await adminRequest(`/api/admin/reservations/${booking.reference}/email`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ email, expectedEmail: booking.email, firstName, lastName, requesterVerified: verified }),
      })
      onUpdated(data.reservation)
      setMessage(data.message)
      setOpen(false)
    } catch (failure) {
      setError(failure.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      {booking.status === 'confirmed' && (
        <button className="secondary-button" onClick={() => { setOpen(true); setError(''); setMessage('') }}>
          Corriger l’adresse mail
        </button>
      )}
      {message && <p role="status" className="account-success">{message}</p>}
      {open && (
        <Modal title="Corriger l’adresse mail de réservation" busy={busy} onClose={() => setOpen(false)}>
          <p>Demandez au client son nom et son prénom sans les lui dicter. La référence seule ne suffit pas.</p>
          <p>L’ancien lien sera invalidé. Le compte, les sièges et le prix seront conservés.</p>
          <form className="account-form" onSubmit={save}>
            <label htmlFor="requester-first-name">Prénom donné par le demandeur</label>
            <input id="requester-first-name" required maxLength={100} value={firstName} onChange={(event) => setFirstName(event.target.value)} />
            <label htmlFor="requester-last-name">Nom donné par le demandeur</label>
            <input id="requester-last-name" required maxLength={100} value={lastName} onChange={(event) => setLastName(event.target.value)} />
            <label htmlFor="corrected-email">Nouvelle adresse mail</label>
            <input id="corrected-email" type="email" required maxLength={180} value={email} onChange={(event) => setEmail(event.target.value)} />
            <label className="checkbox-label">
              <input type="checkbox" required checked={verified} onChange={(event) => setVerified(event.target.checked)} />
              J’ai vérifié le demandeur et confirmé sa nouvelle adresse avec lui.
            </label>
            <button className="primary-button" disabled={busy}>
              {busy ? 'Enregistrement…' : 'Corriger et renvoyer la confirmation'}
            </button>
            {error && <p role="alert" className="field-error">{error}</p>}
          </form>
        </Modal>
      )}
    </>
  )
}
