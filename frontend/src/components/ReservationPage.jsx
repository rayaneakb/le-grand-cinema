import { cancellationMessage } from '../utils/cancellationMessage'
import ReservationPdfButton from './ReservationPdfButton'
import { useEffect, useState } from 'react'

export default function ReservationPage({ reference }) {
  const [query] = useState(() => window.location.search)
  const [booking, setBooking] = useState(null)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [asking, setAsking] = useState(false)
  const [busy, setBusy] = useState(false)
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    async function load() {
      try {
        const response = await fetch(`/api/reservations/${reference}${query}`, {
          signal: controller.signal,
        })
        const result = await response.json()
        if (!response.ok)
          throw new Error(
            result.message || 'Cette réservation est indisponible.',
          )
        setBooking(result.reservation)
        setError('')
      } catch (failure) {
        if (failure.name !== 'AbortError') {
          setError(
            failure instanceof TypeError
              ? 'Impossible de charger la réservation. Réessayez.'
              : failure.message,
          )
        }
      }
    }
    load()
    return () => controller.abort()
  }, [reference, query, retry])

  async function cancel() {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      const csrfResponse = await fetch('/api/csrf')
      if (!csrfResponse.ok)
        throw new Error('Impossible de préparer l’annulation. Réessayez.')
      const { csrfToken } = await csrfResponse.json()
      const response = await fetch(
        `/api/reservations/${reference}/cancel${query}`,
        {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrfToken },
        },
      )
      const result = await response.json()
      if (!response.ok) {
        if (response.status === 409)
          setBooking((current) => ({ ...current, canCancel: false }))
        throw new Error(
          result.message || 'L’annulation est indisponible. Réessayez.',
        )
      }
      setBooking(result.reservation)
      setMessage(result.message)
      setAsking(false)
    } catch (failure) {
      setError(
        failure instanceof TypeError
          ? 'La réponse n’a pas pu être reçue. Réessayez pour vérifier le statut de votre réservation.'
          : failure.message,
      )
    } finally {
      setBusy(false)
    }
  }

  const formatDate = (value) =>
    new Date(value).toLocaleString('fr-FR', {
      dateStyle: 'long',
      timeStyle: 'short',
      timeZone: 'Europe/Paris',
    })

  return (
    <main id="compte" className="account-page">
      <section className="account-panel" aria-labelledby="reservation-title">
        <h1 id="reservation-title">Ma réservation</h1>
        {!booking && !error && (
          <p role="status">Chargement de votre réservation…</p>
        )}
        {error && (
          <p className="field-error" role="alert">
            {error}
          </p>
        )}
        {!booking && error && (
          <button onClick={() => setRetry((value) => value + 1)}>
            Réessayer
          </button>
        )}
        {booking && (
          <>
            <p
              className={
                booking.status === 'cancelled'
                  ? 'account-warning'
                  : 'account-success'
              }
              role="status"
            >
              {booking.status === 'cancelled' && booking.cancellationOrigin
                ? cancellationMessage(booking.cancellationOrigin)
                : message ||
                  (booking.status === 'cancelled'
                    ? cancellationMessage(null)
                    : 'Votre réservation est confirmée.')}
            </p>
            <dl className="account-details">
              <dt>Numéro de réservation</dt>
              <dd>{booking.reference}</dd>
              <dt>Film</dt>
              <dd>{booking.filmTitle}</dd>
              <dt>Séance</dt>
              <dd>
                {formatDate(booking.startsAt)} · {booking.room}
              </dd>
              <dt>Sièges</dt>
              <dd>{booking.seats.join(', ')}</dd>
              <dt>Coordonnées</dt>
              <dd>
                {booking.firstName} {booking.lastName}
                <br />
                {booking.email}
              </dd>
              <dt>Total à régler au cinéma</dt>
              <dd>
                {(booking.totalAmountCents / 100).toLocaleString('fr-FR', {
                  style: 'currency',
                  currency: 'EUR',
                })}
              </dd>
            </dl>
            {booking.status === 'confirmed' ? (
              <ReservationPdfButton reference={reference} query={query} />
            ) : (
              <p>
                Le téléchargement du PDF n’est plus disponible pour cette
                réservation annulée.
              </p>
            )}
            {booking.status === 'confirmed' && (
              <>
                <p>
                  Annulation possible jusqu’au{' '}
                  {formatDate(booking.cancellationDeadline)}.
                </p>
                {booking.canCancel ? (
                  asking ? (
                    <div className="account-warning">
                      <p>
                        Confirmez-vous l’annulation de votre réservation ? Tous
                        vos sièges seront libérés.
                      </p>
                      <button
                        className="primary-button"
                        disabled={busy}
                        onClick={cancel}
                      >
                        {busy
                          ? 'Annulation en cours…'
                          : 'Confirmer l’annulation'}
                      </button>
                      <button
                        className="secondary-button account-back"
                        disabled={busy}
                        onClick={() => setAsking(false)}
                      >
                        Conserver ma réservation
                      </button>
                    </div>
                  ) : (
                    <button
                      className="secondary-button"
                      onClick={() => setAsking(true)}
                    >
                      Annuler ma réservation
                    </button>
                  )
                ) : (
                  <p>
                    Le délai est dépassé. Vous ne pouvez plus annuler votre
                    réservation.
                  </p>
                )}
              </>
            )}
          </>
        )}
        <a className="account-back" href="/">
          Revenir à la programmation
        </a>
      </section>
    </main>
  )
}
