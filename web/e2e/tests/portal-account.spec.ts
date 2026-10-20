// SPDX-License-Identifier: Apache-2.0
import type { Locator, Page } from '@playwright/test'

import { logInAsCustomer, submitLogin } from '../support/flows'
import { seed } from '../support/seed'
import { expect, test } from '../support/test'
import { codeAfter } from '../support/totp'
import { API, PORTAL } from '../support/urls'

// Issue #139: a customer turns two-factor on from the account screen, logs
// in with it, replaces the recovery codes and turns it off again.

function colorsOf(locator: Locator): Promise<{ field: string, modules: string, page: string }> {
  return locator.evaluate((element) => ({
    field: getComputedStyle(element).backgroundColor,
    modules: getComputedStyle(element).color,
    page: getComputedStyle(document.body).backgroundColor,
  }))
}

async function recoveryCodes(page: Page): Promise<string[]> {
  await expect(page.getByRole('heading', { name: 'Save your recovery codes' })).toBeFocused()
  const codes = await page.getByRole('listitem').allTextContents()
  expect(codes).toHaveLength(10)
  await page.getByRole('button', { name: 'I have saved these codes' }).click()
  await expect(page.getByRole('heading', { name: 'Save your recovery codes' })).toBeHidden()
  return codes.map((code) => code.trim())
}

async function logOut(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Log out' }).click()
  // The session has ended on the server once the app shows the login form.
  await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
}

async function submitPasswordAndCode(page: Page, form: string, password: string, code: string): Promise<void> {
  const scope = page.getByRole('form', { name: form })
  await scope.getByLabel('Password').fill(password)
  await scope.getByLabel('Authentication code').fill(code)
  await scope.getByRole('button').click()
}

test('a customer turns two-factor on, logs in with it, replaces the codes and turns it off', async ({ page }) => {
  // Each step needs a code for a later time step than the one before
  // (decision 0011), so the test may wait for the clock once: up to 30 seconds.
  test.setTimeout(90_000)
  const customer = seed.customers['two-factor']

  await logInAsCustomer(page, customer)
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Account' }).click()
  await expect(page.getByRole('heading', { name: 'Account', level: 1 })).toBeFocused()
  await expect(page.getByText('Two-factor authentication is off.')).toBeVisible()

  // Turn it on: the QR code, and the key to make the codes from.
  await page.getByRole('button', { name: 'Turn on two-factor authentication' }).click()
  const qr = page.getByRole('img', { name: 'QR code to scan with your authenticator app' })
  await expect(qr).toBeVisible()

  // Dark on light in both color modes (issue #124), like the admin app's.
  await page.emulateMedia({ colorScheme: 'light' })
  const light = await colorsOf(qr)
  await page.emulateMedia({ colorScheme: 'dark' })
  await expect.poll(async () => (await colorsOf(qr)).page).not.toBe(light.page)
  const dark = await colorsOf(qr)
  expect(dark.field).toBe(light.field)
  expect(dark.modules).toBe(light.modules)
  expect(dark.field).not.toBe(dark.page)
  await page.emulateMedia({ colorScheme: 'light' })

  const secret = (await page.getByText(/^[A-Z2-7]{16,}$/).textContent())?.trim() ?? ''
  let last = await codeAfter(secret, 0)
  const setup = page.getByRole('form', { name: 'Set up two-factor authentication' })
  await setup.getByLabel('Authentication code').fill(last.code)
  await setup.getByRole('button', { name: 'Turn on' }).click()
  const firstCodes = await recoveryCodes(page)
  await expect(page.getByText('Two-factor authentication is on.')).toBeVisible()

  // The next login asks for the second factor; a recovery code answers it.
  await logOut(page)
  await submitLogin(page, PORTAL, customer)
  await expect(page.getByRole('heading', { name: 'Two-factor authentication' })).toBeVisible()
  await page.getByRole('button', { name: 'Use a recovery code' }).click()
  await page.getByLabel('Recovery code').fill(firstCodes[0] ?? '')
  await page.getByRole('button', { name: 'Continue' }).click()
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()

  // New recovery codes: the old ones stop working.
  await page.goto(`${PORTAL}/account`)
  last = await codeAfter(secret, last.step)
  await submitPasswordAndCode(page, 'Recovery codes', customer.password, last.code)
  const newCodes = await recoveryCodes(page)
  expect(newCodes).not.toContain(firstCodes[1])

  // Turn it off.
  last = await codeAfter(secret, last.step)
  await submitPasswordAndCode(page, 'Turn off two-factor authentication', customer.password, last.code)
  await expect(page.getByText('Two-factor authentication is off.')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Two-factor authentication', level: 2 })).toBeFocused()

  // And the next login is complete after the password.
  await logOut(page)
  await logInAsCustomer(page, customer)
  const response = await page.request.get(`${API}/customer/auth/user`, {
    headers: { Origin: PORTAL, Accept: 'application/json' },
  })
  expect(await response.json()).toMatchObject({ data: { two_factor_enabled: false } })
})
