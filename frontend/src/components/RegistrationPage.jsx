import { useRef, useState } from 'react'
import ResendVerificationForm from './ResendVerificationForm'

const fields = [
  {
    name: 'firstName',
    label: 'Prénom',
    autoComplete: 'given-name',
    maxLength: 100,
  },
  {
    name: 'lastName',
    label: 'Nom',
    autoComplete: 'family-name',
    maxLength: 100,
  },
  {
    name: 'email',
    label: 'Adresse mail',
    autoComplete: 'email',
    type: 'email',
    maxLength: 180,
  },
  {
    name: 'password',
    label: 'Mot de passe',
    autoComplete: 'new-password',
    type: 'password',
    minLength: 10,
    maxLength: 128,
  },
]

export default function RegistrationPage() {
  const [values, setValues] = useState({
    firstName: '',
    lastName: '',
    email: '',
    password: '',
  })
  const [errors, setErrors] = useState({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState(null)
  const sending = useRef(false)

  async function register(event) {
    event.preventDefault()
    if (sending.current) return
    sending.current = true
    setBusy(true)
    setErrors({})
    setError('')

    try {
      const response = await fetch('/api/register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(values),
      })
      const data = await response.json()
      if (!response.ok) {
        if (data.errors) {
          setErrors(data.errors)
        } else {
          setError(
            data.message || 'Votre compte n’a pas pu être créé. Réessayez.',
          )
        }
        return
      }
      if (
        data.status !== 'pending_verification' ||
        typeof data.emailSent !== 'boolean'
      ) {
        throw new Error('Réponse d’inscription incorrecte')
      }
      setResult({ email: values.email.trim(), emailSent: data.emailSent })
      setValues((current) => ({ ...current, password: '' }))
    } catch {
      setError(
        'La réponse du serveur n’a pas pu être obtenue. Si le compte a été créé, vous pouvez demander un nouveau mail d’activation.',
      )
    } finally {
      sending.current = false
      setBusy(false)
    }
  }

  return (
    <main id="compte" className="account-page">
      <section className="account-panel" aria-labelledby="account-title">
        <p className="eyebrow">Votre espace cinéma</p>
        <h1 id="account-title">
          {result ? 'Vérifiez votre adresse mail' : 'Créer un compte'}
        </h1>
        {result ? (
          <>
            <p
              role="status"
              className={
                result.emailSent ? 'account-success' : 'account-warning'
              }
            >
              {result.emailSent
                ? `Votre compte est créé. Un lien d’activation a été envoyé à ${result.email}. Il est valable une heure.`
                : 'Votre compte est créé, mais le mail d’activation n’a pas pu être envoyé.'}
            </p>
            <p>
              Ouvrez le lien reçu pour vérifier votre adresse mail. Pensez à
              regarder vos spams.
            </p>
            <ResendVerificationForm initialEmail={result.email} />
          </>
        ) : (
          <>
            <p>
              Retrouvez vos réservations depuis votre compte. La réservation
              sans compte restera possible.
            </p>
            <form
              className="account-form"
              onSubmit={register}
              aria-label="Inscription"
            >
              {fields.map((field) => (
                <div className="form-field" key={field.name}>
                  <label htmlFor={field.name}>{field.label}</label>
                  <input
                    {...field}
                    id={field.name}
                    required
                    disabled={busy}
                    value={values[field.name]}
                    aria-invalid={Boolean(errors[field.name])}
                    aria-describedby={
                      errors[field.name]
                        ? `${field.name}-error`
                        : field.name === 'password'
                          ? 'password-help'
                          : undefined
                    }
                    onChange={(event) => {
                      setValues((current) => ({
                        ...current,
                        [field.name]: event.target.value,
                      }))
                      setErrors((current) => ({
                        ...current,
                        [field.name]: undefined,
                      }))
                    }}
                  />
                  {field.name === 'password' && (
                    <p id="password-help" className="field-help">
                      Entre 10 et 128 caractères. Les espaces sont acceptés.
                    </p>
                  )}
                  {errors[field.name] && (
                    <p
                      id={`${field.name}-error`}
                      className="field-error"
                      role="alert"
                    >
                      {errors[field.name].join(' ')}
                    </p>
                  )}
                </div>
              ))}
              {error && (
                <p className="field-error" role="alert">
                  {error}
                </p>
              )}
              <button className="primary-button" disabled={busy}>
                {busy ? 'Création en cours…' : 'Créer mon compte'}
              </button>
            </form>
            {(errors.email || error) && (
              <ResendVerificationForm initialEmail={values.email} />
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
