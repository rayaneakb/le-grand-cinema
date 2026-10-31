export default function FilmCard({ film, dateKey, onSelectScreening }) {
  const screenings = film.screenings.filter(
    (screening) =>
      new Date(screening.startsAt).toLocaleDateString('en-CA', { timeZone: 'Europe/Paris' }) ===
      dateKey,
  )
  const duration = `${Math.floor(film.durationMinutes / 60)} h ${String(film.durationMinutes % 60).padStart(2, '0')}`

  return (
    <article className="film-card">
      <img
        className="poster poster-image"
        src={film.posterUrl}
        alt={`Affiche du film ${film.title}`}
        loading="lazy"
      />
      <div className="film-content">
        <p className="film-meta">{duration}</p>
        <h2>{film.title}</h2>
        <p className="synopsis">{film.synopsis}</p>
        <p className="cast">
          <strong>Avec</strong> {film.mainActors}
        </p>
        <div className="screenings" aria-label={`Séances de ${film.title}`}>
          {screenings.length ? (
            screenings.map((screening) => (
              <button
                key={screening.id}
                className="screening"
                disabled={screening.full}
                aria-label={`${new Date(screening.startsAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Paris' })}, ${screening.room}${screening.full ? ', complet' : ''}`}
                onClick={() => onSelectScreening(film, screening)}
              >
                <strong>
                  {new Date(screening.startsAt).toLocaleTimeString('fr-FR', {
                    hour: '2-digit',
                    minute: '2-digit',
                    timeZone: 'Europe/Paris',
                  })}
                </strong>
                <span>{screening.full ? 'Complet' : screening.room}</span>
              </button>
            ))
          ) : (
            <p className="empty-state">Aucune séance disponible pour le moment.</p>
          )}
        </div>
      </div>
    </article>
  )
}
