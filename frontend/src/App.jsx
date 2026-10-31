import ForgotPasswordPage from './components/ForgotPasswordPage'
import ResetPasswordPage from './components/ResetPasswordPage'
import AccountMenu from './components/AccountMenu'
import LoginPage from './components/LoginPage'
import AccountPage from './components/AccountPage'
import useSession from './hooks/useSession'
import ProgrammingPage from './components/ProgrammingPage'
import RegistrationPage from './components/RegistrationPage'
import ActivationPage from './components/ActivationPage'
import './App.css'
import './Account.css'

export default function App() {
  const session = useSession()
  const path = window.location.pathname
  const page =
    path === '/inscription'
      ? 'registration'
      : path === '/activation'
        ? 'activation'
        : path === '/connexion'
          ? 'login'
          : path === '/compte'
            ? 'account'
            : path === '/mot-de-passe-oublie'
              ? 'forgot'
              : path === '/reinitialiser-mot-de-passe'
                ? 'reset'
                : 'programme'

  return (
    <>
      <a
        href={page === 'programme' ? '#programmation' : '#compte'}
        className="skip-link"
      >
        Aller au contenu
      </a>
      <header className="site-header">
        <a className="brand" href="/" aria-label="Le Grand Cinéma, accueil">
          <span className="brand-icon" aria-hidden="true">
            G
          </span>
          <span>
            Le Grand Cinéma<small>Le plaisir du grand écran</small>
          </span>
        </a>
        <nav aria-label="Navigation principale">
          <a href="/" aria-current={page === 'programme' ? 'page' : undefined}>
            À l’affiche
          </a>
          <AccountMenu session={session} />
        </nav>
      </header>
      {page === 'registration' ? (
        <RegistrationPage />
      ) : page === 'activation' ? (
        <ActivationPage />
      ) : page === 'login' ? (
        <LoginPage />
      ) : page === 'account' ? (
        <AccountPage session={session} />
      ) : page === 'forgot' ? (
        <ForgotPasswordPage />
      ) : page === 'reset' ? (
        <ResetPasswordPage />
      ) : (
        <ProgrammingPage />
      )}
      <footer>
        <span className="footer-brand">Le Grand Cinéma</span>
        <span>Des histoires à vivre sur grand écran.</span>
      </footer>
    </>
  )
}
