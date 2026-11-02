import { useState } from 'react'
import { adminRequest } from '../utils/adminRequest'

export default function AdminStatistics() {
  const [today] = useState(() =>
    new Date().toLocaleDateString('en-CA', {
      timeZone: 'Europe/Paris',
    }),
  )
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function load(event) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setResult(null)
    try {
      setResult(
        await adminRequest(
          `/api/admin/statistics?${new URLSearchParams({ from, to })}`,
        ),
      )
    } catch (failure) {
      setError(failure.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="admin-section" aria-labelledby="statistics-title">
      <h2 id="statistics-title">Statistiques des réservations en ligne</h2>
      <form onSubmit={load} className="account-form">
        <label htmlFor="stats-from">Date de séance du</label>
        <input
          id="stats-from"
          type="date"
          required
          value={from}
          onChange={(event) => setFrom(event.target.value)}
        />
        <label htmlFor="stats-to">Au</label>
        <input
          id="stats-to"
          type="date"
          required
          value={to}
          onChange={(event) => setTo(event.target.value)}
        />
        <button className="secondary-button" disabled={busy}>
          {busy ? 'Chargement…' : 'Afficher les statistiques'}
        </button>
      </form>
      {error && (
        <p role="alert" className="field-error">
          {error}
        </p>
      )}
      {result && (
        <>
          <dl className="account-details">
            <dt>Réservations confirmées</dt>
            <dd>{result.totals.confirmedReservations}</dd>
            <dt>Réservations annulées</dt>
            <dd>{result.totals.cancelledReservations}</dd>
            <dt>Sièges réservés</dt>
            <dd>{result.totals.bookedSeats}</dd>
            <dt>Taux d’occupation en ligne</dt>
            <dd>
              {result.totals.occupancyRate === null
                ? 'Aucune donnée disponible'
                : `${result.totals.occupancyRate.toLocaleString('fr-FR')} %`}
            </dd>
          </dl>
          {result.screenings.length === 0 && (
            <p>Aucune séance sur cette période.</p>
          )}
          {result.screenings.map((screening) => (
            <article className="account-reservation" key={screening.id}>
              <h3>{screening.filmTitle}</h3>
              <p>
                {new Date(screening.startsAt).toLocaleString('fr-FR', {
                  timeZone: 'Europe/Paris',
                })}{' '}
                · {screening.room}
              </p>
              <p>
                {screening.bookedSeats} sièges réservés sur {screening.capacity}{' '}
                ·{' '}
                {screening.occupancyRate === null
                  ? 'Taux indisponible'
                  : `${screening.occupancyRate.toLocaleString('fr-FR')} %`}
              </p>
              <p>
                {screening.confirmedReservations} réservations confirmées ·{' '}
                {screening.cancelledReservations} annulées
              </p>
            </article>
          ))}
        </>
      )}
    </section>
  )
}
