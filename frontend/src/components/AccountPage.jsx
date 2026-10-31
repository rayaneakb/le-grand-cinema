import useLogout from '../hooks/useLogout'

export default function AccountPage({ session }) {
  const { logout, busy, error } = useLogout(session.logoutToken)

  return (
    <main id="compte" className="account-page">
      <section className="account-panel" aria-labelledby="profile-title">
        <p className="eyebrow">Votre espace cinéma</p>
        <h1 id="profile-title">Mon compte</h1>
        {session.loading ? (
          <p role="status">Chargement de votre compte…</p>
        ) : session.user ? (
          <>
            <p>Bonjour {session.user.firstName}.</p>
            <dl className="account-details">
              <dt>Nom</dt>
              <dd>
                {session.user.firstName} {session.user.lastName}
              </dd>
              <dt>Adresse mail</dt>
              <dd>{session.user.email}</dd>
            </dl>
            <button className="primary-button" onClick={logout} disabled={busy}>
              {busy ? 'Déconnexion en cours…' : 'Me déconnecter'}
            </button>
            {error && (
              <p role="alert" className="field-error">
                {error}
              </p>
            )}
          </>
        ) : (
          <>
            <p>Veuillez vous connecter pour accéder à votre compte.</p>
            <a className="account-back" href="/connexion">
              Se connecter
            </a>
          </>
        )}
      </section>
    </main>
  )
}
