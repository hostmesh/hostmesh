// SPDX-License-Identifier: Apache-2.0
import type { Page } from '@playwright/test'

import { expect, test } from '../support/test'
import { ADMIN, PORTAL } from '../support/urls'

// Issue #142: on a dark system the apps are dark from the first paint, not
// once their bundle has run. The bundle is held back here, so whatever the
// page shows comes from the early script alone; the support test fails on
// any Content Security Policy violation.

const STORAGE_KEY = 'hostmesh-color-mode'

interface Paint {
  dark: boolean
  background: string
}

function paint(page: Page): Promise<Paint> {
  return page.evaluate(() => ({
    dark: document.documentElement.classList.contains('dark'),
    background: getComputedStyle(document.body).backgroundColor,
  }))
}

/** Opens the login screen with the app's bundle held until `release` is called. */
async function openHeld(page: Page, origin: string): Promise<() => void> {
  let release = (): void => undefined
  const held = new Promise<void>((resolve) => {
    release = resolve
  })
  await page.route(/\/assets\/index-[^/]+\.js$/, async (route) => {
    await held
    await route.continue()
  })
  await page.goto(`${origin}/login`, { waitUntil: 'commit' })
  return release
}

for (const [name, origin] of [['admin', ADMIN], ['portal', PORTAL]] as const) {
  test.describe(`the ${name} app`, () => {
    test.describe('on a dark system', () => {
      test.use({ colorScheme: 'dark' })

      test('is dark before its bundle has run, and stays dark', async ({ page }) => {
        const release = await openHeld(page, origin)

        await expect(page.locator('html')).toHaveClass(/\bdark\b/)
        await expect(page.locator('body')).toBeAttached()
        const early = await paint(page)

        release()
        await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
        expect(await paint(page)).toEqual(early)
      })

      test('is light before its bundle has run when light was chosen', async ({ page }) => {
        await page.addInitScript((key) => window.localStorage.setItem(key, 'light'), STORAGE_KEY)
        const release = await openHeld(page, origin)

        await expect(page.locator('html')).toHaveClass(/\blight\b/)
        expect((await paint(page)).dark).toBe(false)

        release()
        await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
        expect((await paint(page)).dark).toBe(false)
      })
    })

    test.describe('on a light system', () => {
      test.use({ colorScheme: 'light' })

      test('is dark before its bundle has run when dark was chosen', async ({ page }) => {
        await page.addInitScript((key) => window.localStorage.setItem(key, 'dark'), STORAGE_KEY)
        const release = await openHeld(page, origin)

        await expect(page.locator('html')).toHaveClass(/\bdark\b/)
        release()
        await expect(page.getByRole('heading', { name: 'Log in' })).toBeVisible()
        expect((await paint(page)).dark).toBe(true)
      })
    })
  })
}
