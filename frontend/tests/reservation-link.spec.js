import { expect, test } from '@playwright/test'

test('un visiteur retrouve son lien sans compte et reçoit une réponse neutre', async ({ page }) => {
  await page.route('**/api/session', (route) => route.fulfill({ json: { user: null } }))
  await page.route('**/api/reservation-link', (route) => {
    expect(route.request().postDataJSON()).toEqual({ email: 'client@example.com', reference: 'ABCDEF0123456789' })
    return route.fulfill({ status: 202, json: { message: 'Si ces informations correspondent à une réservation, vous recevrez son lien personnel par mail.' } })
  })
  await page.goto('/retrouver-reservation')
  await page.getByLabel('Numéro de réservation', { exact: true }).fill('ABCDEF0123456789')
  await page.getByLabel('Adresse mail de la réservation').fill('client@example.com')
  await page.getByRole('button', { name: 'Recevoir mon lien' }).click()
  await expect(page.getByRole('status')).toContainText('Si ces informations correspondent')
})

test('la limite de renvoi conserve les coordonnées et affiche le refus', async ({ page }) => {
  await page.route('**/api/session', (route) => route.fulfill({ json: { user: null } }))
  await page.route('**/api/reservation-link', (route) => route.fulfill({ status: 429, json: { message: 'Trop de demandes. Patientez avant de réessayer.' } }))
  await page.goto('/retrouver-reservation')
  await page.getByLabel('Numéro de réservation', { exact: true }).fill('ABCDEF0123456789')
  await page.getByLabel('Adresse mail de la réservation').fill('client@example.com')
  await page.getByRole('button', { name: 'Recevoir mon lien' }).click()
  await expect(page.getByRole('alert')).toContainText('Trop de demandes')
  await expect(page.getByLabel('Adresse mail de la réservation')).toHaveValue('client@example.com')
})
