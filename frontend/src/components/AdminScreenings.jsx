import { useEffect, useRef, useState } from 'react'
import { adminRequest } from '../utils/adminRequest'

function parisDate(value) {
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('fr-FR', {
      timeZone: 'Europe/Paris',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(new Date(value))
      .map((part) => [part.type, part.value]),
  )
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
}

function dateLabel(value) {
  return new Date(value).toLocaleString('fr-FR', {
    timeZone: 'Europe/Paris',
    dateStyle: 'short',
    timeStyle: 'short',
  })
}

export default function AdminScreenings() {
  const [date, setDate] = useState(() => parisDate(new Date()).slice(0, 10))
  const [data, setData] = useState(null)
  const [form, setForm] = useState({ filmId: '', roomId: '', startsAt: '' })
  const [editing, setEditing] = useState(null)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [cancelId, setCancelId] = useState(null)
  const busy = useRef(false)
  const feedback = useRef(null)

  useEffect(() => {
    if (!error && !message) return
    feedback.current?.focus({ preventScroll: true })
    feedback.current?.scrollIntoView({ block: 'nearest' })
  }, [error, message])

  async function load(day) {
    setLoading(true)
    setError('')
    try {
      setData(
        await adminRequest(
          `/api/admin/screenings?${new URLSearchParams({ date: day })}`,
        ),
      )
    } catch (failure) {
      setError(failure.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const controller = new AbortController()
    const today = parisDate(new Date()).slice(0, 10)
    adminRequest(`/api/admin/screenings?date=${today}`, {
      signal: controller.signal,
    })
      .then(setData)
      .catch((failure) => {
        if (failure.name !== 'AbortError') setError(failure.message)
      })
    return () => controller.abort()
  }, [])

  function edit(session) {
    setEditing(session)
    setForm(
      session
        ? {
            filmId: session.filmId,
            roomId: session.roomId,
            startsAt: parisDate(session.startsAt),
          }
        : { filmId: '', roomId: '', startsAt: `${date}T14:00` },
    )
    setError('')
    setMessage('')
    setCancelId(null)
  }

  async function write(action, id, body) {
    if (busy.current) return
    busy.current = true
    setSaving(true)
    setError('')
    setMessage('')
    try {
      const { csrfToken } = await adminRequest('/api/csrf')
      const result = await adminRequest(
        `/api/admin/screenings${id ? `/${id}` : ''}${action ? `/${action}` : ''}`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken,
          },
          body: JSON.stringify(body || {}),
          signal: AbortSignal.timeout(60000),
        },
      )
      setMessage(
        result.message +
          (result.pendingEmails > 0
            ? ` ${result.pendingEmails} notification(s) restent à envoyer. Attendez une minute avant de relancer les mails.`
            : ''),
      )
      setCancelId(null)
      if (!action) {
        const day = form.startsAt.slice(0, 10)
        setDate(day)
        setEditing(null)
        setForm({ filmId: '', roomId: '', startsAt: '' })
        await load(day)
      } else await load(date)
    } catch (failure) {
      setError(
        failure.name === 'TimeoutError'
          ? 'La réponse tarde. Rechargez les séances pour vérifier le résultat avant de recommencer.'
          : failure.message,
      )
    } finally {
      setSaving(false)
      busy.current = false
    }
  }

  const film = data?.films.find((item) => item.id === Number(form.filmId))

  return (
    <section className="admin-section" aria-labelledby="screenings-title">
      <h2 id="screenings-title">Gérer les séances</h2>
      <form
        className="account-form"
        onSubmit={(event) => {
          event.preventDefault()
          load(date)
        }}
      >
        <label htmlFor="screening-day">Séances du</label>
        <input
          id="screening-day"
          type="date"
          required
          value={date}
          onChange={(event) => setDate(event.target.value)}
        />
        <button className="secondary-button" disabled={loading || saving}>
          {loading ? 'Chargement…' : 'Afficher les séances'}
        </button>
      </form>
      <p>
        Les horaires sont exprimés en heure de Paris. Une heure de marge est
        conservée entre deux séances dans la même salle.
      </p>
      {data && (
        <>
          <button
            className="secondary-button"
            disabled={saving}
            onClick={() => edit(null)}
          >
            Nouvelle séance
          </button>
          <form
            className="account-form"
            onSubmit={(event) => {
              event.preventDefault()
              write(null, editing?.id, {
                filmId: Number(form.filmId),
                roomId: Number(form.roomId),
                startsAt: form.startsAt,
              })
            }}
          >
            <fieldset className="film-fields" disabled={saving}>
              <legend>
                {editing ? 'Modifier la séance' : 'Créer une séance'}
              </legend>
              <label htmlFor="screening-film">Film de la séance</label>
              <select
                id="screening-film"
                required
                value={form.filmId}
                onChange={(event) =>
                  setForm({ ...form, filmId: event.target.value })
                }
              >
                <option value="">Choisir un film</option>
                {editing &&
                  !data.films.some((item) => item.id === editing.filmId) && (
                    <option value={editing.filmId}>
                      {editing.filmTitle} (retiré de l’affiche)
                    </option>
                  )}
                {data.films.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.title}
                  </option>
                ))}
              </select>
              {film && (
                <p>
                  Durée du film {film.durationMinutes} minutes. La fin est
                  calculée automatiquement.
                </p>
              )}
              <label htmlFor="screening-room">Salle de la séance</label>
              <select
                id="screening-room"
                required
                value={form.roomId}
                onChange={(event) =>
                  setForm({ ...form, roomId: event.target.value })
                }
              >
                <option value="">Choisir une salle</option>
                {data.rooms.map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.name}
                  </option>
                ))}
              </select>
              <label htmlFor="screening-start">Début de la séance</label>
              <input
                id="screening-start"
                type="datetime-local"
                required
                value={form.startsAt}
                onChange={(event) =>
                  setForm({ ...form, startsAt: event.target.value })
                }
              />
              {editing?.reservationCount > 0 && (
                <p>
                  Des réservations existent. Seul l’horaire peut changer, avec
                  un délai de 24 heures pour l’ancien et le nouvel horaire.
                </p>
              )}
              <button className="primary-button">
                {saving ? 'Enregistrement…' : 'Enregistrer la séance'}
              </button>
            </fieldset>
          </form>
          <div ref={feedback} tabIndex={-1}>
            {error && (
              <p className="field-error" role="alert">
                {error}
              </p>
            )}
            {message && (
              <p className="account-success" role="status">
                {message}
              </p>
            )}
          </div>
          {data.screenings.length === 0 && (
            <p>Aucune séance pour cette date.</p>
          )}
          {data.screenings.map((session) => (
            <article key={session.id} className="account-reservation">
              <h3>{session.filmTitle}</h3>
              <p>
                {dateLabel(session.startsAt)} · {session.room} · Fin{' '}
                {dateLabel(session.endsAt)}
              </p>
              <p>
                {session.status === 'cancelled'
                  ? 'Séance annulée'
                  : 'Séance programmée'}{' '}
                · {session.reservationCount} réservation(s)
              </p>
              {session.canEdit && (
                <div className="account-history-pages">
                  <button
                    className="secondary-button"
                    disabled={saving}
                    onClick={() => edit(session)}
                  >
                    Modifier la séance
                  </button>
                  <button
                    className="secondary-button"
                    disabled={saving}
                    onClick={() => setCancelId(session.id)}
                  >
                    Annuler la séance
                  </button>
                </div>
              )}
              {cancelId === session.id && (
                <div className="account-warning">
                  <p>
                    Annuler cette séance et toutes ses réservations ? Les sièges
                    seront libérés et les clients seront prévenus par mail.
                  </p>
                  <div className="account-history-pages">
                    <button
                      className="primary-button"
                      disabled={saving}
                      onClick={() => write('cancel', session.id)}
                    >
                      Confirmer l’annulation de la séance
                    </button>
                    <button
                      className="secondary-button"
                      disabled={saving}
                      onClick={() => setCancelId(null)}
                    >
                      Conserver la séance
                    </button>
                  </div>
                </div>
              )}
              {session.pendingEmails > 0 && (
                <>
                  <p>{session.pendingEmails} notification(s) en attente.</p>
                  <button
                    className="secondary-button"
                    disabled={saving}
                    onClick={() => write('retry-emails', session.id)}
                  >
                    Relancer les mails en attente
                  </button>
                </>
              )}
            </article>
          ))}
        </>
      )}
      {!data && error && (
        <p ref={feedback} tabIndex={-1} className="field-error" role="alert">
          {error}
        </p>
      )}
    </section>
  )
}
