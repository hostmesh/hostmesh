// SPDX-License-Identifier: Apache-2.0
import { logInAsAdministrator, logInAsCustomer } from '../support/flows'
import { seed } from '../support/seed'
import { expect, test } from '../support/test'
import { ADMIN, API, PORTAL } from '../support/urls'

test('an administrator logs out', async ({ page }) => {
  await logInAsAdministrator(page, seed.administrators.logout)

  await page.getByRole('button', { name: 'Log out' }).click()

  await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
  await expect(page).toHaveURL(`${ADMIN}/login`)

  // The session is gone on the server, not only in the app.
  await page.goto(`${ADMIN}/`)
  await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
  const response = await page.request.get(`${API}/admin/auth/user`, {
    headers: { Origin: ADMIN, Accept: 'application/json' },
  })
  expect(response.status()).toBe(401)
})

test('a customer logs out', async ({ page }) => {
  await logInAsCustomer(page, seed.customers.logout)

  await page.getByRole('button', { name: 'Log out' }).click()

  await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
  await expect(page).toHaveURL(`${PORTAL}/login`)

  await page.goto(`${PORTAL}/`)
  await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
  const response = await page.request.get(`${API}/customer/auth/user`, {
    headers: { Origin: PORTAL, Accept: 'application/json' },
  })
  expect(response.status()).toBe(401)
})
