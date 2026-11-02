import { cancellationMessage } from '../utils/cancellationMessage'
import { useEffect, useState } from 'react'
import { adminRequest, isStaff } from '../utils/adminRequest'
import AdminFilms from './AdminFilms'
import AdminScreenings from './AdminScreenings'

export default function AdminPage({ session }) {
  return (
    <main id="compte" className="account-page admin-page">
      <section
        className="account-panel admin-shell"
        aria-labelledby="admin-title"
      >
        <h1 id="admin-title">Administration du cinéma</h1>
        {session.loading ? (
          <p role="status">Vérification des accès…</p>
        ) : !session.user ? (
          <>
            <p>Connectez-vous avec votre compte employé ou direction.</p>
            <a href="/connexion?retour=%2Fadmin">Se connecter</a>
          </>
        ) : !isStaff(session.user) ? (
          <p role="alert">Vous n’avez pas accès à l’administration.</p>
        ) : (
          <AdminDashboard key={session.user.id} user={session.user} />
        )}
      </section>
    </main>
  )
}

function AdminDashboard({ user }) {
  const director = user.roles.includes('ROLE_DIRECTOR')
  const [price, setPrice] = useState('')
  const [settings, setSettings] = useState(null)
  const [priceError, setPriceError] = useState('')
  const [priceMessage, setPriceMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [query, setQuery] = useState('')
  const [searchedQuery, setSearchedQuery] = useState('')
  const [result, setResult] = useState(null)
  const [searchError, setSearchError] = useState('')
  const [searching, setSearching] = useState(false)

  useEffect(() => {
    const controller = new AbortController()
    adminRequest('/api/admin/settings', { signal: controller.signal })
      .then((data) => {
        setSettings(data)
        setPrice((data.ticketPriceCents / 100).toFixed(2))
      })
      .catch((failure) => {
        if (failure.name !== 'AbortError') setPriceError(failure.message)
      })
    return () => controller.abort()
  }, [])

  async function savePrice(event) {
    event.preventDefault()
    if (saving) return
    setSaving(true)
    setPriceError('')
    setPriceMessage('')
    try {
      const { csrfToken } = await adminRequest('/api/csrf')
      const data = await adminRequest('/api/admin/settings/tariff', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken,
        },
        body: JSON.stringify({ price }),
      })
      setSettings((current) => ({
        ...current,
        ticketPriceCents: data.ticketPriceCents,
      }))
      setPrice((data.ticketPriceCents / 100).toFixed(2))
      setPriceMessage(data.message)
    } catch (failure) {
      setPriceError(failure.message)
    } finally {
      setSaving(false)
    }
  }

  async function search(value, page = 1) {
    setSearching(true)
    setSearchError('')
    setResult(null)
    setSearchedQuery(value)
    try {
      setResult(
        await adminRequest(
          `/api/admin/reservations?${new URLSearchParams({ q: value, page })}`,
        ),
      )
    } catch (failure) {
      setSearchError(failure.message)
    } finally {
      setSearching(false)
    }
  }

  return (
    <>
      <p>
        Bonjour {user.firstName}. Espace {director ? 'direction' : 'employé'}.
      </p>
      <nav
        className="admin-navigation"
        aria-label="Rubriques de l’administration"
      >
        <a href="#search-title">Réservations</a>
        <a href="#films-title">Films</a>
        <a href="#screenings-title">Séances</a>
        <a href="#price-title">Tarif</a>
        {director && <a href="#statistics-title">Statistiques</a>}
      </nav>
      <section className="admin-section" aria-labelledby="search-title">
        <h2 id="search-title">Retrouver une réservation</h2>
        <form
          className="account-form"
          onSubmit={(event) => {
            event.preventDefault()
            search(query.trim())
          }}
        >
          <label htmlFor="reservation-search">
            Numéro de réservation ou adresse mail
          </label>
          <input
            id="reservation-search"
            required
            maxLength={180}
            value={query}
            onChange={(event) => setQuery(event.target.value)}
          />
          <button className="primary-button" disabled={searching}>
            {searching ? 'Recherche…' : 'Rechercher'}
          </button>
        </form>
        {searchError && (
          <p role="alert" className="field-error">
            {searchError}
          </p>
        )}
        {result && (
          <>
            {result.reservations.length === 0 && (
              <p>Aucune réservation trouvée.</p>
            )}
            {result.reservations.map((booking) => (
              <article className="account-reservation" key={booking.reference}>
                <h3>{booking.filmTitle}</h3>
                <p
                  className={
                    booking.status === 'confirmed'
                      ? 'account-success'
                      : 'account-warning'
                  }
                >
                  {booking.status === 'confirmed'
                    ? 'Réservation confirmée'
                    : cancellationMessage(booking.cancellationOrigin, true)}
                </p>
                <p>Référence {booking.reference}</p>
                <p>
                  {new Date(booking.startsAt).toLocaleString('fr-FR', {
                    timeZone: 'Europe/Paris',
                  })}{' '}
                  · {booking.room}
                </p>
                <p>
                  {booking.firstName} {booking.lastName}
                  <br />
                  {booking.email}
                </p>
                <p>Sièges {booking.seats.join(', ')}</p>
                <p>
                  Total à régler au cinéma{' '}
                  {(booking.totalAmountCents / 100).toLocaleString('fr-FR', {
                    style: 'currency',
                    currency: 'EUR',
                  })}
                </p>
                
              </article>
            ))}
            {(result.page > 1 || result.hasMore) && (
              <div className="account-history-pages">
                <button
                  disabled={searching || result.page <= 1}
                  onClick={() => search(searchedQuery, result.page - 1)}
                >
                  Page précédente
                </button>
                <span>Page {result.page}</span>
                <button
                  disabled={searching || !result.hasMore}
                  onClick={() => search(searchedQuery, result.page + 1)}
                >
                  Page suivante
                </button>
              </div>
            )}
          </>
        )}
      </section>
      <AdminFilms />
      <AdminScreenings />
      <section className="admin-section" aria-labelledby="price-title">
        <h2 id="price-title">Tarif unique</h2>
        {settings && (
          <p>
            Tarif actuel{' '}
            {(settings.ticketPriceCents / 100).toLocaleString('fr-FR', {
              style: 'currency',
              currency: 'EUR',
            })}{' '}
            par place.
          </p>
        )}
        {director && settings && (
          <form onSubmit={savePrice} className="account-form">
            <label htmlFor="ticket-price">Nouveau tarif en euros</label>
            <input
              id="ticket-price"
              inputMode="decimal"
              required
              value={price}
              onChange={(event) => setPrice(event.target.value)}
            />
            <p>
              Le nouveau tarif s’applique aux nouvelles réservations. Les
              montants déjà confirmés sont conservés.
            </p>
            <button className="secondary-button" disabled={saving}>
              {saving ? 'Enregistrement…' : 'Enregistrer le tarif'}
            </button>
          </form>
        )}
        {priceError && (
          <p role="alert" className="field-error">
            {priceError}
          </p>
        )}
        {priceMessage && (
          <p role="status" className="account-success">
            {priceMessage}
          </p>
        )}
      </section>
      
    </>
  )
}
