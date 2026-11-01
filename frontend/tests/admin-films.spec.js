import { expect, test } from '@playwright/test'

const film = {
  id: 12,
  title: 'Film de test',
  synopsis: 'Une histoire.',
  durationMinutes: 100,
  mainActors: 'Actrice de test',
  posterUrl: '/affiche.png',
  isShowing: true,
}

test.beforeEach(async ({ page }) => {
  await page.route('**/api/admin/screenings?*', (route) =>
    route.fulfill({ json: { screenings: [], films: [], rooms: [] } }),
  )
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
    route.fulfill({ json: { csrfToken: 'test-token' } }),
  )
  await page.route('**/api/admin/settings', (route) =>
    route.fulfill({ json: { ticketPriceCents: 1000 } }),
  )
  await page.route('**/api/admin/films', (route) =>
    route.fulfill({ json: { films: [film] } }),
  )
})

test('le formulaire conserve les saisies après un refus et permet de corriger', async ({
  page,
}) => {
  let attempts = 0
  await page.route('**/api/admin/films/12', (route) => {
    expect(route.request().headers()['x-csrf-token']).toBe('test-token')
    expect(route.request().headers()['content-type']).toContain(
      'multipart/form-data',
    )
    expect(route.request().postData()).toContain('Titre corrigé')
    attempts++
    return route.fulfill(
      attempts === 1
        ? {
            status: 409,
            json: {
              message:
                'Cette durée ne laisse plus une heure entre deux séances.',
            },
          }
        : {
            json: {
              film: { ...film, title: 'Titre corrigé' },
              message: 'Le film est enregistré.',
            },
          },
    )
  })
  await page.goto('/admin')
  await page
    .getByRole('button', { name: 'Modifier le film', exact: true })
    .click()
  await expect(page.getByLabel('Titre', { exact: true })).toHaveValue(
    film.title,
  )
  await page.getByLabel('Titre', { exact: true }).fill('Titre corrigé')
  await page.getByRole('button', { name: 'Enregistrer le film' }).click()
  await expect(page.getByRole('alert')).toContainText('une heure')
  await expect(page.getByLabel('Titre', { exact: true })).toHaveValue(
    'Titre corrigé',
  )
  await page.getByRole('button', { name: 'Enregistrer le film' }).click()
  await expect(
    page.getByText('Le film est enregistré.', { exact: true }),
  ).toBeVisible()
  expect(attempts).toBe(2)
})

test('un fichier trop lourd est refusé avant son envoi', async ({ page }) => {
  await page.goto('/admin')
  await page
    .getByRole('button', { name: 'Ajouter un film', exact: true })
    .click()
  await page.getByLabel('Affiche', { exact: true }).setInputFiles({
    name: 'affiche.png',
    mimeType: 'image/png',
    buffer: Buffer.alloc(5 * 1024 * 1024 + 1),
  })
  await expect(page.getByRole('alert')).toContainText('5 Mo maximum')
  await expect(page.getByLabel('Affiche', { exact: true })).toHaveValue('')
})

test('la modale garde le focus et Échap revient au bouton d’ouverture', async ({
  page,
}) => {
  await page.goto('/admin')
  const trigger = page.getByRole('button', {
    name: 'Ajouter un film',
    exact: true,
  })
  await trigger.click()
  const dialog = page.getByRole('dialog', { name: 'Ajouter un film' })
  await expect(dialog).toBeVisible()
  await expect(dialog.getByRole('button', { name: 'Fermer' })).toBeFocused()
  await page.keyboard.press('Shift+Tab')
  expect(
    await dialog.evaluate((element) =>
      element.contains(document.activeElement),
    ),
  ).toBe(true)
  await page.keyboard.press('Tab')
  await expect(dialog.getByRole('button', { name: 'Fermer' })).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
  await expect(trigger).toBeFocused()
})

test('le formulaire reste dans la largeur du téléphone et peut défiler', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/admin')
  await page
    .getByRole('button', { name: 'Modifier le film', exact: true })
    .click()
  const dialog = page.getByRole('dialog', { name: 'Modifier le film' })
  const box = await dialog.boundingBox()
  expect(box.x).toBeGreaterThanOrEqual(0)
  expect(box.x + box.width).toBeLessThanOrEqual(390)
  expect(
    await dialog.evaluate(
      (element) => element.scrollWidth <= element.clientWidth,
    ),
  ).toBe(true)
  await dialog
    .getByRole('button', { name: 'Enregistrer le film' })
    .scrollIntoViewIfNeeded()
  await expect(
    dialog.getByRole('button', { name: 'Enregistrer le film' }),
  ).toBeInViewport()
  await page.screenshot({ path: '/tmp/cinema-modale-mobile.png' })
  await page.keyboard.press('Escape')
  await page.setViewportSize({ width: 1280, height: 900 })
  await page.getByRole('link', { name: 'Films', exact: true }).click()
  await page.screenshot({ path: '/tmp/cinema-administration.png' })
})
