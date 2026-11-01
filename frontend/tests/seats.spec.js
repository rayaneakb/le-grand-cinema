import { expect, test } from '@playwright/test'

const plan = {
  screening: {
    id: 1,
    filmTitle: 'Film de test',
    startsAt: '2030-06-01T18:00:00Z',
    room: 'Salle 1',
  },
  unitPriceCents: 1050,
  seats: Array.from({ length: 300 }, (_, index) => ({
    id: index + 1,
    row: String.fromCharCode(65 + Math.floor(index / 20)),
    number: (index % 20) + 1,
    available: index !== 1,
  })),
}

test.beforeEach(async ({ page }) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: { user: null } }),
  )
})

test('sélection, désélection et total, avec un siège réservé désactivé', async ({
  page,
}) => {
  await page.route('**/api/screenings/1/seats', (route) =>
    route.fulfill({ json: plan }),
  )
  await page.goto('/seances/1/sieges')
  await expect(
    page.getByRole('button', { name: 'Siège A2, réservé', exact: true }),
  ).toBeDisabled()
  const first = page.getByRole('button', { name: 'Siège A1', exact: true })
  await first.focus()
  await first.press('Enter')
  await expect(first).toHaveAttribute('aria-pressed', 'true')
  await page.getByRole('button', { name: 'Siège A3', exact: true }).click()
  const summary = page.getByRole('region', { name: 'Votre sélection' })
  await expect(summary).toContainText('2 places · A1, A3')
  await expect(summary).toContainText('21,00')
  await first.click()
  await expect(first).toHaveAttribute('aria-pressed', 'false')
  await expect(summary).toContainText('1 place · A3')
  await expect(summary).toContainText('10,50')
  await page.screenshot({
    path: '/tmp/cinema-salle-ordinateur.png',
    fullPage: true,
  })
})

test('une séance annulée affiche un message et aucun siège', async ({
  page,
}) => {
  await page.route('**/api/screenings/1/seats', (route) =>
    route.fulfill({
      status: 410,
      json: { message: 'Cette séance n’est plus disponible à la réservation.' },
    }),
  )
  await page.goto('/seances/1/sieges')
  await expect(page.getByRole('alert')).toContainText('plus disponible')
  await expect(page.locator('.seat-button')).toHaveCount(0)
})

test('la vue d’ensemble tient sur mobile et le zoom conserve les sièges', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.route('**/api/screenings/1/seats', (route) =>
    route.fulfill({ json: plan }),
  )
  await page.goto('/seances/1/sieges')
  const roomPlan = page.getByRole('region', { name: 'Plan de la salle' })
  await expect
    .poll(() =>
      roomPlan.evaluate(
        (element) => element.scrollWidth <= element.clientWidth,
      ),
    )
    .toBe(true)
  await page.screenshot({
    path: '/tmp/cinema-salle-vue-ensemble.png',
    fullPage: true,
  })
  await page.getByRole('button', { name: 'Agrandir le plan' }).click()
  await page.getByRole('button', { name: 'Siège A20', exact: true }).click()
  await page.getByRole('button', { name: 'Vue d’ensemble' }).click()
  await expect
    .poll(() =>
      roomPlan.evaluate(
        (element) => element.scrollWidth <= element.clientWidth,
      ),
    )
    .toBe(true)
  await expect(
    page.getByRole('region', { name: 'Votre sélection' }),
  ).toContainText('A20')
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true)
  await page.screenshot({
    path: '/tmp/cinema-sieges-mobile.png',
    fullPage: true,
  })
})

test('le bouton agrandit réellement les fauteuils sur ordinateur', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 900 })
  await page.route('**/api/screenings/1/seats', (route) =>
    route.fulfill({ json: plan }),
  )
  await page.goto('/seances/1/sieges')
  const seat = page.getByRole('button', { name: 'Siège A1', exact: true })
  await seat.waitFor()
  const positions = await page
    .getByRole('group', { name: 'Rangée A', exact: true })
    .getByRole('button')
    .evaluateAll((elements) =>
      elements.map((element) => {
        const box = element.getBoundingClientRect()
        return { left: box.left, right: box.right }
      }),
    )
  const normalGap = positions[4].left - positions[3].right
  expect(positions[5].left - positions[4].right).toBeGreaterThan(normalGap + 20)
  expect(positions[15].left - positions[14].right).toBeGreaterThan(
    normalGap + 20,
  )
  await page.screenshot({
    path: '/tmp/cinema-salle-trois-blocs.png',
    fullPage: true,
  })
  const before = await seat.boundingBox()
  await page.getByRole('button', { name: 'Agrandir le plan' }).click()
  const after = await seat.boundingBox()
  expect(after.width).toBeGreaterThan(before.width * 1.3)
  await page.getByRole('button', { name: 'Vue d’ensemble' }).click()
  const restored = await seat.boundingBox()
  expect(restored.width).toBeCloseTo(before.width, 0)
})

