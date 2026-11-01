import { expect, test } from '@playwright/test'

const session = {
  id: 31,
  filmId: 12,
  filmTitle: 'Film de test',
  roomId: 2,
  room: 'Salle 2',
  startsAt: '2035-01-02T13:00:00Z',
  endsAt: '2035-01-02T15:58:00Z',
  status: 'scheduled',
  canEdit: true,
  reservationCount: 1,
  pendingEmails: 0,
}

test.beforeEach(async ({ page }) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({
      json: {
        user: {
          id: 1,
          firstName: 'Staff',
          email: 'staff@example.com',
          roles: ['ROLE_EMPLOYEE'],
        },
      },
    }),
  )
  await page.route('**/api/csrf', (route) =>
    route.fulfill({ json: { csrfToken: 'screening-token' } }),
  )
  await page.route('**/api/admin/settings', (route) =>
    route.fulfill({ json: { ticketPriceCents: 1000 } }),
  )
  await page.route('**/api/admin/films', (route) =>
    route.fulfill({ json: { films: [] } }),
  )
})

function reply(screenings = [session]) {
  return {
    json: {
      screenings,
      films: [{ id: 12, title: 'Film de test', durationMinutes: 178 }],
      rooms: [{ id: 2, name: 'Salle 2' }],
    },
  }
}

test('l’employé crée une séance puis la retrouve avec la fin calculée', async ({
  page,
}) => {
  let created = false
  await page.route('**/api/admin/screenings?*', (route) =>
    route.fulfill(reply(created ? [session] : [])),
  )
  await page.route('**/api/admin/screenings', (route) => {
    expect(route.request().postDataJSON()).toEqual({
      filmId: 12,
      roomId: 2,
      startsAt: '2035-01-02T14:00',
    })
    expect(route.request().headers()['x-csrf-token']).toBe('screening-token')
    created = true
    return route.fulfill({
      status: 201,
      json: { id: 31, message: 'La séance est enregistrée.', pendingEmails: 0 },
    })
  })
  await page.goto('/admin')
  await page.getByRole('button', { name: 'Nouvelle séance' }).click()
  await page.getByLabel('Film de la séance').selectOption('12')
  await page.getByLabel('Salle de la séance').selectOption('2')
  await page.getByLabel('Début de la séance').fill('2035-01-02T14:00')
  await page.getByRole('button', { name: 'Enregistrer la séance' }).click()
  await expect(
    page.getByText('La séance est enregistrée.', { exact: true }),
  ).toBeVisible()
  await expect(page.getByLabel('Séances du')).toHaveValue('2035-01-02')
  await expect(page.getByText(/Fin 02\/01\/2035 16:58/)).toBeVisible()
})

test('un conflit conserve l’horaire saisi pour le corriger', async ({
  page,
}) => {
  await page.route('**/api/admin/screenings?*', (route) =>
    route.fulfill(reply()),
  )
  await page.route('**/api/admin/screenings/31', (route) =>
    route.fulfill({
      status: 409,
      json: {
        message:
          'L’ancien et le nouvel horaire doivent être à au moins 24 heures.',
      },
    }),
  )
  await page.goto('/admin')
  await page
    .getByRole('button', { name: 'Modifier la séance', exact: true })
    .click()
  await page.getByLabel('Début de la séance').fill('2035-01-01T14:00')
  await page.getByRole('button', { name: 'Enregistrer la séance' }).click()
  await expect(page.getByRole('alert')).toContainText('24 heures')
  await expect(page.getByLabel('Début de la séance')).toHaveValue(
    '2035-01-01T14:00',
  )
})

test('annuler demande confirmation et permet de relancer un mail en échec', async ({
  page,
}) => {
  let cancelled = false
  let pending = 0
  let calls = 0
  await page.route('**/api/admin/screenings?*', (route) =>
    route.fulfill(
      reply([
        {
          ...session,
          status: cancelled ? 'cancelled' : 'scheduled',
          pendingEmails: pending,
          canEdit: !cancelled,
        },
      ]),
    ),
  )
  await page.route('**/api/admin/screenings/31/cancel', (route) => {
    calls++
    cancelled = true
    pending = 1
    return route.fulfill({
      json: { message: 'La séance est annulée.', pendingEmails: 1 },
    })
  })
  await page.route('**/api/admin/screenings/31/retry-emails', (route) => {
    pending = 0
    return route.fulfill({
      json: {
        message: 'Les notifications ont été relancées.',
        pendingEmails: 0,
      },
    })
  })
  await page.goto('/admin')
  await page
    .getByRole('button', { name: 'Annuler la séance', exact: true })
    .click()
  expect(calls).toBe(0)
  await page.getByRole('button', { name: 'Conserver la séance' }).click()
  expect(calls).toBe(0)
  await page
    .getByRole('button', { name: 'Annuler la séance', exact: true })
    .click()
  await page
    .getByRole('button', { name: 'Confirmer l’annulation de la séance' })
    .click()
  await expect(page.getByText(/Séance annulée ·/)).toBeVisible()
  await page
    .getByRole('button', { name: 'Relancer les mails en attente' })
    .click()
  await expect(
    page.getByRole('button', { name: 'Relancer les mails en attente' }),
  ).toHaveCount(0)
  expect(calls).toBe(1)
})

test('un conflit reste visible dans la modale avec l’horaire saisi', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 720 })
  const response = reply(
    Array.from({ length: 12 }, (_, index) => ({ ...session, id: 100 + index })),
  )
  response.json.films.push({
    id: 13,
    title: 'Autre film',
    durationMinutes: 112,
  })
  await page.route('**/api/admin/screenings?*', (route) =>
    route.fulfill(response),
  )
  await page.route('**/api/admin/screenings', (route) => {
    expect(route.request().postDataJSON().filmId).toBe(13)
    return route.fulfill({
      status: 409,
      json: {
        message:
          'Aucune disponibilité dans cette salle à cet horaire. Gardez une heure entre deux séances.',
      },
    })
  })
  await page.goto('/admin')
  await page.getByRole('button', { name: 'Nouvelle séance' }).click()
  await page.getByLabel('Film de la séance').selectOption('13')
  await page.getByLabel('Salle de la séance').selectOption('2')
  await page.getByLabel('Début de la séance').fill('2035-01-02T15:57')
  await page.getByRole('button', { name: 'Enregistrer la séance' }).click()
  const alert = page.getByRole('alert')
  await expect(alert).toContainText('Aucune disponibilité')
  await expect(alert).toBeInViewport()
  await expect(page.getByLabel('Début de la séance')).toHaveValue(
    '2035-01-02T15:57',
  )
  await expect(page.getByRole('dialog')).toBeVisible()
})

test('modifier ouvre une fenêtre avec la séance actuelle et protège le film réservé', async ({
  page,
}) => {
  await page.route('**/api/admin/screenings?*', (route) =>
    route.fulfill(reply()),
  )
  await page.goto('/admin')
  await expect(page.getByLabel('Début de la séance')).toHaveCount(0)
  const trigger = page.getByRole('button', {
    name: 'Modifier la séance',
    exact: true,
  })
  await trigger.click()
  const dialog = page.getByRole('dialog', { name: 'Modifier la séance' })
  await expect(dialog).toContainText('Horaire actuel 02/01/2035 14:00')
  await expect(dialog.getByLabel('Film de la séance')).toBeDisabled()
  await expect(dialog.getByLabel('Salle de la séance')).toBeDisabled()
  await expect(dialog.getByLabel('Début de la séance')).toBeEnabled()
  await dialog.getByRole('button', { name: 'Fermer' }).click()
  await expect(trigger).toBeFocused()
})
