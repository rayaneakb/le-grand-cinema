
import './App.css'
import './Account.css'

export default function App() {
  const path = window.location.pathname
  const screeningMatch = path.match(/^\/seances\/([1-9][0-9]{0,9})\/sieges$/)
  const reservationMatch = path.match(/^\/reservations\/([A-F0-9]{16})$/)
  return (
    <>
      <header className="site-header">
        <a className="brand" href="/">Le Grand Cinéma</a>
        <nav aria-label="Navigation principale">
          <a href="/">À l’affiche</a>
        </nav>
      </header>
      {<main id="compte"><h1>Le Grand Cinéma</h1></main>}
    </>
  )
}
