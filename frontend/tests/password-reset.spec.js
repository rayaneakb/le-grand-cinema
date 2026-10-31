import { expect, test } from '@playwright/test'

test.beforeEach(async ({ page }) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: { user: null } }),
  )
})

test('la demande de lien affiche un message qui ne révèle pas les comptes', async ({
  page,
}) => {
  await page.route('**/api/forgot-password', (route) => {
    expect(route.request().postDataJSON()).toEqual({
      email: 'elodie@example.com',
    })
    return route.fulfill({
      status: 202,
      json: {
        status: 'request_accepted',
        message:
          'Si cette adresse mail correspond à un compte activé, vous recevrez un lien pour réinitialiser votre mot de passe.',
      },
    })
  })
  await page.goto('/mot-de-passe-oublie')
  await page
    .getByLabel('Adresse mail', { exact: true })
    .fill('elodie@example.com')
  await page.getByRole('button', { name: 'Recevoir un lien' }).click()
  await expect(page.getByRole('status')).toContainText(
    'Si cette adresse mail correspond',
  )
})

test('le nouveau mot de passe et le lien sont transmis puis la connexion est proposée', async ({
  page,
}) => {
  await page.route('**/api/reset-password', (route) => {
    const data = route.request().postDataJSON()
    expect(data.url).toContain('/reinitialiser-mot-de-passe?id=1&_hash=test')
    expect(data.password).toBe('un nouveau mot de passe')
    return route.fulfill({
      json: {
        status: 'password_changed',
        message: 'Votre mot de passe est modifié.',
      },
    })
  })
  await page.goto('/reinitialiser-mot-de-passe?id=1&_hash=test')
  await expect(page).toHaveURL(/\/reinitialiser-mot-de-passe$/)
  await page.getByLabel('Nouveau mot de passe', { exact: true }).fill('un nouveau mot de passe')
  await page.getByRole('button', { name: 'Modifier mon mot de passe' }).click()
  await expect(page.getByRole('status')).toContainText(
    'Votre mot de passe est modifié',
  )
  await expect(
    page.getByRole('link', { name: 'Se connecter', exact: true }),
  ).toBeVisible()
  await expect(page.getByLabel('Nouveau mot de passe', { exact: true })).toHaveCount(0)
})

test('un lien refusé permet de demander un nouveau lien', async ({ page }) => {
  await page.route('**/api/reset-password', (route) =>
    route.fulfill({
      status: 400,
      json: {
        status: 'invalid_link',
        message: 'Ce lien est invalide ou a expiré. Demandez un nouveau lien.',
      },
    }),
  )
  await page.goto('/reinitialiser-mot-de-passe?id=1&_hash=test')
  await page.getByLabel('Nouveau mot de passe', { exact: true }).fill('un nouveau mot de passe')
  await page.getByRole('button', { name: 'Modifier mon mot de passe' }).click()
  await expect(page.getByRole('alert')).toContainText(
    'Ce lien est invalide ou a expiré',
  )
  await expect(
    page.getByRole('link', { name: 'Demander un nouveau lien' }),
  ).toBeVisible()
})

test('une page ouverte sans lien ne permet pas de changer le mot de passe', async ({
  page,
}) => {
  await page.goto('/reinitialiser-mot-de-passe')
  await expect(page.getByRole('alert')).toContainText(
    'Ouvrez le lien reçu par mail',
  )
  await expect(
    page.getByRole('button', { name: 'Modifier mon mot de passe' }),
  ).toHaveCount(0)
})
