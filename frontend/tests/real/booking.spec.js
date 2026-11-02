import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'

const password = process.env.CINEMA_TEST_PASSWORD
const screeningId = process.env.CINEMA_TEST_SCREENING
let reference

async function login(page, role) {
  await page.goto('/connexion')
  await page
    .getByLabel('Adresse mail', { exact: true })
    .fill(`${role}@example.test`)
  await page.getByLabel('Mot de passe', { exact: true }).fill(password)
  await page.getByRole('button', { name: 'Me connecter', exact: true }).click()
  await expect(page).toHaveURL(/\/compte$/)
}

test.describe.serial('Parcours avec Symfony et PostgreSQL', () => {
  test('réserver sans compte, télécharger le PDF et libérer les sièges', async ({
    page,
  }) => {
    await page.goto('/')
    await expect(
      page.getByRole('heading', { name: 'Film de recette' }),
    ).toBeVisible()
    await page.goto(`/seances/${screeningId}/sieges`)
    await page.getByRole('button', { name: 'Siège A1', exact: true }).click()
    await page.getByRole('button', { name: 'Siège A2', exact: true }).click()
    await page.getByRole('button', { name: 'Continuer', exact: true }).click()
    await page.getByLabel('Prénom', { exact: true }).fill('Camille')
    await page.getByLabel('Nom', { exact: true }).fill('Recette')
    await page
      .getByLabel('Adresse mail', { exact: true })
      .fill('client@example.test')
    await page.getByRole('button', { name: 'Voir le récapitulatif' }).click()
    const response = page.waitForResponse(
      (r) =>
        r.url().endsWith('/api/reservations') &&
        r.request().method() === 'POST',
    )
    await page.getByRole('button', { name: 'Confirmer ma réservation' }).click()
    const saved = await response
    expect(saved.status()).toBe(201)
    const data = await saved.json()
    reference = data.reservation.reference
    expect(data.reservation.totalAmountCents).toBe(2100)
    await expect(
      page.getByRole('heading', {
        name: 'Merci Camille, votre réservation est confirmée !',
      }),
    ).toBeVisible()
    const seats = await (
      await page.request.get(`/api/screenings/${screeningId}/seats`)
    ).json()
    expect(seats.seats.filter((s) => !s.available).length).toBe(2)
    const download = page.waitForEvent('download')
    await page
      .getByRole('button', { name: 'Télécharger ma confirmation PDF' })
      .click()
    const file = await download
    expect(await file.failure()).toBeNull()
    const bytes = await readFile(await file.path())
    expect(bytes.subarray(0, 5).toString()).toBe('%PDF-')
    expect(bytes.length).toBeGreaterThan(1000)
    await page
      .getByRole('link', { name: 'Consulter ou annuler ma réservation' })
      .click()
    await page
      .getByRole('button', { name: 'Annuler ma réservation', exact: true })
      .click()
    await page
      .getByRole('button', { name: 'Confirmer l’annulation', exact: true })
      .click()
    await expect(
      page.getByText('Vous avez annulé cette réservation.', { exact: true }),
    ).toBeVisible()
    await expect(
      page.getByRole('button', { name: 'Télécharger ma confirmation PDF' }),
    ).toHaveCount(0)
    const freed = await (
      await page.request.get(`/api/screenings/${screeningId}/seats`)
    ).json()
    expect(freed.seats.every((s) => s.available)).toBe(true)
  })

  test('retrouver la réservation sans compte après connexion et refuser l’administration', async ({
    page,
  }) => {
    await login(page, 'client')
    await expect(page.getByText(new RegExp(reference))).toBeVisible()
    const denied = await page.request.get('/api/admin/statistics')
    expect(denied.status()).toBe(403)
    await page.goto('/admin')
    await expect(page.getByRole('alert')).toHaveText(
      'Vous n’avez pas accès à l’administration.',
    )
    await page.getByRole('button', { name: 'Mon compte', exact: true }).click()
    await page
      .getByRole('button', { name: 'Me déconnecter', exact: true })
      .click()
    const session = await (await page.request.get('/api/session')).json()
    expect(session.user).toBeNull()
  })

  test('rechercher au guichet et réserver les tarifs et statistiques à la direction', async ({
    page,
  }) => {
    await login(page, 'staff')
    await page.goto('/admin')
    await page
      .getByLabel('Numéro de réservation ou adresse mail')
      .fill(reference)
    await page.getByRole('button', { name: 'Rechercher', exact: true }).click()
    await expect(
      page.getByText('Le client a annulé cette réservation.', { exact: true }),
    ).toBeVisible()
    await expect(page.getByLabel('Nouveau tarif en euros')).toHaveCount(0)
    expect((await page.request.get('/api/admin/statistics')).status()).toBe(403)
    await page.getByRole('button', { name: 'Mon compte', exact: true }).click()
    await page
      .getByRole('button', { name: 'Me déconnecter', exact: true })
      .click()
    await login(page, 'direction')
    await page.goto('/admin')
    await page.getByLabel('Nouveau tarif en euros').fill('12.50')
    await page
      .getByRole('button', { name: 'Enregistrer le tarif', exact: true })
      .click()
    await expect(
      page.getByText('Le tarif est modifié pour les nouvelles réservations.'),
    ).toBeVisible()
    const plan = await (
      await page.request.get(`/api/screenings/${screeningId}/seats`)
    ).json()
    expect(plan.unitPriceCents).toBe(1250)
    await page
      .getByRole('button', { name: 'Afficher les statistiques', exact: true })
      .click()
    await expect(
      page.getByText('Aucune séance sur cette période.'),
    ).toBeVisible()
  })
})
