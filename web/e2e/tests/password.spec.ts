// SPDX-License-Identifier: Apache-2.0
import { randomUUID } from 'node:crypto'

import { logInAsCustomer, submitCode, submitLogin } from '../support/flows'
import { seed } from '../support/seed'
import { expect, test } from '../support/test'
import { codeAfter } from '../support/totp'
import { ADMIN, PORTAL } from '../support/urls'

// Issue #133, decision 0023: an account changes its own password on the
// account screen, stays signed in, and logs in with the new one next time.
// A random new password: Pwned Passwords cannot know it.

test('an administrator changes the password, stays signed in, and logs in with the new one', async ({ page }) => {
  // A code works once per time step (decision 0011): up to 30 seconds of waiting, three times.
  test.setTimeout(120_000)
  const administrator = seed.administrators.password
  const newPassword = `new ${randomUUID()}`

  let last = await codeAfter(administrator.totp_secret, 0)
  await submitLogin(page, ADMIN, administrator)
  await submitCode(page, last.code)
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Account' }).click()

  const change = page.getByRole('form', { name: 'Change password' })
  last = await codeAfter(administrator.totp_secret, last.step)
  await change.getByLabel('Current password').fill(administrator.password)
  await change.getByLabel('New password').fill(newPassword)
  await change.getByLabel('Authentication code').fill(last.code)
  await change.getByRole('button', { name: 'Change password' }).click()
  await expect(change.getByRole('status')).toHaveText('Your password was changed. Your other sessions were signed out.')

  // This session goes on.
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Account', level: 1 })).toBeVisible()

  await page.getByRole('button', { name: 'Log out' }).click()
  await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
  last = await codeAfter(administrator.totp_secret, last.step)
  await submitLogin(page, ADMIN, { email: administrator.email, password: newPassword })
  await submitCode(page, last.code)
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
})

test('a customer changes the password, stays signed in, and logs in with the new one', async ({ page }) => {
  const customer = seed.customers.password
  const newPassword = `new ${randomUUID()}`

  await logInAsCustomer(page, customer)
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Account' }).click()

  const change = page.getByRole('form', { name: 'Change password' })
  await expect(change.getByLabel('Authentication code')).toHaveCount(0)
  await change.getByLabel('Current password').fill(customer.password)
  await change.getByLabel('New password').fill(newPassword)
  await change.getByRole('button', { name: 'Change password' }).click()
  await expect(change.getByRole('status')).toHaveText('Your password was changed. Your other sessions were signed out.')

  await page.reload()
  await expect(page.getByRole('heading', { name: 'Account', level: 1 })).toBeVisible()

  await page.getByRole('button', { name: 'Log out' }).click()
  await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
  await submitLogin(page, PORTAL, { email: customer.email, password: newPassword })
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
})
