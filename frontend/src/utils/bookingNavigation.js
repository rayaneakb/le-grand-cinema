const draftKey = 'cinema.booking-draft'
const draftLifetime = 30 * 60 * 1000

export function bookingReturnPath(value) {
  if (typeof value !== 'string') return null
  return value === '/' ||
    value === '/compte' ||
    /^\/seances\/[1-9][0-9]{0,9}\/sieges$/.test(value)
    ? value
    : null
}

export function saveBookingDraft(draft) {
  try {
    sessionStorage.setItem(
      draftKey,
      JSON.stringify({ ...draft, expiresAt: Date.now() + draftLifetime }),
    )
  } catch {
    // Le navigateur peut interdire le stockage temporaire.
  }
}

export function clearBookingDraft() {
  try {
    sessionStorage.removeItem(draftKey)
  } catch {
    // La déconnexion doit rester possible même si le stockage est bloqué.
  }
}

export function readBookingDraft(screeningId) {
  try {
    const draft = JSON.parse(sessionStorage.getItem(draftKey))
    if (
      !draft ||
      !Number.isFinite(draft.expiresAt) ||
      draft.expiresAt < Date.now()
    ) {
      clearBookingDraft()
      return null
    }
    if (
      draft.screeningId !== screeningId ||
      !['seats', 'contact', 'review'].includes(draft.step) ||
      !Array.isArray(draft.selected) ||
      draft.selected.length > 1000 ||
      !draft.selected.every(Number.isSafeInteger)
    )
      return null
    const contact = Object.fromEntries(
      ['firstName', 'lastName', 'email']
        .filter((key) => typeof draft.contact?.[key] === 'string')
        .map((key) => [
          key,
          draft.contact[key].slice(0, key === 'email' ? 180 : 100),
        ]),
    )
    return {
      step: draft.step,
      selected: [...new Set(draft.selected)],
      contact,
      pending:
        draft.pending === true &&
        typeof draft.confirmationKey === 'string' &&
        /^[0-9a-f-]{36}$/i.test(draft.confirmationKey) &&
        Number.isInteger(draft.expectedUnitPriceCents) &&
        draft.expectedUnitPriceCents > 0,
      confirmationKey: draft.confirmationKey,
      expectedUnitPriceCents: draft.expectedUnitPriceCents,
    }
  } catch {
    clearBookingDraft()
    return null
  }
}