test('le parcours conserve les coordonnées et les places lors des modifications', async ({
  page,
}) => {
  await page.route('**/api/screenings/1/seats', (route) =>
    route.fulfill({ json: plan }),
  )
  await page.goto('/seances/1/sieges')
  await page.getByRole('button', { name: 'Continuer', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('sélectionner')
  await page.getByRole('button', { name: 'Siège A1', exact: true }).click()
  await page.getByRole('button', { name: 'Continuer', exact: true }).click()
  await page.getByLabel('Prénom', { exact: true }).fill('Élodie')
  await page.getByLabel('Nom', { exact: true }).fill('Martin')
  await page
    .getByLabel('Adresse mail', { exact: true })
    .fill('elodie@example.com')
  await page.getByRole('button', { name: 'Voir le récapitulatif' }).click()
  const recap = page.getByRole('region', {
    name: 'Récapitulatif de réservation',
  })
  await expect(recap).toContainText('Élodie')
  await expect(recap).toContainText('Martin')
  await expect(recap).toContainText('elodie@example.com')
  await expect(recap).toContainText('1 place · A1')
  await page.getByRole('button', { name: 'Modifier mes sièges' }).click()
  await expect(
    page.getByRole('button', { name: 'Siège A1', exact: true }),
  ).toHaveAttribute('aria-pressed', 'true')
  await page.getByRole('button', { name: 'Siège A3', exact: true }).click()
  await page.getByRole('button', { name: 'Continuer', exact: true }).click()
  await expect(page.getByLabel('Nom', { exact: true })).toHaveValue('Martin')
  await page.getByRole('button', { name: 'Voir le récapitulatif' }).click()
  await expect(recap).toContainText('2 places · A1, A3')
  await expect(recap).toContainText('21,00')
  await page.getByRole('button', { name: 'Modifier mes coordonnées' }).click()
  await page.getByLabel('Nom', { exact: true }).fill('Dupont')
  await page.getByRole('button', { name: 'Voir le récapitulatif' }).click()
  await expect(recap).toContainText('Dupont')
  await expect(recap).not.toContainText('Martin')
})

test('le compte préremplit les coordonnées et un nom vide est refusé', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.route('**/api/session', (route) =>
    route.fulfill({
      json: {
        user: {
          id: 1,
          firstName: 'Rayane',
          lastName: 'Akbly',
          email: 'rayane@example.com',
        },
      },
    }),
  )
  await page.route('**/api/screenings/1/seats', (route) =>
    route.fulfill({ json: plan }),
  )
  await page.goto('/seances/1/sieges')
  await page.getByRole('button', { name: 'Siège A1', exact: true }).click()
  await page.getByRole('button', { name: 'Continuer', exact: true }).click()
  await expect(page.getByLabel('Prénom', { exact: true })).toHaveValue('Rayane')
  await expect(page.getByLabel('Nom', { exact: true })).toHaveValue('Akbly')
  await page.getByLabel('Nom', { exact: true }).fill('   ')
  await page.getByRole('button', { name: 'Voir le récapitulatif' }).click()
  await expect(page.getByRole('alert')).toContainText('votre nom')
  await page.getByLabel('Nom', { exact: true }).fill('Akbly')
  await page.getByRole('button', { name: 'Voir le récapitulatif' }).click()
  await expect(page.getByRole('heading', { level: 1 })).toBeFocused()
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true)
  await page.screenshot({
    path: '/tmp/cinema-recapitulatif-mobile.png',
    fullPage: true,
  })
})

