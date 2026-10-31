import { expect, test } from '@playwright/test'

test.beforeEach(async ({ page }) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: { user: null } }),
  )
})

async function fillRegistration(page) {
  await page.getByLabel('Prénom', { exact: true }).fill('Élodie')
  await page.getByLabel('Nom', { exact: true }).fill("O'Connor")
  await page
    .getByLabel('Adresse mail', { exact: true })
    .fill('elodie@example.com')
  await page
    .getByLabel('Mot de passe', { exact: true })
    .fill('une phrase facile à retenir')
}

test('le formulaire envoie les quatre champs et attend la validation du mail', async ({
  page,
}) => {
  let payload
  await page.route('**/api/register', async (route) => {
    payload = route.request().postDataJSON()
    await route.fulfill({
      status: 201,
      json: { status: 'pending_verification', emailSent: true },
    })
  })
  await page.goto('/inscription')
  await fillRegistration(page)
  await page.getByRole('button', { name: 'Créer mon compte' }).click()
  await expect(page.getByRole('status')).toContainText('elodie@example.com')
  await expect(
    page.getByRole('heading', { name: 'Vérifiez votre adresse mail' }),
  ).toBeVisible()
  expect(payload).toEqual({
    firstName: 'Élodie',
    lastName: "O'Connor",
    email: 'elodie@example.com',
    password: 'une phrase facile à retenir',
  })
  await expect(page.getByLabel('Mot de passe', { exact: true })).toHaveCount(0)
})

test('un mail déjà utilisé affiche l’erreur près du champ et propose un renvoi', async ({
  page,
}) => {
  await page.route('**/api/register', (route) =>
    route.fulfill({
      status: 409,
      json: {
        errors: { email: ['Un compte est déjà associé à cette adresse mail.'] },
      },
    }),
  )
  await page.goto('/inscription')
  await fillRegistration(page)
  await page.getByRole('button', { name: 'Créer mon compte' }).click()
  await expect(page.getByRole('alert')).toContainText(
    'Un compte est déjà associé',
  )
  await expect(page.locator('#email')).toHaveAttribute('aria-invalid', 'true')
  await expect(page.locator('#firstName')).toHaveValue('Élodie')
  await expect(
    page.getByRole('button', { name: 'Renvoyer le mail' }),
  ).toBeVisible()
})

test('une panne de mail conserve le succès de création et permet un renvoi', async ({
  page,
}) => {
  await page.route('**/api/register', (route) =>
    route.fulfill({
      status: 201,
      json: { status: 'pending_verification', emailSent: false },
    }),
  )
  await page.route('**/api/resend-verification', (route) =>
    route.fulfill({
      status: 202,
      json: {
        status: 'request_accepted',
        message:
          'Si un compte attend une activation à cette adresse, un nouveau mail vous sera envoyé.',
      },
    }),
  )
  await page.goto('/inscription')
  await fillRegistration(page)
  await page.getByRole('button', { name: 'Créer mon compte' }).click()
  await expect(
    page.getByText(
      'Votre compte est créé, mais le mail d’activation n’a pas pu être envoyé.',
    ),
  ).toBeVisible()
  await page.getByRole('button', { name: 'Renvoyer le mail' }).click()
  await expect(
    page
      .getByRole('form', { name: 'Renvoyer le mail d’activation' })
      .getByRole('status'),
  ).toContainText('un nouveau mail vous sera envoyé')
})

test('le bouton reste désactivé pendant une demande et aucun double envoi ne part', async ({
  page,
}) => {
  let finish
  let requests = 0
  const waiting = new Promise((resolve) => {
    finish = resolve
  })
  await page.route('**/api/register', async (route) => {
    requests += 1
    await waiting
    await route.fulfill({
      status: 201,
      json: { status: 'pending_verification', emailSent: true },
    })
  })
  await page.goto('/inscription')
  await fillRegistration(page)
  await page.getByRole('button', { name: 'Créer mon compte' }).click()
  await expect(
    page.getByRole('button', { name: 'Création en cours…' }),
  ).toBeDisabled()
  expect(requests).toBe(1)
  finish()
  await expect(
    page.getByRole('heading', { name: 'Vérifiez votre adresse mail' }),
  ).toBeVisible()
})

test('un lien valide est transmis à Symfony puis retiré de la barre d’adresse', async ({
  page,
}) => {
  await page.route('**/api/verify-email?**', (route) => {
    expect(route.request().url()).toContain('id=3&_hash=signature-test')
    return route.fulfill({
      json: { status: 'verified', message: 'Votre adresse mail est vérifiée.' },
    })
  })
  await page.goto('/activation?id=3&_hash=signature-test')
  await expect(page.getByRole('status')).toHaveText(
    'Votre adresse mail est vérifiée.',
  )
  await expect(page).toHaveURL(/\/activation$/)
  await expect(
    page.getByRole('button', { name: 'Renvoyer le mail' }),
  ).toHaveCount(0)
})

test('un lien expiré permet de demander un nouveau mail et affiche la limite de renvoi', async ({
  page,
}) => {
  await page.route('**/api/verify-email?**', (route) =>
    route.fulfill({
      status: 410,
      json: {
        status: 'expired_link',
        message: 'Ce lien a expiré. Demandez un nouveau mail d’activation.',
      },
    }),
  )
  await page.route('**/api/resend-verification', (route) =>
    route.fulfill({
      status: 429,
      json: {
        status: 'too_many_requests',
        message: 'Patientez une minute avant de demander un nouveau mail.',
      },
    }),
  )
  await page.goto('/activation?id=3&_hash=signature-test')
  await expect(page.getByRole('alert').first()).toContainText(
    'Ce lien a expiré',
  )
  await page
    .getByLabel('Adresse mail', { exact: true })
    .fill('elodie@example.com')
  await page.getByRole('button', { name: 'Renvoyer le mail' }).click()
  await expect(
    page.getByText('Patientez une minute avant de demander un nouveau mail.'),
  ).toBeVisible()
})

test('une panne réseau ne prétend pas que le compte est créé', async ({
  page,
}) => {
  await page.route('**/api/register', (route) => route.abort('failed'))
  await page.goto('/inscription')
  await fillRegistration(page)
  await page.getByRole('button', { name: 'Créer mon compte' }).click()
  await expect(page.getByRole('alert')).toContainText(
    'La réponse du serveur n’a pas pu être obtenue',
  )
  await expect(
    page.getByRole('button', { name: 'Créer mon compte' }),
  ).toBeEnabled()
  await expect(
    page.getByRole('heading', { name: 'Vérifiez votre adresse mail' }),
  ).toHaveCount(0)
})

test('le formulaire reste utilisable au clavier sur téléphone sans débordement', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/inscription')
  await page.getByLabel('Prénom', { exact: true }).focus()
  await page.keyboard.press('Tab')
  await expect(page.getByLabel('Nom', { exact: true })).toBeFocused()
  await page.keyboard.press('Tab')
  await expect(page.getByLabel('Adresse mail', { exact: true })).toBeFocused()
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true)
  await page.screenshot({
    path: 'test-results/registration-mobile.png',
    fullPage: true,
  })
})
