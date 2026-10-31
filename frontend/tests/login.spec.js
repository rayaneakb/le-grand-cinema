import { expect, test } from '@playwright/test'

const user = {
  id: 1,
  firstName: 'Élodie',
  lastName: 'Martin',
  email: 'elodie@example.com',
  roles: ['ROLE_USER'],
}
const activeSession = {
  user,
  csrfToken: 'csrf-test',
  logoutToken: 'logout-test',
}

test.beforeEach(async ({ page }) => {
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: { user: null } }),
  )
  await page.route('**/api/csrf', (route) =>
    route.fulfill({ json: { csrfToken: 'csrf-test' } }),
  )
})

async function fillLogin(page) {
  await page
    .getByLabel('Adresse mail', { exact: true })
    .fill('elodie@example.com')
  await page
    .getByLabel('Mot de passe', { exact: true })
    .fill('une phrase facile à retenir')
}

test('une connexion valide envoie le jeton CSRF et ouvre le compte', async ({
  page,
}) => {
  let loggedIn = false
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: loggedIn ? activeSession : { user: null } }),
  )
  await page.route('**/api/login', (route) => {
    expect(route.request().headers()['x-csrf-token']).toBe('csrf-test')
    expect(route.request().postDataJSON()).toEqual({
      email: 'elodie@example.com',
      password: 'une phrase facile à retenir',
    })
    loggedIn = true
    return route.fulfill({ json: { status: 'authenticated' } })
  })
  await page.goto('/connexion')
  await fillLogin(page)
  await page.getByRole('button', { name: 'Me connecter' }).click()
  await expect(page).toHaveURL(/\/compte$/)
  await expect(page.getByText('Bonjour Élodie.')).toBeVisible()
  await expect(
    page.getByRole('button', { name: 'Mon compte', exact: true }),
  ).toBeVisible()
})

test('un mauvais mot de passe affiche une erreur sans révéler le compte', async ({
  page,
}) => {
  await page.route('**/api/login', (route) =>
    route.fulfill({
      status: 401,
      json: {
        status: 'invalid_credentials',
        message: 'Adresse mail ou mot de passe incorrect.',
      },
    }),
  )
  await page.goto('/connexion')
  await fillLogin(page)
  await page.getByRole('button', { name: 'Me connecter' }).click()
  await expect(page.getByRole('alert')).toHaveText(
    'Adresse mail ou mot de passe incorrect.',
  )
  await expect(page.getByRole('button', { name: 'Me connecter' })).toBeEnabled()
  await expect(page).toHaveURL(/\/connexion$/)
})

test('un compte non vérifié peut demander un nouveau lien', async ({
  page,
}) => {
  await page.route('**/api/login', (route) =>
    route.fulfill({
      status: 403,
      json: {
        status: 'email_not_verified',
        message: 'Vérifiez votre adresse mail avant de vous connecter.',
      },
    }),
  )
  await page.goto('/connexion')
  await fillLogin(page)
  await page.getByRole('button', { name: 'Me connecter' }).click()
  await expect(page.getByRole('alert')).toContainText(
    'Vérifiez votre adresse mail',
  )
  await expect(
    page.getByRole('button', { name: 'Renvoyer le mail' }),
  ).toBeVisible()
})

test('le blocage des tentatives est affiché', async ({ page }) => {
  await page.route('**/api/login', (route) =>
    route.fulfill({
      status: 429,
      json: {
        status: 'too_many_attempts',
        message:
          'Trop de tentatives. Patientez cinq minutes avant de réessayer.',
      },
    }),
  )
  await page.goto('/connexion')
  await fillLogin(page)
  await page.getByRole('button', { name: 'Me connecter' }).click()
  await expect(page.getByRole('alert')).toContainText('Patientez cinq minutes')
})

test('la déconnexion envoie le jeton et revient à la connexion', async ({
  page,
}) => {
  let loggedIn = true
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: loggedIn ? activeSession : { user: null } }),
  )
  await page.route('**/api/logout', (route) => {
    expect(route.request().method()).toBe('POST')
    expect(
      new URLSearchParams(route.request().postData()).get('_csrf_token'),
    ).toBe('logout-test')
    loggedIn = false
    return route.fulfill({ json: { status: 'logged_out' } })
  })
  await page.goto('/compte')
  await page.getByRole('button', { name: 'Me déconnecter' }).click()
  await expect(page).toHaveURL(/\/connexion$/)
  await expect(
    page.getByRole('heading', { name: 'Se connecter' }),
  ).toBeVisible()
})

