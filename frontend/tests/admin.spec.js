import { expect, test } from '@playwright/test'

const user = {
  id: 1,
  firstName: 'Rayane',
  lastName: 'Akbly',
  email: 'direction@example.com',
  roles: ['ROLE_DIRECTOR'],
}
const booking = {
  reference: 'ABCDEF0123456789',
  firstName: 'Camille',
  lastName: 'Martin',
  email: 'camille@example.com',
  status: 'confirmed',
  filmTitle: 'Au-delà des étoiles',
  startsAt: '2030-01-01T19:00:00Z',
  room: 'Salle 2',
  seats: ['A1', 'A2'],
  totalAmountCents: 2000,
}

test.beforeEach(async ({ page }) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: { user } }),
  )
  await page.route('**/api/admin/settings', (route) =>
    route.fulfill({ json: { ticketPriceCents: 1000, canChangePrice: true } }),
  )
  await page.route('**/api/csrf', (route) =>
    route.fulfill({ json: { csrfToken: 'csrf-test' } }),
  )
})

test('le client ne voit pas l’administration et la page lui refuse l’accès', async ({
  page,
}) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: { user: { ...user, roles: ['ROLE_USER'] } } }),
  )
  let calls = 0
  await page.route('**/api/admin/**', (route) => {
    calls++
    return route.fulfill({ status: 403 })
  })
  await page.goto('/admin')
  await expect(page.getByRole('alert')).toHaveText(
    'Vous n’avez pas accès à l’administration.',
  )
  await page.getByRole('button', { name: 'Mon compte', exact: true }).click()
  await expect(
    page.getByRole('link', { name: 'Administration', exact: true }),
  ).toHaveCount(0)
  expect(calls).toBe(0)
})

test('l’employé recherche une réservation sans accès au tarif ni aux statistiques', async ({
  page,
}) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: { user: { ...user, roles: ['ROLE_EMPLOYEE'] } } }),
  )
  await page.route('**/api/admin/reservations?*', (route) => {
    expect(new URL(route.request().url()).searchParams.get('q')).toBe(
      booking.reference,
    )
    return route.fulfill({
      json: { reservations: [booking], page: 1, hasMore: false },
    })
  })
  await page.goto('/admin')
  await page
    .getByLabel('Numéro de réservation ou adresse mail')
    .fill(booking.reference)
  await page.getByRole('button', { name: 'Rechercher', exact: true }).click()
  await expect(
    page.getByText('Réservation confirmée', { exact: true }),
  ).toBeVisible()
  await expect(page.getByText('Sièges A1, A2')).toBeVisible()
  await expect(
    page.getByText(/camille@example\.com/),
  ).toBeVisible()
  await expect(page.getByLabel('Nouveau tarif en euros')).toHaveCount(0)
  await expect(
    page.getByRole('heading', {
      name: 'Statistiques des réservations en ligne',
    }),
  ).toHaveCount(0)
})

test('la direction modifie le tarif avec un jeton CSRF et consulte les statistiques', async ({
  page,
}) => {
  await page.route('**/api/admin/settings/tariff', (route) => {
    expect(route.request().headers()['x-csrf-token']).toBe('csrf-test')
    expect(route.request().postDataJSON()).toEqual({ price: '10,50' })
    return route.fulfill({
      json: {
        ticketPriceCents: 1050,
        message: 'Le tarif est modifié pour les nouvelles réservations.',
      },
    })
  })
  await page.route('**/api/admin/statistics?*', (route) => {
    expect(new URL(route.request().url()).searchParams.get('from')).toBe(
      '2030-01-01',
    )
    return route.fulfill({
      json: {
        totals: {
          confirmedReservations: 1,
          cancelledReservations: 0,
          bookedSeats: 86,
          occupancyRate: 47.8,
        },
        screenings: [
          {
            id: 1,
            filmTitle: booking.filmTitle,
            startsAt: booking.startsAt,
            room: booking.room,
            capacity: 180,
            bookedSeats: 86,
            occupancyRate: 47.8,
            confirmedReservations: 1,
            cancelledReservations: 0,
          },
        ],
      },
    })
  })
  await page.goto('/admin')
  await page.getByLabel('Nouveau tarif en euros').fill('10,50')
  await page.getByRole('button', { name: 'Enregistrer le tarif' }).click()
  await expect(page.getByRole('status')).toContainText('Le tarif est modifié')
  await page.getByLabel('Date de séance du').fill('2030-01-01')
  await page.getByLabel('Au', { exact: true }).fill('2030-01-01')
  await page.getByRole('button', { name: 'Afficher les statistiques' }).click()
  await expect(
    page.getByText('86 sièges réservés sur 180 · 47,8 %'),
  ).toBeVisible()
})

test('une recherche sans résultat garde un message clair', async ({ page }) => {
  await page.route('**/api/admin/reservations?*', (route) =>
    route.fulfill({ json: { reservations: [], page: 1, hasMore: false } }),
  )
  await page.goto('/admin')
  await page
    .getByLabel('Numéro de réservation ou adresse mail')
    .fill('inconnu@example.com')
  await page.getByRole('button', { name: 'Rechercher', exact: true }).click()
  await expect(page.getByText('Aucune réservation trouvée.')).toBeVisible()
})
