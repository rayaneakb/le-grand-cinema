import { expect, test } from '@playwright/test'

const reference = 'ABCDEF0123456789'
const booking = {
  reference,
  filmTitle: 'Au-delà des étoiles',
  startsAt: '2030-01-01T19:00:00Z',
  room: 'Salle 2',
  seats: ['D10', 'D11'],
  status: 'confirmed',
  totalAmountCents: 2000,
}

test.beforeEach(async ({ page }) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({
      json: {
        user: {
          id: 1,
          firstName: 'Rayane',
          lastName: 'Akbly',
          email: 'rayane@example.com',
          roles: ['ROLE_USER'],
        },
      },
    }),
  )
})

test('le compte présente les réservations et ouvre leur détail', async ({
  page,
}) => {
  await page.route('**/api/account/reservations?*', (route) =>
    route.fulfill({ json: { reservations: [booking], hasMore: false } }),
  )
  await page.route(`**/api/reservations/${reference}`, (route) =>
    route.fulfill({
      json: {
        reservation: {
          ...booking,
          firstName: 'Rayane',
          lastName: 'Akbly',
          email: 'rayane@example.com',
          canCancel: true,
          cancellationDeadline: '2030-01-01T17:00:00Z',
        },
      },
    }),
  )
  await page.goto('/compte')
  await expect(
    page.getByRole('heading', { name: 'Mes réservations' }),
  ).toBeVisible()
  await expect(page.getByText('Sièges D10, D11')).toBeVisible()
  await expect(
    page.getByText(`Référence ${reference} · Confirmée`),
  ).toBeVisible()
  await page.getByRole('link', { name: 'Voir ma réservation' }).click()
  await expect(page).toHaveURL(new RegExp(`/reservations/${reference}$`))
  await expect(
    page.getByRole('button', { name: 'Annuler ma réservation', exact: true }),
  ).toBeVisible()
})

test('une panne peut être retentée et une liste vide est expliquée', async ({
  page,
}) => {
  let available = false
  await page.route('**/api/account/reservations?*', (route) =>
    !available
      ? route.fulfill({ status: 503, json: { message: 'Indisponible' } })
      : route.fulfill({ json: { reservations: [], hasMore: false } }),
  )
  await page.goto('/compte')
  await expect(page.getByRole('alert')).toContainText(
    'Impossible de charger vos réservations',
  )
  available = true
  await page.getByRole('button', { name: 'Réessayer' }).click()
  await expect(
    page.getByText('Vous n’avez pas encore de réservation.'),
  ).toBeVisible()
  await expect(page.getByRole('alert')).toHaveCount(0)
})

test('la page suivante montre les anciennes réservations annulées', async ({
  page,
}) => {
  await page.route('**/api/account/reservations?*', (route) => {
    const second =
      new URL(route.request().url()).searchParams.get('page') === '2'
    return route.fulfill({
      json: {
        reservations: [
          { ...booking, status: second ? 'cancelled' : 'confirmed' },
        ],
        hasMore: !second,
      },
    })
  })
  await page.goto('/compte')
  await expect(
    page.getByRole('button', { name: 'Page précédente' }),
  ).toBeDisabled()
  await page.getByRole('button', { name: 'Page suivante' }).click()
  await expect(page.getByText(`Référence ${reference} · Annulée`)).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Page suivante' }),
  ).toBeDisabled()
  await page.getByRole('button', { name: 'Page précédente' }).click()
  await expect(
    page.getByText(`Référence ${reference} · Confirmée`),
  ).toBeVisible()
})
