import { expect, test } from '@playwright/test'

test.use({ locale: 'fr-FR' })

const reference = 'ABCDEF0123456789'
const title =
  'Le voyage extraordinaire des explorateurs au-delà des étoiles et des océans oubliés'
const user = {
  id: 1,
  firstName: 'Alexandrine-Marie',
  lastName: 'De La Roche Saint-André',
  email: `${'adresse'.repeat(7)}@exemple.fr`,
  roles: ['ROLE_DIRECTOR'],
}
const film = {
  id: 1,
  title,
  synopsis: 'Une équipe part à la découverte d’un monde inconnu. '.repeat(12),
  durationMinutes: 178,
  mainActors:
    'Camille Dupont, Alexandre Martin, Élodie Bernard et Jean-Christophe Laurent',
  posterUrl: '/responsive-poster.svg',
  isShowing: true,
  screenings: [
    { id: 1, startsAt: '2035-06-01T18:00:00Z', room: 'Salle 1', full: false },
  ],
}
const screening = {
  id: 1,
  filmId: 1,
  filmTitle: title,
  roomId: 1,
  room: 'Salle 1',
  startsAt: '2035-06-01T18:00:00Z',
  endsAt: '2035-06-01T20:58:00Z',
  status: 'scheduled',
  canEdit: true,
  reservationCount: 1,
  pendingEmails: 0,
}
const booking = {
  reference,
  ...user,
  filmTitle: title,
  room: 'Salle 1',
  startsAt: screening.startsAt,
  cancellationDeadline: '2035-06-01T16:00:00Z',
  seats: ['A1', 'A2'],
  totalAmountCents: 2000,
  status: 'confirmed',
  canCancel: true,
}

async function noOverflow(page, context) {
  await expect
    .poll(
      () =>
        page.evaluate(
          () => document.documentElement.scrollWidth - window.innerWidth,
        ),
      { message: context },
    )
    .toBeLessThanOrEqual(1)
}

