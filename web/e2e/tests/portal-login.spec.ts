// SPDX-License-Identifier: Apache-2.0
import { logInAsCustomer, submitLogin } from '../support/flows'
import { seed } from '../support/seed'
import { expect, test } from '../support/test'
import { PORTAL } from '../support/urls'

test('a customer logs in to the portal', async ({ page }) => {
  const customer = seed.customers.login

  await logInAsCustomer(page, customer)

  await expect(page).toHaveURL(`${PORTAL}/`)
  await expect(page.getByRole('banner').getByText(customer.name)).toBeVisible()
})

test('a wrong password is refused', async ({ page }) => {
  await submitLogin(page, PORTAL, { email: seed.customers.login.email, password: 'not-the-password' })

  await expect(page.getByRole('alert').filter({ hasText: 'These credentials do not match our records.' })).toBeVisible()
  await expect(page).toHaveURL(`${PORTAL}/login`)
})

test('an administrator cannot log in to the portal', async ({ page }) => {
  await submitLogin(page, PORTAL, seed.administrators.login)

  await expect(page.getByRole('alert').filter({ hasText: 'These credentials do not match our records.' })).toBeVisible()
  await expect(page).toHaveURL(`${PORTAL}/login`)
})
