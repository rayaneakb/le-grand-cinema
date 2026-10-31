import {
  readBookingDraft,
  saveBookingDraft,
  clearBookingDraft,
} from '../utils/bookingNavigation'
import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import BookingDetails from './BookingDetails'
import './SeatSelectionPage.css'

const formatPrice = (cents) =>
  new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(
    cents / 100,
  )

function startsSeatBlock(index, rowLength) {
  if (rowLength < 4) {
    return rowLength > 1 && index === Math.ceil(rowLength / 2)
  }
  const sideBlockSize = Math.max(1, Math.round(rowLength / 4))
  return index === sideBlockSize || index === rowLength - sideBlockSize
}

export default function SeatSelectionPage({ screeningId, user }) {
  const [draft] = useState(() => readBookingDraft(screeningId))
  const [step, setStep] = useState(draft?.step || 'seats')
  const [contact, setContact] = useState(draft?.contact || {})
  const bookingContact = {
    firstName: contact.firstName ?? user?.firstName ?? '',
    lastName: contact.lastName ?? user?.lastName ?? '',
    email: contact.email ?? user?.email ?? '',
  }
  const [selectionError, setSelectionError] = useState('')
  const [confirmation, setConfirmation] = useState(null)
  const [now, setNow] = useState(() => Date.now())
  const [confirmError, setConfirmError] = useState('')
  const [confirming, setConfirming] = useState(false)
  const [uncertain, setUncertain] = useState(Boolean(draft?.pending))
  const sending = useRef(false)
  const attempt = useRef(
    draft?.pending
      ? {
          key: draft.confirmationKey,
          expectedPrice: draft.expectedUnitPriceCents,
        }
      : null,
  )
  const stepHeading = useRef(null)
  const previousStep = useRef(step)
  const [data, setData] = useState(null)
  const [selected, setSelected] = useState(draft?.selected || [])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  const [enlarged, setEnlarged] = useState(false)
  const [planSize, setPlanSize] = useState({
    width: 0,
    height: 0,
    available: 0,
  })
  const planContainer = useRef(null)
  const planContent = useRef(null)

  useEffect(() => {
    function saveBeforeLogin() {
      if (!confirmation)
        saveBookingDraft({
          screeningId,
          step,
          selected,
          contact,
          pending: uncertain,
          confirmationKey: attempt.current?.key,
          expectedUnitPriceCents: attempt.current?.expectedPrice,
        })
    }
    window.addEventListener('cinema:before-login', saveBeforeLogin)
    return () =>
      window.removeEventListener('cinema:before-login', saveBeforeLogin)
  }, [screeningId, step, selected, contact, confirmation, uncertain])

  useLayoutEffect(() => {
    if (!planContainer.current || !planContent.current) return
    function measure() {
      setPlanSize({
        width: planContent.current.offsetWidth,
        height: planContent.current.offsetHeight,
        available: Math.max(0, planContainer.current.clientWidth - 18),
      })
    }
    const observer = new ResizeObserver(measure)
    observer.observe(planContainer.current)
    observer.observe(planContent.current)
    measure()
    return () => observer.disconnect()
  }, [data, loading, error, step])

  useEffect(() => {
    if (previousStep.current !== step) {
      stepHeading.current?.focus()
      previousStep.current = step
    }
  }, [step])

  useEffect(() => {
    if (step !== 'review') return
    const timer = setInterval(() => setNow(Date.now()), 30000)
    return () => clearInterval(timer)
  }, [step])

  function continueToContact() {
    if (!selected.length) {
      setSelectionError(
        'Veuillez sélectionner un ou plusieurs sièges pour continuer.',
      )
      return
    }
    setSelectionError('')
    setStep('contact')
  }

  const overviewScale = planSize.width
    ? Math.min(1, planSize.available / planSize.width)
    : 1
  const planScale = enlarged ? 1.35 : overviewScale

  useEffect(() => {
    const controller = new AbortController()
    async function load() {
      try {
        const response = await fetch(`/api/screenings/${screeningId}/seats`, {
          signal: controller.signal,
        })
        const result = await response.json()
        if (!response.ok)
          throw new Error(
            result.message || 'Le plan de salle est indisponible.',
          )
        if (
          !result.screening ||
          !Array.isArray(result.seats) ||
          !Number.isInteger(result.unitPriceCents)
        ) {
          throw new Error('Le plan de salle est indisponible.')
        }
        setData(result)
        if (draft && !draft.pending) {
          const available = new Set(
            result.seats
              .filter((seat) => seat.available)
              .map((seat) => seat.id),
          )
          const remaining = draft.selected.filter((id) => available.has(id))
          setSelected(remaining)
          if (
            remaining.length !== draft.selected.length ||
            (!remaining.length && draft.step !== 'seats')
          ) {
            setStep('seats')
            setSelectionError(
              'Certains sièges ne sont plus disponibles. Vérifiez votre sélection.',
            )
          }
          clearBookingDraft()
        }
        setError('')
      } catch (failure) {
        if (failure.name !== 'AbortError') {
          setError(
            failure.message === 'Failed to fetch'
              ? 'Le plan de salle est indisponible. Veuillez réessayer.'
              : failure.message,
          )
        }
      } finally {
        if (!controller.signal.aborted) setLoading(false)
      }
    }
    load()
    return () => controller.abort()
  }, [screeningId, retry, draft])

  const seats = data?.seats.filter((seat) => selected.includes(seat.id)) || []
  const rows = Object.groupBy(data?.seats || [], (seat) => seat.row)

  async function confirmReservation() {
    if (sending.current) return
    sending.current = true
    setConfirming(true)
    setConfirmError('')
    const payload = {
      screeningId: Number(screeningId),
      seatIds: [...selected].sort((a, b) => a - b),
      firstName: bookingContact.firstName.trim(),
      lastName: bookingContact.lastName.trim(),
      email: bookingContact.email.trim().toLowerCase(),
      expectedUnitPriceCents: uncertain
        ? attempt.current.expectedPrice
        : data.unitPriceCents,
    }
    const signature = JSON.stringify(payload)
    if (
      !attempt.current ||
      (!uncertain && attempt.current.signature !== signature)
    ) {
      attempt.current = {
        key: crypto.randomUUID(),
        signature,
        expectedPrice: payload.expectedUnitPriceCents,
      }
    }
    saveBookingDraft({
      screeningId,
      step: 'review',
      selected,
      contact: payload,
      pending: true,
      confirmationKey: attempt.current.key,
      expectedUnitPriceCents: payload.expectedUnitPriceCents,
    })
    let submitted = false
    try {
      const csrfResponse = await fetch('/api/csrf', {
        signal: AbortSignal.timeout(15000),
      })
      const csrf = await csrfResponse.json()
      if (!csrfResponse.ok || typeof csrf.csrfToken !== 'string')
        throw new Error('Formulaire indisponible')
      submitted = true
      const response = await fetch('/api/reservations', {
        method: 'POST',
        signal: AbortSignal.timeout(15000),
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrf.csrfToken,
        },
        body: JSON.stringify({
          ...payload,
          confirmationKey: attempt.current.key,
        }),
      })
      const result = await response.json()
      if (
        response.ok &&
        result.status === 'confirmed' &&
        result.reservation?.reference
      ) {
        clearBookingDraft()
        setUncertain(false)
        setConfirmation(result.reservation)
        setStep('confirmed')
        return
      }
      if (response.status >= 500 || response.ok)
        throw new Error('Réponse incertaine')
      setUncertain(false)
      clearBookingDraft()
      if (result.status === 'seats_unavailable') {
        const refresh = await fetch(`/api/screenings/${screeningId}/seats`)
        const plan = await refresh.json()
        if (!refresh.ok || !Array.isArray(plan.seats))
          throw new Error('Plan indisponible')
        const available = new Set(
          plan.seats.filter((seat) => seat.available).map((seat) => seat.id),
        )
        setData(plan)
        setSelected((current) => current.filter((id) => available.has(id)))
        setStep('seats')
        setSelectionError(result.message)
        attempt.current = null
      } else if (result.status === 'price_changed') {
        setData((current) => ({
          ...current,
          unitPriceCents: result.unitPriceCents,
        }))
        attempt.current = null
        setConfirmError(result.message)
      } else {
        setConfirmError(
          result.message ||
            'La réservation n’a pas pu être confirmée. Vérifiez vos informations.',
        )
      }
    } catch {
      setUncertain(submitted || uncertain)
      setConfirmError(
        submitted || uncertain
          ? 'La réponse du serveur n’a pas été reçue. Cliquez sur « Vérifier ma réservation » pour retrouver le résultat sans créer de doublon.'
          : 'Le formulaire est indisponible. Réessayez.',
      )
      if (!submitted && !uncertain) clearBookingDraft()
    } finally {
      sending.current = false
      setConfirming(false)
    }
  }

  function toggle(id) {
    setSelectionError('')
    setSelected((current) =>
      current.includes(id)
        ? current.filter((seatId) => seatId !== id)
        : [...current, id],
    )
  }

  return (
    <main id="compte" className="seat-page">
      <a className="account-back" href="/">
        Revenir à la programmation
      </a>
      <h1 ref={stepHeading} tabIndex={-1}>
        {step === 'confirmed'
          ? `Merci ${confirmation.firstName}, votre réservation est confirmée !`
          : step === 'seats'
            ? 'Choisissez vos sièges'
            : step === 'contact'
              ? 'Vos coordonnées'
              : 'Vérifiez votre réservation'}
      </h1>
      {step !== 'confirmed' && (
        <ol className="booking-steps" aria-label="Étapes de réservation">
          <li aria-current={step === 'seats' ? 'step' : undefined}>
            1. Sièges
          </li>
          <li aria-current={step === 'contact' ? 'step' : undefined}>
            2. Coordonnées
          </li>
          <li aria-current={step === 'review' ? 'step' : undefined}>
            3. Récapitulatif
          </li>
        </ol>
      )}
      {loading && <p role="status">Chargement du plan de salle…</p>}
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
      {confirmation && (
        <section className="booking-panel" aria-label="Réservation confirmée">
          <p className="account-success" role="status">
            Votre réservation est enregistrée.
          </p>
          <dl className="account-details">
            <dt>Numéro de réservation</dt>
            <dd>{confirmation.reference}</dd>
            <dt>Film</dt>
            <dd>{confirmation.filmTitle}</dd>
            <dt>Séance</dt>
            <dd>
              {new Date(confirmation.startsAt).toLocaleString('fr-FR', {
                timeZone: 'Europe/Paris',
              })}{' '}
              · {confirmation.room}
            </dd>
            <dt>Sièges</dt>
            <dd>{confirmation.seats.join(', ')}</dd>
            <dt>Coordonnées</dt>
            <dd>
              {confirmation.firstName} {confirmation.lastName}
              <br />
              {confirmation.email}
            </dd>
            <dt>Total à régler au cinéma</dt>
            <dd>{formatPrice(confirmation.totalAmountCents)}</dd>
          </dl>
          <p>Conservez cette référence pour votre passage au guichet.</p>
        </section>
      )}
      {!confirmation && !loading && !error && data && (
        <>
          <h2>{data.screening.filmTitle}</h2>
          <p>
            {new Date(data.screening.startsAt).toLocaleString('fr-FR', {
              dateStyle: 'full',
              timeStyle: 'short',
              timeZone: 'Europe/Paris',
            })}{' '}
            · {data.screening.room}
          </p>
          <p>
            {formatPrice(data.unitPriceCents)} par place · Paiement au cinéma
          </p>
          <div hidden={step !== 'seats'}>
            <ul className="seat-legend" aria-label="Légende du plan">
              <li>
                <span className="seat-key available" />
                Disponible
              </li>
              <li>
                <span className="seat-key selected" />
                Sélectionné
              </li>
              <li>
                <span className="seat-key occupied" />
                Réservé
              </li>
            </ul>
            {data.seats.length ? (
              <>
                {!data.seats.some((seat) => seat.available) && (
                  <p role="status">Cette séance est complète.</p>
                )}
                <div
                  className="seat-view-controls"
                  role="group"
                  aria-label="Affichage du plan"
                >
                  <button
                    aria-pressed={!enlarged}
                    onClick={() => setEnlarged(false)}
                  >
                    Vue d’ensemble
                  </button>
                  <button
                    aria-pressed={enlarged}
                    onClick={() => setEnlarged(true)}
                  >
                    Agrandir le plan
                  </button>
                </div>
                <div
                  ref={planContainer}
                  className="seat-plan"
                  role="region"
                  aria-label="Plan de la salle"
                  tabIndex={0}
                >
                  <div
                    className="seat-plan-viewport"
                    style={{
                      width: planSize.width * planScale,
                      height: planSize.height * planScale,
                    }}
                  >
                    <div
                      ref={planContent}
                      className="seat-plan-content"
                      style={{ transform: `scale(${planScale})` }}
                    >
                      <p className="cinema-screen">Écran</p>
                      {Object.entries(rows).map(([row, places]) => (
                        <div
                          className="seat-row"
                          role="group"
                          aria-label={`Rangée ${row}`}
                          key={row}
                        >
                          <span className="seat-row-label" aria-hidden="true">
                            {row}
                          </span>
                          {places.map((seat, index) => (
                            <button
                              key={seat.id}
                              className={`seat-button${startsSeatBlock(index, places.length) ? ' seat-after-aisle' : ''}`}
                              disabled={!seat.available}
                              aria-pressed={selected.includes(seat.id)}
                              aria-label={`Siège ${seat.row}${seat.number}${seat.available ? '' : ', réservé'}`}
                              onClick={() => toggle(seat.id)}
                            >
                              <span className="seat-number">{seat.number}</span>
                            </button>
                          ))}
                          <span className="seat-row-label" aria-hidden="true">
                            {row}
                          </span>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
                <p className="field-help">
                  {enlarged
                    ? 'Faites défiler le plan si nécessaire pour choisir votre siège.'
                    : 'Toute la salle est visible. Agrandissez le plan pour sélectionner plus facilement un siège.'}
                </p>
              </>
            ) : (
              <p>Aucun siège disponible dans cette salle.</p>
            )}
            <section
              className="seat-summary"
              aria-label="Votre sélection"
              aria-live="polite"
            >
              <h2>Votre sélection</h2>
              <p>
                {seats.length
                  ? `${seats.length} place${seats.length > 1 ? 's' : ''} · ${seats.map((seat) => `${seat.row}${seat.number}`).join(', ')}`
                  : 'Sélectionnez un ou plusieurs sièges.'}
              </p>
              <p className="seat-total">
                Total à régler au cinéma :{' '}
                {formatPrice(seats.length * data.unitPriceCents)}
              </p>
              <p>Les sièges sélectionnés ne sont pas encore réservés.</p>
              {selectionError && (
                <p className="field-error" role="alert">
                  {selectionError}
                </p>
              )}
              <button className="primary-button" onClick={continueToContact}>
                Continuer
              </button>
            </section>
          </div>
          {step === 'contact' && (
            <BookingDetails
              contact={bookingContact}
              setContact={setContact}
              onBack={() => setStep('seats')}
              onReview={() => setStep('review')}
            />
          )}
          {step === 'review' && (
            <section
              className="booking-panel"
              aria-label="Récapitulatif de réservation"
            >
              <div className="booking-section-heading">
                <h2>Vos coordonnées</h2>
                <button
                  className="secondary-button"
                  disabled={confirming || uncertain}
                  onClick={() => setStep('contact')}
                >
                  Modifier mes coordonnées
                </button>
              </div>
              <dl className="account-details">
                <dt>Prénom</dt>
                <dd>{bookingContact.firstName}</dd>
                <dt>Nom</dt>
                <dd>{bookingContact.lastName}</dd>
                <dt>Adresse mail</dt>
                <dd>{bookingContact.email}</dd>
              </dl>
              <div className="booking-section-heading">
                <h2>Vos places</h2>
                <button
                  className="secondary-button"
                  disabled={confirming || uncertain}
                  onClick={() => setStep('seats')}
                >
                  Modifier mes sièges
                </button>
              </div>
              <p>
                {seats.length} place{seats.length > 1 ? 's' : ''} ·{' '}
                {seats.map((seat) => `${seat.row}${seat.number}`).join(', ')}
              </p>
              <p>{formatPrice(data.unitPriceCents)} par place</p>
              <p className="seat-total">
                Total à régler au cinéma :{' '}
                {formatPrice(seats.length * data.unitPriceCents)}
              </p>
              <p>
                Annulation possible jusqu’à deux heures avant le début de la
                séance.
              </p>
              {now >
                new Date(data.screening.startsAt).getTime() -
                  2 * 60 * 60 * 1000 && (
                <p className="account-warning">
                  La séance commence dans moins de deux heures. Vous ne pourrez
                  plus annuler après confirmation.
                </p>
              )}
              {confirmError && (
                <p className="field-error" role="alert">
                  {confirmError}
                </p>
              )}
              <button
                className="primary-button"
                disabled={confirming}
                onClick={confirmReservation}
              >
                {confirming
                  ? 'Confirmation en cours…'
                  : uncertain
                    ? 'Vérifier ma réservation'
                    : 'Confirmer ma réservation'}
              </button>
            </section>
          )}
        </>
      )}
    </main>
  )
}
