// SPDX-License-Identifier: Apache-2.0
import type { Page } from '@playwright/test'

import type { Account, Administrator } from './seed'
import { expect } from './test'
import { totp } from './totp'
import { ADMIN, PORTAL } from './urls'

/** Opens the login screen of the app at `origin` and submits the form. */
export async function submitLogin(page: Page, origin: string, account: Pick<Account, 'email' | 'password'>): Promise<void> {
  await page.goto(`${origin}/login`)
  await page.getByLabel('Email').fill(account.email)
  await page.getByLabel('Password').fill(account.password)
  await page.getByRole('button', { name: 'Log in' }).click()
}

/** Submits the two-factor challenge screen. */
export async function submitCode(page: Page, code: string): Promise<void> {
  await expect(page.getByRole('heading', { name: 'Two-factor authentication' })).toBeVisible()
  await page.getByLabel('Authentication code').fill(code)
  await page.getByRole('button', { name: 'Continue' }).click()
}

/** Password and TOTP code, up to the dashboard. Once per administrator and run: a code works once. */
export async function logInAsAdministrator(page: Page, administrator: Administrator): Promise<void> {
  await submitLogin(page, ADMIN, administrator)
  await submitCode(page, totp(administrator.totp_secret))
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
}

export async function logInAsCustomer(page: Page, customer: Account): Promise<void> {
  await submitLogin(page, PORTAL, customer)
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
}
