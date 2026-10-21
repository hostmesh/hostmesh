// SPDX-License-Identifier: Apache-2.0
import type { Page } from '@playwright/test'

import { submitCode, submitLogin } from '../support/flows'
import { seed } from '../support/seed'
import { expect, test } from '../support/test'
import { codeAfter } from '../support/totp'
import { ADMIN, API } from '../support/urls'

// Issue #138: an administrator replaces the recovery codes from the account
// screen, and turns two-factor off, which in admin leads straight into
// setting it up again (spec section 28).

async function recoveryCodes(page: Page): Promise<string[]> {
  await expect(page.getByRole('heading', { name: 'Save your recovery codes' })).toBeFocused()
  const codes = await page.getByRole('listitem').allTextContents()
  expect(codes).toHaveLength(10)
  await page.getByRole('button', { name: 'I have saved these codes' }).click()
  await expect(page.getByRole('heading', { name: 'Save your recovery codes' })).toBeHidden()
  return codes.map((code) => code.trim())
}

async function submitPasswordAndCode(page: Page, form: string, password: string, code: string): Promise<void> {
  const scope = page.getByRole('form', { name: form })
  await scope.getByLabel('Password').fill(password)
  await scope.getByLabel('Authentication code').fill(code)
  await scope.getByRole('button').click()
}

async function twoFactorEnabled(page: Page): Promise<unknown> {
  const response = await page.request.get(`${API}/admin/auth/user`, {
    headers: { Origin: ADMIN, Accept: 'application/json' },
  })
  return ((await response.json()) as { data?: { two_factor_enabled?: boolean } }).data?.two_factor_enabled
}

test('an administrator replaces the recovery codes, and turns two-factor off and sets it up again', async ({ page }) => {
  // Each step needs a code for a later time step than the one before
  // (decision 0011), so the test may wait for the clock: up to 30 seconds
  // at a time.
  test.setTimeout(120_000)
  const administrator = seed.administrators.account

  let last = await codeAfter(administrator.totp_secret, 0)
  await submitLogin(page, ADMIN, administrator)
  await submitCode(page, last.code)
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Account' }).click()
  await expect(page.getByRole('heading', { name: 'Account', level: 1 })).toBeFocused()
  await expect(page.getByText('Two-factor authentication is on.')).toBeVisible()

  // New recovery codes, shown once.
  last = await codeAfter(administrator.totp_secret, last.step)
  await submitPasswordAndCode(page, 'Recovery codes', administrator.password, last.code)
  await recoveryCodes(page)
  await expect(page.getByRole('heading', { name: 'Two-factor authentication', level: 2 })).toBeFocused()

  // Turning it off leads straight into the setup, with a new key.
  last = await codeAfter(administrator.totp_secret, last.step)
  await submitPasswordAndCode(page, 'Turn off two-factor authentication', administrator.password, last.code)
  await expect(page.getByRole('heading', { name: 'Set up two-factor authentication' })).toBeVisible()
  await expect(page).toHaveURL(`${ADMIN}/two-factor/setup?redirect=/account`)
  expect(await twoFactorEnabled(page)).toBeUndefined()

  const secret = (await page.getByText(/^[A-Z2-7]{16,}$/).textContent())?.trim() ?? ''
  expect(secret).not.toBe(administrator.totp_secret)
  let next = await codeAfter(secret, 0)
  await page.getByLabel('Authentication code').fill(next.code)
  await page.getByRole('button', { name: 'Turn on two-factor authentication' }).click()
  await recoveryCodes(page)

  // Back on the account screen, with two-factor on again.
  await expect(page).toHaveURL(`${ADMIN}/account`)
  await expect(page.getByText('Two-factor authentication is on.')).toBeVisible()
  expect(await twoFactorEnabled(page)).toBe(true)

  // The next login takes a code of the new key.
  await page.getByRole('button', { name: 'Log out' }).click()
  await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
  next = await codeAfter(secret, next.step)
  await submitLogin(page, ADMIN, administrator)
  await submitCode(page, next.code)
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
})
