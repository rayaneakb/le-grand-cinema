import { useState } from 'react'

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
]

export default function BookingDetails({
  contact,
  setContact,
  onBack,
  onReview,
}) {
  const [errors, setErrors] = useState({})

  function submit(event) {
    event.preventDefault()
    const missing = Object.fromEntries(
      fields
        .filter((field) => !contact[field.name].trim())
        .map((field) => [
          field.name,
          `Veuillez renseigner votre ${field.label.toLowerCase()}.`,
        ]),
    )
    setErrors(missing)
    if (Object.keys(missing).length) return
    setContact(
      Object.fromEntries(
        Object.entries(contact).map(([key, value]) => [key, value.trim()]),
      ),
    )
    onReview()
  }

  return (
    <section className="booking-panel" aria-label="Vos coordonnées">
      <h2>Vos coordonnées</h2>
      <p>
        Vérifiez votre adresse mail. La confirmation de réservation sera envoyée
        à cette adresse.
      </p>
      <form className="account-form" onSubmit={submit}>
        {fields.map((field) => (
          <div className="form-field" key={field.name}>
            <label htmlFor={`booking-${field.name}`}>{field.label}</label>
            <input
              {...field}
              id={`booking-${field.name}`}
              required
              value={contact[field.name]}
              aria-invalid={Boolean(errors[field.name])}
              aria-describedby={
                errors[field.name] ? `booking-${field.name}-error` : undefined
              }
              onChange={(event) => {
                setContact((current) => ({
                  ...current,
                  [field.name]: event.target.value,
                }))
                setErrors((current) => ({
                  ...current,
                  [field.name]: undefined,
                }))
              }}
            />
            {errors[field.name] && (
              <p
                id={`booking-${field.name}-error`}
                className="field-error"
                role="alert"
              >
                {errors[field.name]}
              </p>
            )}
          </div>
        ))}
        <div className="booking-actions">
          <button type="button" className="secondary-button" onClick={onBack}>
            Modifier mes sièges
          </button>
          <button className="primary-button">Voir le récapitulatif</button>
        </div>
      </form>
    </section>
  )
}
