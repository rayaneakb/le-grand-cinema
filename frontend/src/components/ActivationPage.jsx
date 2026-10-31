import { useEffect, useState } from 'react'
import ResendVerificationForm from './ResendVerificationForm'

export default function ActivationPage() {
  const [query] = useState(() => window.location.search)
  const [result, setResult] = useState(null)

  useEffect(() => {
    const controller = new AbortController()

    async function verify() {
      try {
        const response = await fetch(`/api/verify-email${query}`, {
          signal: controller.signal,
        })
        const data = await response.json()
        if (
          ![200, 400, 410].includes(response.status) ||
          typeof data.message !== 'string'
        ) {
          throw new Error('Réponse d’activation incorrecte')
        }
        setResult({ ...data, success: response.ok })
        window.history.replaceState(null, '', '/activation')
      } catch (failure) {
        if (failure.name !== 'AbortError') {
          setResult({
            success: false,
            message:
              'La vérification est indisponible. Réouvrez le lien reçu pour réessayer.',
          })
        }
      }
    }

    verify()
    return () => controller.abort()
  }, [query])

  return (
    <main id="compte" className="account-page">
      <section className="account-panel" aria-labelledby="activation-title">
        <p className="eyebrow">Votre espace cinéma</p>
        <h1 id="activation-title">Vérification de votre adresse mail</h1>
        {!result && <p role="status">Vérification du lien en cours…</p>}
        {result && (
          <>
            <p
              role={result.success ? 'status' : 'alert'}
              className={result.success ? 'account-success' : 'account-warning'}
            >
              {result.message}
            </p>
            {result.success ? (
              <a className="account-back" href="/connexion">
                Se connecter
              </a>
            ) : (
              <ResendVerificationForm />
            )}
          </>
        )}
        <a className="account-back" href="/">
          Revenir à la programmation
        </a>
      </section>
    </main>
  )
}
