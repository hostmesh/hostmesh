// SPDX-License-Identifier: Apache-2.0
import { logInAsAdministrator, logInAsCustomer } from '../support/flows'
import { seed } from '../support/seed'
import { expect, test } from '../support/test'
import { ADMIN, PORTAL } from '../support/urls'

// Spec section 28: both apps take the product name from GET /branding, and
// the portal also the brand colors. No logo is seeded, so each app shows
// the name where the logo would be.
const { product_name: productName, primary_light: primaryLight } = seed.branding

test('the admin app shows the product name before and after login', async ({ page }) => {
  await page.goto(`${ADMIN}/login`)

  await expect(page.getByRole('main').getByText(productName, { exact: true })).toBeVisible()
  await expect(page).toHaveTitle(`${productName} admin`)

  await logInAsAdministrator(page, seed.administrators.branding)

  await expect(page.getByRole('banner').getByText(productName, { exact: true })).toBeVisible()
})

test('the portal shows the product name and applies the brand color', async ({ page }) => {
  await page.goto(`${PORTAL}/login`)

  await expect(page.getByRole('main').getByText(productName, { exact: true })).toBeVisible()
  await expect(page).toHaveTitle(productName)
  // Set by the app on <html> once branding has loaded. Polled, never slept for.
  await expect
    .poll(() => page.evaluate(() => document.documentElement.style.getPropertyValue('--primary')))
    .toBe(primaryLight)

  await logInAsCustomer(page, seed.customers.login)

  await expect(page.getByRole('banner').getByText(productName, { exact: true })).toBeVisible()
})
