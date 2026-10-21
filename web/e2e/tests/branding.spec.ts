// SPDX-License-Identifier: Apache-2.0
import type { Page } from '@playwright/test'

import { logInAsAdministrator, logInAsCustomer } from '../support/flows'
import { seed } from '../support/seed'
import { expect, test } from '../support/test'
import { ADMIN, PORTAL } from '../support/urls'

// Spec section 28: both apps take the product name from GET /branding, and
// the portal also the brand colors and the support and legal links. No logo
// is seeded, so each app shows the name where the logo would be. Of the
// links only support is seeded: the footer leaves out the two others.
const { product_name: productName, primary_light: primaryLight, support_url: supportUrl } = seed.branding

/** The portal's footer (AppFooter, issue #140): the seeded link and no other. */
async function expectFooterWithSupportLink(page: Page): Promise<void> {
  const links = page.getByRole('contentinfo').getByRole('link')

  await expect(links).toHaveText(['Support'])
  await expect(links).toHaveAttribute('href', supportUrl)
}

test('the admin app shows the product name before and after login', async ({ page }) => {
  await page.goto(`${ADMIN}/login`)

  await expect(page.getByRole('main').getByText(productName, { exact: true })).toBeVisible()
  await expect(page).toHaveTitle(`${productName} admin`)
  // Spec section 29: the admin app uses the name and the logo only. Checked
  // once the name shows, so branding has loaded.
  await expect(page.locator(`a[href="${supportUrl}"]`)).toHaveCount(0)

  await logInAsAdministrator(page, seed.administrators.branding)

  await expect(page.getByRole('banner').getByText(productName, { exact: true })).toBeVisible()
  await expect(page.locator(`a[href="${supportUrl}"]`)).toHaveCount(0)
})

test('the portal shows the product name, applies the brand color and links to support', async ({ page }) => {
  await page.goto(`${PORTAL}/login`)

  await expect(page.getByRole('main').getByText(productName, { exact: true })).toBeVisible()
  await expect(page).toHaveTitle(productName)
  // Set by the app on <html> once branding has loaded. Polled, never slept for.
  await expect
    .poll(() => page.evaluate(() => document.documentElement.style.getPropertyValue('--primary')))
    .toBe(primaryLight)
  await expectFooterWithSupportLink(page)

  await logInAsCustomer(page, seed.customers.login)

  await expect(page.getByRole('banner').getByText(productName, { exact: true })).toBeVisible()
  await expectFooterWithSupportLink(page)
})
