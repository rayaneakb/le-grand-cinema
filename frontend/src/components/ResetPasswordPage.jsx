import { useEffect, useRef, useState } from 'react'

export default function ResetPasswordPage() {
  const [url] = useState(() => window.location.href)
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [invalidLink, setInvalidLink] = useState(!window.location.search)
  const [changed, setChanged] = useState(false)
  const sending = useRef(false)

  useEffect(() => {
    window.history.replaceState(null, '', '/reinitialiser-mot-de-passe')
  }, [])

  async function reset(event) {
    event.preventDefault()
    if (sending.current) return
    sending.current = true
    setBusy(true)
    setError('')
    try {
      const response = await fetch('/api/reset-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url, password }),
      })
      const data = await response.json()
      if (!response.ok) {
        setError(data.message || 'Le mot de passe n’a pas pu être modifié.')
        setInvalidLink(data.status === 'invalid_link')
        return
      }
      if (data.status !== 'password_changed')
        throw new Error('Réponse incorrecte')
      setChanged(true)
      setPassword('')
    } catch {
      setError(
        'La réponse du serveur n’a pas pu être obtenue. Essayez de vous connecter avec le nouveau mot de passe ou demandez un nouveau lien.',
      )
    } finally {
      sending.current = false
      setBusy(false)
    }
  }

  return (
    <main id="compte" className="account-page">
      <section className="account-panel" aria-labelledby="reset-title">
        <p className="eyebrow">Votre espace cinéma</p>
        <h1 id="reset-title">Choisir un nouveau mot de passe</h1>
        {changed ? (
          <>
            <p role="status" className="account-success">
              Votre mot de passe est modifié. Connectez-vous avec le nouveau.
            </p>
            <a className="account-back" href="/connexion">
              Se connecter
            </a>
          </>
        ) : (
          <>
            {!invalidLink && (
              <form className="account-form" onSubmit={reset}>
                <label htmlFor="new-password">Nouveau mot de passe</label>
                <input
                  id="new-password"
                  type="password"
                  autoComplete="new-password"
                  required
                  minLength={10}
                  maxLength={128}
                  aria-describedby="reset-password-help"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                />
                <p id="reset-password-help" className="field-help">
                  Entre 10 et 128 caractères. Les espaces sont acceptés.
                </p>
                <button className="primary-button" disabled={busy}>
                  {busy
                    ? 'Modification en cours…'
                    : 'Modifier mon mot de passe'}
                </button>
              </form>
            )}
            {error && (
              <p role="alert" className="field-error">
                {error}
              </p>
            )}
            {invalidLink && (
              <>
                {!error && (
                  <p role="alert">
                    Ouvrez le lien reçu par mail pour choisir un nouveau mot de
                    passe.
                  </p>
                )}
                <a className="account-back" href="/mot-de-passe-oublie">
                  Demander un nouveau lien
                </a>
              </>
            )}
          </>
        )}
      </section>
    </main>
  )
}
