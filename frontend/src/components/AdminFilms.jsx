import { useEffect, useRef, useState } from 'react'
import { adminRequest } from '../utils/adminRequest'

const emptyFilm = {
  title: '',
  synopsis: '',
  durationMinutes: '',
  mainActors: '',
  isShowing: true,
}

export default function AdminFilms() {
  const [films, setFilms] = useState([])
  const [film, setFilm] = useState(emptyFilm)
  const [poster, setPoster] = useState(null)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)
  const fileInput = useRef(null)
  const busy = useRef(false)

  async function load() {
    setLoading(true)
    setError('')
    try {
      const data = await adminRequest('/api/admin/films')
      setFilms(data.films)
    } catch (failure) {
      setError(failure.message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const controller = new AbortController()
    adminRequest('/api/admin/films', { signal: controller.signal })
      .then((data) => setFilms(data.films))
      .catch((failure) => {
        if (failure.name !== 'AbortError') setError(failure.message)
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false)
      })
    return () => controller.abort()
  }, [])

  function edit(value) {
    setFilm(value)
    setPoster(null)
    fileInput.current.value = ''
    setError('')
    setMessage('')
  }

  function choosePoster(event) {
    const file = event.target.files[0]
    setError('')
    setPoster(null)
    if (!file) return
    if (
      file.size > 5 * 1024 * 1024 ||
      !['image/jpeg', 'image/png', 'image/webp'].includes(file.type)
    ) {
      event.target.value = ''
      setError('Choisissez une image JPEG, PNG ou WebP de 5 Mo maximum.')
      return
    }
    setPoster(file)
  }

  async function save(event) {
    event.preventDefault()
    if (busy.current) return
    busy.current = true
    setSaving(true)
    setError('')
    setMessage('')
    try {
      const { csrfToken } = await adminRequest('/api/csrf')
      const body = new FormData()
      for (const field of [
        'title',
        'synopsis',
        'durationMinutes',
        'mainActors',
      ])
        body.set(field, film[field])
      body.set('isShowing', film.isShowing ? '1' : '0')
      if (poster) body.set('poster', poster)
      const data = await adminRequest(
        `/api/admin/films${film.id ? `/${film.id}` : ''}`,
        {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrfToken },
          body,
          signal: AbortSignal.timeout(60000),
        },
      )
      setFilms((current) =>
        [...current.filter((item) => item.id !== data.film.id), data.film].sort(
          (a, b) => a.title.localeCompare(b.title),
        ),
      )
      setFilm(data.film)
      setPoster(null)
      fileInput.current.value = ''
      setMessage(data.message)
    } catch (failure) {
      setError(
        failure.name === 'TimeoutError'
          ? 'La réponse tarde. Rechargez la liste avant de recommencer pour vérifier si le film a été enregistré.'
          : failure.message,
      )
    } finally {
      setSaving(false)
      busy.current = false
    }
  }

  function change(event) {
    setFilm((current) => ({
      ...current,
      [event.target.name]: event.target.value,
    }))
  }

  return (
    <section className="admin-section" aria-labelledby="films-title">
      <h2 id="films-title">Gérer les films</h2>
      <p>Une séance associe un film à un horaire et à une salle.</p>
      {loading ? (
        <p role="status">Chargement des films…</p>
      ) : (
        <div className="account-history-pages">
          <label htmlFor="admin-film">Film à modifier</label>
          <select
            id="admin-film"
            value={film.id || ''}
            disabled={saving}
            onChange={(event) =>
              edit(
                films.find((item) => item.id === Number(event.target.value)) ||
                  emptyFilm,
              )
            }
          >
            <option value="">Ajouter un film</option>
            {films.map((item) => (
              <option key={item.id} value={item.id}>
                {item.title}
                {item.isShowing ? '' : ' (retiré de l’affiche)'}
              </option>
            ))}
          </select>
          <button type="button" disabled={saving} onClick={load}>
            Recharger la liste
          </button>
        </div>
      )}
      <form className="account-form" onSubmit={save}>
        <fieldset disabled={saving} className="film-fields">
          <legend>{film.id ? 'Modifier le film' : 'Ajouter un film'}</legend>
          <label htmlFor="film-title">Titre</label>
          <input
            id="film-title"
            name="title"
            required
            maxLength={255}
            value={film.title}
            onChange={change}
          />
          <label htmlFor="film-synopsis">Synopsis</label>
          <textarea
            id="film-synopsis"
            name="synopsis"
            required
            maxLength={20000}
            rows={4}
            value={film.synopsis}
            onChange={change}
          />
          <label htmlFor="film-duration">Durée en minutes</label>
          <input
            id="film-duration"
            name="durationMinutes"
            type="number"
            min="1"
            step="1"
            required
            value={film.durationMinutes}
            onChange={change}
          />
          <label htmlFor="film-actors">Acteurs principaux</label>
          <textarea
            id="film-actors"
            name="mainActors"
            required
            maxLength={5000}
            rows={2}
            value={film.mainActors}
            onChange={change}
          />
          <label htmlFor="film-poster">
            Affiche {film.id ? '(laisser vide pour conserver l’actuelle)' : ''}
          </label>
          <input
            ref={fileInput}
            id="film-poster"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            required={!film.id}
            onChange={choosePoster}
          />
          <p>JPEG, PNG ou WebP, 5 Mo maximum.</p>
          {film.posterUrl && (
            <img
              className="admin-poster"
              src={film.posterUrl}
              alt={`Affiche actuelle de ${film.title}`}
            />
          )}
          <label>
            <input
              type="checkbox"
              checked={film.isShowing}
              onChange={(event) =>
                setFilm((current) => ({
                  ...current,
                  isShowing: event.target.checked,
                }))
              }
            />{' '}
            Afficher le film dans la programmation
          </label>
          <button className="primary-button">
            {saving ? 'Enregistrement…' : 'Enregistrer le film'}
          </button>
        </fieldset>
      </form>
      {error && (
        <p role="alert" className="field-error">
          {error}
        </p>
      )}
      {message && (
        <p role="status" className="account-success">
          {message}
        </p>
      )}
    </section>
  )
}