for (const width of [320, 390, 768, 1440]) {
  test(`les pages et les formulaires restent utilisables à ${width} px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 })
    await page.clock.install({ time: new Date('2035-06-01T10:00:00Z') })
    await page.route('**/responsive-poster.svg', (route) =>
      route.fulfill({
        contentType: 'image/svg+xml',
        body: '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="300"><rect width="200" height="300" fill="#28544c"/><text x="30" y="140" fill="white">Le voyage</text></svg>',
      }),
    )
    await page.route('**/api/**', (route) => {
      const path = new URL(route.request().url()).pathname
      const responses = {
        '/api/session': { user },
        '/api/csrf': { csrfToken: 'test-token' },
        '/api/films': { films: [film] },
        '/api/reservations': {
          status: 'confirmed',
          emailSent: true,
          reservation: {
            ...booking,
            unitPriceCents: 1000,
            managementUrl: `/reservations/${reference}?_hash=test`,
          },
        },
        '/api/account/reservations': {
          reservations: [booking],
          hasMore: false,
        },
        [`/api/reservations/${reference}`]: { reservation: booking },
        '/api/admin/films': { films: [film] },
        '/api/admin/settings': { ticketPriceCents: 1000 },
        '/api/admin/screenings': {
          screenings: [screening],
          films: [film],
          rooms: [{ id: 1, name: 'Salle 1' }],
        },
        '/api/admin/reservations': {
          reservations: [booking],
          page: 1,
          hasMore: false,
        },
        '/api/admin/statistics': {
          totals: {
            confirmedReservations: 1,
            cancelledReservations: 0,
            bookedSeats: 2,
            occupancyRate: 0.7,
          },
          screenings: [
            {
              ...screening,
              capacity: 300,
              bookedSeats: 2,
              occupancyRate: 0.7,
              confirmedReservations: 1,
              cancelledReservations: 0,
            },
          ],
        },
        '/api/screenings/1/seats': {
          screening,
          unitPriceCents: 1000,
          seats: Array.from({ length: 300 }, (_, i) => ({
            id: i + 1,
            row: String.fromCharCode(65 + Math.floor(i / 20)),
            number: (i % 20) + 1,
            available: true,
          })),
        },
        '/api/verify-email': {
          status: 'verified',
          message: 'Votre adresse mail est vérifiée.',
        },
      }
      return route.fulfill({ json: responses[path] || {} })
    })
    for (const path of [
      '/',
      '/connexion',
      '/inscription',
      '/mot-de-passe-oublie',
      '/reinitialiser-mot-de-passe?token=test',
      '/activation?id=1&signature=test',
      '/compte',
      `/reservations/${reference}`,
    ]) {
      await page.goto(path)
      await expect(page.locator('main h1')).toBeVisible()
      await noOverflow(page, path)
      if (path === '/' && width === 390)
        await page.screenshot({
          path: '/tmp/cinema-programmation-responsive.png',
          fullPage: true,
        })
      await page
        .getByRole('button', { name: 'Mon compte', exact: true })
        .click()
      await expect(
        page.getByRole('link', { name: 'Voir mon compte' }),
      ).toBeVisible()
      await noOverflow(page, `${path} avec le menu ouvert`)
      await page.keyboard.press('Escape')
      if (path === '/compte' && width === 390)
        await page.screenshot({
          path: '/tmp/cinema-compte-responsive.png',
          fullPage: true,
        })
      if (path === `/reservations/${reference}`) {
        await page
          .getByRole('button', { name: 'Annuler ma réservation', exact: true })
          .click()
        await noOverflow(page, 'confirmation d’annulation')
        await page
          .getByRole('button', { name: 'Conserver ma réservation' })
          .click()
      }
    }
    await page.goto('/seances/1/sieges')
    const seat = page.getByRole('button', { name: 'Siège A1', exact: true })
    await seat.click()
    await noOverflow(page, 'plan de salle')
    await page.getByRole('button', { name: 'Continuer', exact: true }).click()
    await noOverflow(page, 'coordonnées')
    await page.getByRole('button', { name: 'Voir le récapitulatif' }).click()
    await expect(
      page.getByRole('region', { name: 'Récapitulatif de réservation' }),
    ).toBeVisible()
    await noOverflow(page, 'récapitulatif')
    await page
      .getByRole('button', { name: 'Confirmer ma réservation', exact: true })
      .click()
    await expect(
      page.getByRole('region', { name: 'Réservation confirmée' }),
    ).toBeVisible()
    await noOverflow(page, 'confirmation de réservation')
    await page.goto('/admin')
    await page
      .getByLabel('Numéro de réservation ou adresse mail')
      .fill(user.email)
    await page.getByRole('button', { name: 'Rechercher', exact: true }).click()
    await expect(
      page.getByText('Réservation confirmée', { exact: true }),
    ).toBeVisible()
    await page
      .getByRole('button', { name: 'Afficher les statistiques' })
      .click()
    await expect(
      page.getByText('2 sièges réservés sur 300 · 0,7 %'),
    ).toBeVisible()
    await noOverflow(page, 'administration avec résultats')
    for (const name of [
      'Ajouter un film',
      'Modifier le film',
      'Nouvelle séance',
      'Modifier la séance',
    ]) {
      await page.getByRole('button', { name, exact: true }).click()
      const dialog = page.getByRole('dialog')
      await expect(dialog).toBeVisible()
      expect(
        await dialog.evaluate(
          (element) => element.scrollWidth <= element.clientWidth + 1,
        ),
        name,
      ).toBe(true)
      await noOverflow(page, name)
      await dialog.locator('button.primary-button').scrollIntoViewIfNeeded()
      await expect(dialog.locator('button.primary-button')).toBeInViewport()
      await expect(
        dialog.getByRole('button', { name: 'Fermer' }),
      ).toBeInViewport()
      if (name === 'Modifier la séance' && width === 390)
        await page.screenshot({ path: '/tmp/cinema-seance-responsive.png' })
      await dialog.getByRole('button', { name: 'Fermer' }).click()
    }
  })
}