test('un compte non connecté ne présente aucune donnée personnelle', async ({
  page,
}) => {
  await page.goto('/compte')
  await expect(
    page.getByText('Veuillez vous connecter pour accéder à votre compte.'),
  ).toBeVisible()
  await expect(page.getByText('elodie@example.com')).toHaveCount(0)
})

test('aucune activité automatique ne maintient la session, une interaction peut révéler son expiration', async ({
  page,
}) => {
  await page.clock.install()
  let requests = 0
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: activeSession }),
  )
  await page.route('**/api/activity', (route) => {
    requests += 1
    return route.fulfill({
      status: 401,
      json: { message: 'Votre session a expiré.' },
    })
  })
  await page.goto('/compte')
  await expect(page.getByText('Bonjour Élodie.')).toBeVisible()
  await page.clock.fastForward('02:00')
  expect(requests).toBe(0)
  await page.keyboard.press('Tab')
  await page.clock.fastForward('01:00')
  await expect(
    page.getByText('Veuillez vous connecter pour accéder à votre compte.'),
  ).toBeVisible()
  expect(requests).toBe(1)
})

test('le compte disparaît de l’écran après dix minutes sans interaction', async ({
  page,
}) => {
  await page.clock.install()
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: activeSession }),
  )
  await page.goto('/compte')
  await expect(page.getByText('Bonjour Élodie.')).toBeVisible()
  await page.clock.fastForward('10:01')
  await expect(
    page.getByText('Veuillez vous connecter pour accéder à votre compte.'),
  ).toBeVisible()
  await expect(page.getByText('elodie@example.com')).toHaveCount(0)
})

test('le menu visiteur est accessible au clavier et se ferme avec Échap', async ({
  page,
}) => {
  await page.route('**/api/films', (route) =>
    route.fulfill({ json: { films: [] } }),
  )
  await page.goto('/')
  const button = page.getByRole('button', { name: 'Mon compte', exact: true })
  await expect(button).toBeEnabled()
  await expect(
    page.getByRole('link', { name: 'Se connecter', exact: true }),
  ).toHaveCount(0)
  await button.focus()
  await page.keyboard.press('Enter')
  await expect(button).toHaveAttribute('aria-expanded', 'true')
  await page.keyboard.press('Tab')
  await expect(
    page.getByRole('link', { name: 'Se connecter', exact: true }),
  ).toBeFocused()
  await page.keyboard.press('Tab')
  await expect(
    page.getByRole('link', { name: 'Créer un compte', exact: true }),
  ).toBeFocused()
  await page.keyboard.press('Escape')
  await expect(button).toHaveAttribute('aria-expanded', 'false')
  await expect(button).toBeFocused()

  const programme = page.getByRole('link', { name: 'À l’affiche', exact: true })
  const linkBox = await programme.boundingBox()
  const buttonBox = await button.boundingBox()
  expect(Math.abs(linkBox.y + linkBox.height / 2 - buttonBox.y - buttonBox.height / 2)).toBeLessThan(2)
  await programme.click()
  await expect(page).toHaveURL(/\/$/)
  await expect(button).toBeInViewport()
})

test('le menu connecté permet de se déconnecter depuis la programmation', async ({
  page,
}) => {
  let loggedIn = true
  await page.route('**/api/films', (route) =>
    route.fulfill({ json: { films: [] } }),
  )
  await page.route('**/api/session', (route) =>
    route.fulfill({ json: loggedIn ? activeSession : { user: null } }),
  )
  await page.route('**/api/logout', (route) => {
    expect(
      new URLSearchParams(route.request().postData()).get('_csrf_token'),
    ).toBe('logout-test')
    loggedIn = false
    return route.fulfill({ json: { status: 'logged_out' } })
  })
  await page.goto('/')
  await page.getByRole('button', { name: 'Mon compte', exact: true }).click()
  await expect(
    page.getByRole('link', { name: 'Voir mon compte' }),
  ).toBeVisible()
  await expect(
    page.getByRole('link', { name: 'Créer un compte', exact: true }),
  ).toHaveCount(0)
  await page
    .getByRole('button', { name: 'Me déconnecter', exact: true })
    .click()
  await expect(page).toHaveURL(/\/connexion$/)
})

test('le menu se ferme au clic extérieur et ne déborde pas sur téléphone', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/connexion')
  const button = page.getByRole('button', { name: 'Mon compte', exact: true })
  await button.click()
  await expect(page.locator('#account-menu-options')).toBeVisible()
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBe(true)
  await page.screenshot({
    path: 'test-results/account-menu-mobile.png',
    fullPage: true,
  })
  await page.getByRole('heading', { name: 'Se connecter' }).click()
  await expect(button).toHaveAttribute('aria-expanded', 'false')
})