test('le retour sur une page restaurée actualise la session sans écraser la saisie', async ({
  page,
}) => {
  let loggedIn = false
  await page.route('**/api/session', (route) =>
    route.fulfill({
      json: {
        user: loggedIn
          ? {
              id: 1,
              firstName: 'Élodie',
              lastName: 'Martin',
              email: 'elodie@example.com',
            }
          : null,
      },
    }),
  )
  await page.route('**/api/screenings/1/seats', (route) =>
    route.fulfill({ json: plan }),
  )
  await page.goto('/seances/1/sieges')
  await page.getByRole('button', { name: 'Siège A1', exact: true }).click()
  await page.getByRole('button', { name: 'Continuer', exact: true }).click()
  await page.getByLabel('Nom', { exact: true }).fill('Dupont')
  loggedIn = true
  await page.evaluate(() =>
    window.dispatchEvent(
      new PageTransitionEvent('pageshow', { persisted: true }),
    ),
  )
  await expect(page.getByLabel('Prénom', { exact: true })).toHaveValue('Élodie')
  await expect(page.getByLabel('Adresse mail', { exact: true })).toHaveValue(
    'elodie@example.com',
  )
  await expect(page.getByLabel('Nom', { exact: true })).toHaveValue('Dupont')
  await page.getByRole('button', { name: 'Voir le récapitulatif' }).click()
  await expect(
    page.getByRole('region', { name: 'Récapitulatif de réservation' }),
  ).toContainText('1 place · A1')
})

for (const initialStep of ['seats', 'contact', 'review']) {
  test(`la connexion revient à l’étape ${initialStep} avec la sélection conservée`, async ({
    page,
  }) => {
    let loggedIn = false
    await page.route('**/api/session', (route) =>
      route.fulfill({
        json: {
          user: loggedIn
            ? {
                id: 1,
                firstName: 'Élodie',
                lastName: 'Martin',
                email: 'elodie@example.com',
              }
            : null,
        },
      }),
    )
    await page.route('**/api/csrf', (route) =>
      route.fulfill({ json: { csrfToken: 'test-csrf' } }),
    )
    await page.route('**/api/login', (route) => {
      loggedIn = true
      return route.fulfill({ json: { status: 'authenticated' } })
    })
    await page.route('**/api/screenings/1/seats', (route) =>
      route.fulfill({ json: plan }),
    )
    await page.goto('/seances/1/sieges')
    await page.getByRole('button', { name: 'Siège A1', exact: true }).click()
    if (initialStep !== 'seats') {
      await page.getByRole('button', { name: 'Continuer', exact: true }).click()
      await page.getByLabel('Nom', { exact: true }).fill('Dupont')
    }
    if (initialStep === 'review') {
      await page.getByLabel('Prénom', { exact: true }).fill('Lucie')
      await page
        .getByLabel('Adresse mail', { exact: true })
        .fill('lucie@example.com')
      await page.getByRole('button', { name: 'Voir le récapitulatif' }).click()
    }
    await page.getByRole('button', { name: 'Mon compte', exact: true }).click()
    await page.getByRole('link', { name: 'Se connecter', exact: true }).click()
    await expect(page).toHaveURL(/connexion\?retour=/)
    await page
      .getByLabel('Adresse mail', { exact: true })
      .fill('elodie@example.com')
    await page
      .getByLabel('Mot de passe', { exact: true })
      .fill('mot de passe de test')
    await page
      .getByRole('button', { name: 'Me connecter', exact: true })
      .click()
    await expect(page).toHaveURL(/\/seances\/1\/sieges$/)
    if (initialStep === 'seats') {
      await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'Choisissez vos sièges',
      )
      await expect(
        page.getByRole('button', { name: 'Siège A1', exact: true }),
      ).toHaveAttribute('aria-pressed', 'true')
    } else if (initialStep === 'contact') {
      await expect(page.getByLabel('Nom', { exact: true })).toHaveValue(
        'Dupont',
      )
      await expect(page.getByLabel('Prénom', { exact: true })).toHaveValue(
        'Élodie',
      )
    } else {
      const recap = page.getByRole('region', {
        name: 'Récapitulatif de réservation',
      })
      await expect(recap).toContainText('Lucie')
      await expect(recap).toContainText('Dupont')
      await expect(recap).toContainText('lucie@example.com')
      await expect(recap).toContainText('1 place · A1')
    }
  })
}

