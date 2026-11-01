import { expect, test } from '@playwright/test'

const reference = 'ABCDEF0123456789'
const query = '?_expiration=2000000000&_hash=signature-test'
const booking = {
  reference,
  firstName: 'Élodie',
  lastName: 'Martin',
  email: 'elodie@example.com',
  filmTitle: 'Film de test',
  room: 'Salle 2',
  startsAt: '2030-01-01T19:00:00Z',
  cancellationDeadline: '2030-01-01T17:00:00Z',
  seats: ['A1', 'A2'],
  totalAmountCents: 2000,
  status: 'confirmed',
  canCancel: true,
}

test.beforeEach(async ({ page }) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: { user: null } }),
  )
  await page.route('**/api/csrf', (route) =>
    route.fulfill({ json: { csrfToken: 'csrf-test' } }),
  )
})

test('le lien personnel ouvre le récapitulatif sans annuler au chargement', async ({
  page,
}) => {
  let cancellations = 0
  await page.route('**/api/reservations/**', (route) => {
    expect(route.request().url()).toContain(query)
    if (route.request().method() === 'POST') cancellations++
    return route.fulfill({ json: { reservation: booking } })
  })
  await page.goto(`/reservations/${reference}${query}`)
  await expect(page.getByText('Film de test', { exact: true })).toBeVisible()
  await expect(page.getByText('A1, A2', { exact: true })).toBeVisible()
  await page
    .getByRole('button', { name: 'Annuler ma réservation', exact: true })
    .click()
  await expect(
    page.getByText(
      'Confirmez-vous l’annulation de votre réservation ? Tous vos sièges seront libérés.',
    ),
  ).toBeVisible()
  await page.getByRole('button', { name: 'Conserver ma réservation' }).click()
  await expect(
    page.getByRole('button', { name: 'Confirmer l’annulation' }),
  ).toHaveCount(0)
  expect(cancellations).toBe(0)
})

test('une confirmation annule puis masque le bouton et conserve le récapitulatif', async ({
  page,
}) => {
  let cancellations = 0
  await page.route('**/api/reservations/**', (route) => {
    if (route.request().method() === 'POST') {
      cancellations++
      expect(route.request().headers()['x-csrf-token']).toBe('csrf-test')
      expect(route.request().url()).toContain(`/cancel${query}`)
      return route.fulfill({
        json: {
          message: 'Votre réservation est annulée. Les sièges ont été libérés.',
          reservation: { ...booking, status: 'cancelled', canCancel: false },
        },
      })
    }
    return route.fulfill({ json: { reservation: booking } })
  })
  await page.goto(`/reservations/${reference}${query}`)
  await page
    .getByRole('button', { name: 'Annuler ma réservation', exact: true })
    .click()
  await page.getByRole('button', { name: 'Confirmer l’annulation' }).click()
  await expect(
    page.getByText(
      'Votre réservation est annulée. Les sièges ont été libérés.',
    ),
  ).toBeVisible()
  await expect(
    page.getByRole('button', { name: /annulation|annuler/i }),
  ).toHaveCount(0)
  await expect(page.getByText(reference, { exact: true })).toBeVisible()
  expect(cancellations).toBe(1)
})

test('le refus du serveur après la limite reste visible', async ({ page }) => {
  await page.route('**/api/reservations/**', (route) =>
    route.request().method() === 'POST'
      ? route.fulfill({
          status: 409,
          json: {
            message:
              'Le délai est dépassé. Vous ne pouvez plus annuler votre réservation.',
          },
        })
      : route.fulfill({ json: { reservation: booking } }),
  )
  await page.goto(`/reservations/${reference}${query}`)
  await page
    .getByRole('button', { name: 'Annuler ma réservation', exact: true })
    .click()
  await page.getByRole('button', { name: 'Confirmer l’annulation' }).click()
  await expect(page.getByRole('alert')).toContainText('Le délai est dépassé')
  await expect(
    page.getByRole('button', { name: 'Confirmer l’annulation' }),
  ).toHaveCount(0)
  await expect(
    page.getByText('Votre réservation est confirmée.', { exact: true }),
  ).toBeVisible()
})

test('un lien invalide ne montre aucune coordonnée', async ({ page }) => {
  await page.route('**/api/reservations/**', (route) =>
    route.fulfill({
      status: 403,
      json: { message: 'Ce lien est invalide ou a expiré.' },
    }),
  )
  await page.goto(`/reservations/${reference}${query}`)
  await expect(page.getByRole('alert')).toHaveText(
    'Ce lien est invalide ou a expiré.',
  )
  await expect(page.getByText(booking.email)).toHaveCount(0)
  await expect(
    page.getByRole('button', { name: 'Annuler ma réservation', exact: true }),
  ).toHaveCount(0)
})

test('le téléchargement conserve le lien personnel et produit un fichier PDF', async ({
  page,
}) => {
  await page.route('**/api/reservations/**', (route) => {
    if (route.request().url().includes('/pdf?')) {
      expect(route.request().url()).toContain(`/pdf${query}`)
      return route.fulfill({
        contentType: 'application/pdf',
        body: '%PDF-1.4\n%%EOF',
      })
    }
    return route.fulfill({ json: { reservation: booking } })
  })
  await page.goto(`/reservations/${reference}${query}`)
  const downloaded = page.waitForEvent('download')
  await page
    .getByRole('button', { name: 'Télécharger ma confirmation PDF' })
    .click()
  const file = await downloaded
  expect(file.suggestedFilename()).toBe(`reservation-${reference}.pdf`)
  expect(await file.failure()).toBeNull()
})

test('une réservation annulée ne propose plus le téléchargement', async ({
  page,
}) => {
  await page.route('**/api/reservations/**', (route) =>
    route.fulfill({
      json: {
        reservation: { ...booking, status: 'cancelled', canCancel: false },
      },
    }),
  )
  await page.goto(`/reservations/${reference}${query}`)
  await expect(
    page.getByText(
      'Le téléchargement du PDF n’est plus disponible pour cette réservation annulée.',
    ),
  ).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Télécharger ma confirmation PDF' }),
  ).toHaveCount(0)
})

test('le refus du PDF par Symfony est affiché sans produire de fichier', async ({
  page,
}) => {
  await page.route('**/api/reservations/**', (route) =>
    route.request().url().includes('/pdf?')
      ? route.fulfill({
          status: 409,
          json: {
            message:
              'Cette réservation est annulée. Vous ne pouvez plus télécharger sa confirmation PDF.',
          },
        })
      : route.fulfill({ json: { reservation: booking } }),
  )
  await page.goto(`/reservations/${reference}${query}`)
  await page
    .getByRole('button', { name: 'Télécharger ma confirmation PDF' })
    .click()
  await expect(page.getByRole('alert')).toContainText(
    'Cette réservation est annulée',
  )
})
