import { expect, test } from '@playwright/test'

test.beforeEach(async ({ page }) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: { user: null } }),
  )
})

const emptyMessage = 'Aucun film à l’affiche pour le moment.'
const errorMessage =
  'La programmation est indisponible pour le moment. Veuillez réessayer.'
const film = {
  id: 1,
  title: 'Film de test',
  synopsis: 'Un synopsis pour vérifier la présentation du film.',
  durationMinutes: 110,
  mainActors: 'Actrice de test',
  posterUrl: '/test-poster.svg',
  screenings: [],
}

test.beforeEach(async ({ page }) => {
  await page.clock.install({ time: new Date('2030-06-01T10:00:00Z') })
  await page.route('**/test-poster.svg', (route) =>
    route.fulfill({
      contentType: 'image/svg+xml',
      body: '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="300"><rect width="200" height="300" fill="#28544c"/></svg>',
    }),
  )
})

test('une liste vide affiche le message prévu sans erreur', async ({
  page,
}) => {
  await page.route('**/api/films', (route) =>
    route.fulfill({ json: { films: [] } }),
  )
  await page.goto('/')
  await expect(page.getByText(emptyMessage, { exact: true })).toBeVisible()
  await expect(page.getByRole('alert')).toHaveCount(0)
  await expect(page.getByRole('article')).toHaveCount(0)
})

test('une panne affiche une erreur et ne prétend pas que la programmation est vide', async ({
  page,
}) => {
  await page.route('**/api/films', (route) =>
    route.fulfill({ status: 503, json: { error: 'Unavailable' } }),
  )
  await page.goto('/')
  await expect(page.getByRole('alert')).toContainText(errorMessage)
  await expect(page.getByRole('button', { name: 'Réessayer' })).toBeVisible()
  await expect(page.getByText(emptyMessage, { exact: true })).toHaveCount(0)
})

test('le chargement reste visible en attendant la réponse', async ({
  page,
}) => {
  let releaseResponse
  const pendingResponse = new Promise((resolve) => {
    releaseResponse = resolve
  })
  await page.route('**/api/films', async (route) => {
    await pendingResponse
    await route.fulfill({ json: { films: [] } })
  })
  await page.goto('/')
  await expect(page.getByRole('status')).toHaveText(
    'Chargement de la programmation…',
  )
  await expect(page.getByText(emptyMessage, { exact: true })).toHaveCount(0)
  releaseResponse()
  await expect(page.getByText(emptyMessage, { exact: true })).toBeVisible()
})

test('Réessayer recharge la programmation après une panne', async ({
  page,
}) => {
  let unavailable = true
  await page.route('**/api/films', (route) =>
    unavailable
      ? route.fulfill({ status: 503, json: { error: 'Unavailable' } })
      : route.fulfill({ json: { films: [film] } }),
  )
  await page.goto('/')
  await expect(page.getByRole('alert')).toBeVisible()
  unavailable = false
  await page.getByRole('button', { name: 'Réessayer' }).click()
  await expect(page.getByRole('heading', { name: film.title })).toBeVisible()
  await expect(page.getByRole('alert')).toHaveCount(0)
})

test('une séance complète est visible et désactivée, les jours filtrent les séances', async ({
  page,
}) => {
  await page.route('**/api/films', (route) =>
    route.fulfill({
      json: {
        films: [
          {
            ...film,
            screenings: [
              {
                id: 1,
                startsAt: '2030-06-01T12:00:00Z',
                room: 'Salle 1',
                full: true,
                availableSeats: 0,
              },
              {
                id: 2,
                startsAt: '2030-06-02T12:00:00Z',
                room: 'Salle 1',
                full: false,
                availableSeats: 1,
              },
            ],
          },
        ],
      },
    }),
  )
  await page.goto('/')
  const fullScreening = page.getByRole('button', {
    name: '14:00, Salle 1, complet',
    exact: true,
  })
  await expect(fullScreening).toBeVisible()
  await expect(fullScreening).toBeDisabled()
  await page
    .getByRole('group', { name: 'Choisir le jour des séances' })
    .getByRole('button')
    .nth(1)
    .click()
  await expect(fullScreening).toHaveCount(0)
  await expect(
    page.getByRole('button', { name: '14:00, Salle 1', exact: true }),
  ).toBeEnabled()
})

test('la carte reste lisible sur un écran de 390 pixels sans débordement de page', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.route('**/api/films', (route) =>
    route.fulfill({ json: { films: [film] } }),
  )
  await page.goto('/')
  await expect(page.getByRole('heading', { name: film.title })).toBeVisible()
  await expect(
    page.getByText('Aucune séance disponible pour le moment.', { exact: true }),
  ).toBeVisible()
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true)
  await page.screenshot({
    path: 'test-results/programming-mobile.png',
    fullPage: true,
  })
})
