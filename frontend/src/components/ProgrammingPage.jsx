import { useEffect, useState } from 'react'
import FilmCard from './FilmCard'

export default function ProgrammingPage() {
  const [films, setFilms] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    async function loadFilms() {
      try {
        const response = await fetch('/api/films', {
          signal: controller.signal,
        })
        if (!response.ok) throw new Error('Chargement impossible')
        const data = await response.json()
        if (!Array.isArray(data.films)) throw new Error('Réponse incorrecte')
        setFilms(data.films)
        setError('')
      } catch (failure) {
        if (failure.name !== 'AbortError')
          setError(
            'La programmation est indisponible pour le moment. Veuillez réessayer.',
          )
      } finally {
        if (!controller.signal.aborted) setLoading(false)
      }
    }
    loadFilms()
    const refresh = setInterval(loadFilms, 30000)
    return () => {
      controller.abort()
      clearInterval(refresh)
    }
  }, [retry])

  const [dayOffset, setDayOffset] = useState(0)
  const [selection, setSelection] = useState(null)
  const [today] = useState(() => new Date())
  const days = Array.from({ length: 5 }, (_, offset) => {
    const date = new Date(today)
    date.setDate(date.getDate() + offset)
    return {
      offset,
      dateKey: date.toLocaleDateString('en-CA', { timeZone: 'Europe/Paris' }),
      label:
        offset === 0
          ? 'Aujourd’hui'
          : date.toLocaleDateString('fr-FR', {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
              timeZone: 'Europe/Paris',
            }),
    }
  })

  return (
    <main id="programmation">
      <section className="intro" aria-labelledby="page-title">
        <p className="eyebrow">Votre prochaine sortie commence ici</p>
        <h1 id="page-title">
          Une séance.
          <br />
          <span>Mille émotions.</span>
        </h1>
        <p>
          Découvrez les films à l’affiche et trouvez votre prochaine séance au
          Grand Cinéma.
        </p>
      </section>
      <div className="demo-notice" role="note">
        Programmation de démonstration. La réservation n’est pas encore
        disponible.
      </div>
      <section aria-label="Programmation des films">
        <div className="programme-heading">
          <div>
            <p className="eyebrow">Sur nos écrans</p>
            <h2 className="section-title">À l’affiche</h2>
          </div>
          <span>
            {!loading && !error && `${films.length} films à découvrir`}
          </span>
        </div>
        <div
          className="date-picker"
          role="group"
          aria-label="Choisir le jour des séances"
        >
          {days.map((day) => (
            <button
              key={day.offset}
              aria-pressed={dayOffset === day.offset}
              onClick={() => {
                setDayOffset(day.offset)
                setSelection(null)
              }}
            >
              {day.label}
            </button>
          ))}
        </div>
        {selection && (
          <div className="selection" role="status">
            <strong>{selection.film.title}</strong> —{' '}
            {new Date(selection.screening.startsAt).toLocaleTimeString(
              'fr-FR',
              {
                hour: '2-digit',
                minute: '2-digit',
                timeZone: 'Europe/Paris',
              },
            )}
            , {selection.screening.room}.<br />
            Séance sélectionnée pour cet aperçu. La sélection des sièges sera
            ajoutée à la prochaine étape.
          </div>
        )}
        {loading && <p role="status">Chargement de la programmation…</p>}
        {error && (
          <div role="alert" className="error-state">
            <p>{error}</p>
            <button
              onClick={() => {
                setLoading(true)
                setRetry((value) => value + 1)
              }}
            >
              Réessayer
            </button>
          </div>
        )}
        {!loading && !error && (
          <div className="film-list">
            {films.length ? (
              films.map((film) => (
                <FilmCard
                  key={film.id}
                  film={film}
                  dateKey={days[dayOffset].dateKey}
                  onSelectScreening={(film, screening) =>
                    setSelection({ film, screening })
                  }
                />
              ))
            ) : (
              <p>Aucun film à l’affiche pour le moment.</p>
            )}
          </div>
        )}
      </section>
    </main>
  )
}
