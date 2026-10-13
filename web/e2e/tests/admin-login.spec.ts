// SPDX-License-Identifier: Apache-2.0
import { logInAsAdministrator, submitCode, submitLogin } from '../support/flows'
import { seed } from '../support/seed'
import { expect, test } from '../support/test'
import { totp } from '../support/totp'
import { ADMIN, API } from '../support/urls'

// Spec section 28: admin requires two-factor authentication.

test('an administrator logs in with a password and a TOTP code', async ({ page }) => {
  const administrator = seed.administrators.login

  await logInAsAdministrator(page, administrator)

  await expect(page).toHaveURL(`${ADMIN}/`)
  await expect(page.getByRole('banner').getByText(administrator.name)).toBeVisible()
})

test('a wrong code is refused, and the right one still works', async ({ page }) => {
  const administrator = seed.administrators['wrong-code']
  const right = totp(administrator.totp_secret)
  // Never the right one, nor one of its neighbours by more than chance.
  const wrong = right === '000000' ? '111111' : '000000'

  await submitLogin(page, ADMIN, administrator)
  await submitCode(page, wrong)

  await expect(page.getByRole('alert').filter({ hasText: 'The two-factor code is invalid.' })).toBeVisible()
  await expect(page).toHaveURL(`${ADMIN}/two-factor`)

  await submitCode(page, totp(administrator.totp_secret))
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
})

test('the password alone does not open the admin app', async ({ page }) => {
  const administrator = seed.administrators['second-factor']

  await submitLogin(page, ADMIN, administrator)
  await expect(page.getByRole('heading', { name: 'Two-factor authentication' })).toBeVisible()

  // A page of the app, asked for directly: the challenge again.
  await page.goto(`${ADMIN}/branding`)
  await expect(page.getByRole('heading', { name: 'Two-factor authentication' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeHidden()

  // And the API itself refuses the half-finished session.
  const response = await page.request.get(`${API}/admin/auth/user`, {
    headers: { Origin: ADMIN, Accept: 'application/json' },
  })
  expect(response.status()).toBe(403)
  expect(await response.json()).toMatchObject({ code: 'two_factor_required' })
})

test('a wrong password does not reach the second factor', async ({ page }) => {
  await submitLogin(page, ADMIN, { email: seed.administrators.login.email, password: 'not-the-password' })

  await expect(page.getByRole('alert').filter({ hasText: 'These credentials do not match our records.' })).toBeVisible()
  await expect(page).toHaveURL(`${ADMIN}/login`)
})