test('une place prise pendant la connexion est retirée sans perdre les autres', async ({
  page,
}) => {
  let afterLogin = false
  await page.route('**/api/csrf', (route) =>
    route.fulfill({ json: { csrfToken: 'test' } }),
  )
  await page.route('**/api/login', (route) => {
    afterLogin = true
    return route.fulfill({ json: { status: 'authenticated' } })
  })
  await page.route('**/api/screenings/1/seats', (route) =>
    route.fulfill({
      json: {
        ...plan,
        seats: plan.seats.map((seat) =>
          afterLogin && seat.id === 1 ? { ...seat, available: false } : seat,
        ),
      },
    }),
  )
  await page.goto('/seances/1/sieges')
  await page.getByRole('button', { name: 'Siège A1', exact: true }).click()
  await page.getByRole('button', { name: 'Siège A3', exact: true }).click()
  await page.getByRole('button', { name: 'Continuer', exact: true }).click()
  await page.getByRole('button', { name: 'Mon compte', exact: true }).click()
  await page.getByRole('link', { name: 'Se connecter', exact: true }).click()
  await page
    .getByLabel('Adresse mail', { exact: true })
    .fill('client@example.com')
  await page
    .getByLabel('Mot de passe', { exact: true })
    .fill('mot de passe de test')
  await page.getByRole('button', { name: 'Me connecter', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('plus disponibles')
  await expect(
    page.getByRole('button', { name: 'Siège A1, réservé', exact: true }),
  ).toBeDisabled()
  await expect(
    page.getByRole('button', { name: 'Siège A3', exact: true }),
  ).toHaveAttribute('aria-pressed', 'true')
})

async function openReview(page) {
  await page.route('**/api/csrf', (route) =>
    route.fulfill({ json: { csrfToken: 'csrf-test' } }),
  )
  await page.route('**/api/screenings/1/seats', (route) =>
    route.fulfill({ json: plan }),
  )
  await page.goto('/seances/1/sieges')
  await page.getByRole('button', { name: 'Siège A1', exact: true }).click()
  await page.getByRole('button', { name: 'Siège A3', exact: true }).click()
  await page.getByRole('button', { name: 'Continuer', exact: true }).click()
  await page.getByLabel('Prénom', { exact: true }).fill('Élodie')
  await page.getByLabel('Nom', { exact: true }).fill('Martin')
  await page
    .getByLabel('Adresse mail', { exact: true })
    .fill('elodie@example.com')
  await page.getByRole('button', { name: 'Voir le récapitulatif' }).click()
}

function confirmedBooking(reference = 'TEST-RESERVATION') {
  return {
    status: 'confirmed',
    emailSent: true,
    emailRetryAfter: 0,
    replayed: false,
    reservation: {
      reference,
      firstName: 'Élodie',
      lastName: 'Martin',
      email: 'elodie@example.com',
      filmTitle: 'Film de test',
      room: 'Salle 1',
      startsAt: plan.screening.startsAt,
      seats: ['A1', 'A3'],
      unitPriceCents: 1050,
      totalAmountCents: 2100,
    },
  }
}

test('la confirmation affiche uniquement la référence et le total renvoyés par Symfony', async ({
  page,
}) => {
  await openReview(page)
  await page.route('**/api/reservations', (route) => {
    const payload = route.request().postDataJSON()
    expect(route.request().headers()['x-csrf-token']).toBe('csrf-test')
    expect(payload.seatIds).toEqual([1, 3])
    expect(payload.expectedUnitPriceCents).toBe(1050)
    expect(payload.totalAmountCents).toBeUndefined()
    expect(payload.confirmationKey).toMatch(/^[0-9a-f-]{36}$/)
    return route.fulfill({ status: 201, json: confirmedBooking() })
  })
  await page
    .getByRole('button', { name: 'Confirmer ma réservation', exact: true })
    .click()
  const confirmation = page.getByRole('region', {
    name: 'Réservation confirmée',
  })
  await expect(confirmation).toContainText('TEST-RESERVATION')
  await expect(confirmation).toContainText('21,00')
  await expect(
    page.getByRole('button', { name: 'Confirmer ma réservation', exact: true }),
  ).toHaveCount(0)
})

test('un conflit conserve les sièges libres et les coordonnées', async ({
  page,
}) => {
  await openReview(page)
  await page.route('**/api/screenings/1/seats', (route) =>
    route.fulfill({
      json: {
        ...plan,
        seats: plan.seats.map((seat) =>
          seat.id === 1 ? { ...seat, available: false } : seat,
        ),
      },
    }),
  )
  await page.route('**/api/reservations', (route) =>
    route.fulfill({
      status: 409,
      json: {
        status: 'seats_unavailable',
        unavailableSeatIds: [1],
        message: 'Le siège A1 vient d’être réservé.',
      },
    }),
  )
  await page
    .getByRole('button', { name: 'Confirmer ma réservation', exact: true })
    .click()
  await expect(page.getByRole('alert')).toContainText('A1')
  await expect(
    page.getByRole('button', { name: 'Siège A1, réservé', exact: true }),
  ).toBeDisabled()
  await expect(
    page.getByRole('button', { name: 'Siège A3', exact: true }),
  ).toHaveAttribute('aria-pressed', 'true')
  await page.getByRole('button', { name: 'Continuer', exact: true }).click()
  await expect(page.getByLabel('Nom', { exact: true })).toHaveValue('Martin')
})

test('une réponse perdue se vérifie avec la même clé même après rechargement', async ({
  page,
}) => {
  await openReview(page)
  let attempts = 0
  let originalKey
  await page.route('**/api/reservations', (route) => {
    attempts++
    const key = route.request().postDataJSON().confirmationKey
    if (attempts === 1) {
      originalKey = key
      return route.abort()
    }
    expect(key).toBe(originalKey)
    return route.fulfill({ json: { ...confirmedBooking(), replayed: true } })
  })
  await page
    .getByRole('button', { name: 'Confirmer ma réservation', exact: true })
    .click()
  await expect(
    page.getByRole('button', { name: 'Vérifier ma réservation', exact: true }),
  ).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Modifier mes sièges', exact: true }),
  ).toBeDisabled()
  await page.reload()
  await page
    .getByRole('button', { name: 'Vérifier ma réservation', exact: true })
    .click()
  await expect(
    page.getByRole('region', { name: 'Réservation confirmée' }),
  ).toContainText('TEST-RESERVATION')
  expect(attempts).toBe(2)
})

test('un tarif modifié exige une nouvelle confirmation du client', async ({
  page,
}) => {
  await openReview(page)
  let attempts = 0
  await page.route('**/api/reservations', (route) => {
    attempts++
    if (attempts === 1)
      return route.fulfill({
        status: 409,
        json: {
          status: 'price_changed',
          unitPriceCents: 1200,
          message: 'Le tarif a changé. Vérifiez le nouveau total.',
        },
      })
    expect(route.request().postDataJSON().expectedUnitPriceCents).toBe(1200)
    const response = confirmedBooking()
    response.reservation.totalAmountCents = 2400
    return route.fulfill({ status: 201, json: response })
  })
  await page
    .getByRole('button', { name: 'Confirmer ma réservation', exact: true })
    .click()
  await expect(page.getByRole('alert')).toContainText('tarif a changé')
  await expect(
    page.getByRole('region', { name: 'Récapitulatif de réservation' }),
  ).toContainText('24,00')
  expect(attempts).toBe(1)
  await page
    .getByRole('button', { name: 'Confirmer ma réservation', exact: true })
    .click()
  await expect(
    page.getByRole('region', { name: 'Réservation confirmée' }),
  ).toContainText('24,00')
})

test('un échec du mail conserve la confirmation et permet un nouvel envoi sans doublon', async ({
  page,
}) => {
  await page.clock.install()
  await openReview(page)
  let requests = 0
  let key
  await page.route('**/api/reservations', (route) => {
    requests++
    const payload = route.request().postDataJSON()
    if (requests === 1) {
      key = payload.confirmationKey
      return route.fulfill({
        status: 201,
        json: { ...confirmedBooking(), emailSent: false, emailRetryAfter: 2 },
      })
    }
    expect(payload.confirmationKey).toBe(key)
    return route.fulfill({ json: { ...confirmedBooking(), replayed: true } })
  })
  await page
    .getByRole('button', { name: 'Confirmer ma réservation', exact: true })
    .click()
  const confirmation = page.getByRole('region', {
    name: 'Réservation confirmée',
  })
  await expect(confirmation).toContainText('TEST-RESERVATION')
  await expect(confirmation).toContainText('mail n’a pas pu être envoyé')
  const retry = page.getByRole('button', { name: 'Réessayer l’envoi du mail' })
  await expect(retry).toBeDisabled()
  await page.clock.runFor(3000)
  await expect(retry).toBeEnabled()
  await retry.click()
  await expect(confirmation).toContainText(
    'Un mail de confirmation a été envoyé à elodie@example.com',
  )
  expect(requests).toBe(2)
})
