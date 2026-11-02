export function cancellationMessage(origin, staff = false) {
  if (origin === 'client') {
    return staff
      ? 'Le client a annulé cette réservation.'
      : 'Vous avez annulé cette réservation.'
  }
  if (origin === 'cinema') return 'La séance a été annulée par le cinéma.'
  return staff ? 'Réservation annulée.' : 'Votre réservation est annulée.'
}
