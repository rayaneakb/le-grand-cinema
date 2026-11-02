import AccountPage from './components/AccountPage'
import ActivationPage from './components/ActivationPage'
import ForgotPasswordPage from './components/ForgotPasswordPage'
import LoginPage from './components/LoginPage'
import RegistrationPage from './components/RegistrationPage'
import ResetPasswordPage from './components/ResetPasswordPage'
import useSession from './hooks/useSession'
import AccountMenu from './components/AccountMenu'
import './App.css'
import './Account.css'

export default function App() {
  const session = useSession()
  const path = window.location.pathname
  const screeningMatch = path.match(/^\/seances\/([1-9][0-9]{0,9})\/sieges$/)
  const reservationMatch = path.match(/^\/reservations\/([A-F0-9]{16})$/)
  return (
    <>
      <header className="site-header">
        <a className="brand" href="/">Le Grand Cinéma</a>
        <nav aria-label="Navigation principale">
          <a href="/">À l’affiche</a>
          <AccountMenu session={session} />
        </nav>
      </header>
      {path === '/inscription' ? <RegistrationPage /> : (path === '/activation' ? <ActivationPage /> : (path === '/connexion' ? <LoginPage /> : (path === '/compte' ? <AccountPage session={session} /> : (path === '/mot-de-passe-oublie' ? <ForgotPasswordPage /> : (path === '/reinitialiser-mot-de-passe' ? <ResetPasswordPage /> : (<main id="compte"><h1>Le Grand Cinéma</h1></main>))))))}
    </>
  )
}
