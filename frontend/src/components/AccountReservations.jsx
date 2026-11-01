import { cancellationMessage } from '../utils/cancellationMessage'
import { useEffect, useState } from 'react'

export default function AccountReservations() {
  const [bookings, setBookings] = useState([])
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    async function load() {
      try {
        const response = await fetch(`/api/account/reservations?page=${page}`, {
          signal: controller.signal,
        })
        const data = await response.json()
        if (!response.ok)
          throw new Error(
            data.message || 'Vos réservations sont indisponibles. Réessayez.',
          )
        if (!Array.isArray(data.reservations))
          throw new Error('Vos réservations sont indisponibles. Réessayez.')
        setBookings(data.reservations)
        setHasMore(data.hasMore === true)
        setError('')
      } catch (failure) {
        if (failure.name !== 'AbortError')
          setError('Impossible de charger vos réservations. Réessayez.')
      } finally {
        if (!controller.signal.aborted) setLoading(false)
      }
    }
    load()
    return () => controller.abort()
  }, [page, retry])

  function changePage(nextPage) {
    setLoading(true)
    setError('')
    setBookings([])
    setPage(nextPage)
  }

  return (
    <section
      className="account-reservations"
      aria-labelledby="reservations-title"
    >
      <h2 id="reservations-title">Mes réservations</h2>
      {loading ? (
        <p role="status">Chargement de vos réservations…</p>
      ) : error ? (
        <>
          <p role="alert" className="field-error">
            {error}
          </p>
          <button
            className="secondary-button"
            onClick={() => {
              setLoading(true)
              setRetry((value) => value + 1)
            }}
          >
            Réessayer
          </button>
        </>
      ) : (
        <>
          {bookings.length === 0 && (
            <p>Vous n’avez pas encore de réservation.</p>
          )}
          {bookings.map((booking) => (
            <article className="account-reservation" key={booking.reference}>
              <h3>{booking.filmTitle}</h3>
              <p>
                {new Date(booking.startsAt).toLocaleString('fr-FR', {
                  dateStyle: 'long',
                  timeStyle: 'short',
                  timeZone: 'Europe/Paris',
                })}{' '}
                · {booking.room}
              </p>
              <p>
                Référence {booking.reference} ·{' '}
                {booking.status === 'cancelled' ? 'Annulée' : 'Confirmée'}
              </p>
              {booking.status === 'cancelled' && (
                <p>{cancellationMessage(booking.cancellationOrigin)}</p>
              )}
              <p>Sièges {booking.seats.join(', ')}</p>
              <a href={`/reservations/${booking.reference}`}>
                Voir ma réservation
              </a>
            </article>
          ))}
        </>
      )}
      {(page > 1 || hasMore) && (
        <div
          className="account-history-pages"
          aria-label="Pages des réservations"
        >
          <button
            disabled={loading || page <= 1}
            onClick={() => changePage(page - 1)}
          >
            Page précédente
          </button>
          <span>Page {page}</span>
          <button
            disabled={loading || !hasMore}
            onClick={() => changePage(page + 1)}
          >
            Page suivante
          </button>
        </div>
      )}
    </section>
  )
}
