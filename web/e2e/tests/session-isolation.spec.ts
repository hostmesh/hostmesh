// SPDX-License-Identifier: Apache-2.0
import { logInAsAdministrator } from '../support/flows'
import { seed } from '../support/seed'
import { expect, test } from '../support/test'
import { ADMIN, ADMIN_SESSION_COOKIE, API, PORTAL, PORTAL_SESSION_COOKIE } from '../support/urls'

// Spec section 28 and decision 0010: the two realms share the API host, so
// the browser sends the admin session cookie along with requests from the
// portal. One browser context holds everything below, as one browser would.

const json = { Accept: 'application/json' }

test('an admin session cannot act in the portal', async ({ page, context }) => {
  await logInAsAdministrator(page, seed.administrators.isolation)

  const adminCookie = (await context.cookies(API)).find((cookie) => cookie.name === ADMIN_SESSION_COOKIE)
  expect(adminCookie, 'the admin session cookie').toBeDefined()

  await test.step('the portal asks the administrator to log in', async () => {
    await page.goto(`${PORTAL}/`)
    await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
    await expect(page).toHaveURL(`${PORTAL}/login`)
  })

  await test.step('the customer API does not know the admin session', async () => {
    const response = await context.request.get(`${API}/customer/auth/user`, { headers: { ...json, Origin: PORTAL } })
    expect(response.status()).toBe(401)
  })

  await test.step('the admin API refuses a request from the portal origin', async () => {
    const response = await context.request.get(`${API}/admin/auth/user`, { headers: { ...json, Origin: PORTAL } })
    expect(response.status()).toBe(403)
  })

  await test.step('the admin cookie under the portal cookie name opens nothing', async () => {
    await context.addCookies([{
      name: PORTAL_SESSION_COOKIE,
      value: adminCookie?.value ?? '',
      // The origin, not the API's base path: a `__Host-` cookie must have
      // the path `/`, and Playwright takes the path from this URL.
      url: new URL(API).origin,
      secure: true,
      httpOnly: true,
      sameSite: 'Lax',
    }])
    // Or the 401 below would prove nothing.
    const forged = (await context.cookies(API)).find((cookie) => cookie.name === PORTAL_SESSION_COOKIE)
    expect(forged?.value, 'the forged cookie is sent to the API').toBe(adminCookie?.value)
    const response = await context.request.get(`${API}/customer/auth/user`, { headers: { ...json, Origin: PORTAL } })
    expect(response.status()).toBe(401)
  })

  await test.step('the admin session itself is untouched', async () => {
    const response = await context.request.get(`${API}/admin/auth/user`, { headers: { ...json, Origin: ADMIN } })
    expect(response.status()).toBe(200)
    await page.goto(`${ADMIN}/`)
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
  })
})
