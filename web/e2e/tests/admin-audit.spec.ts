// SPDX-License-Identifier: Apache-2.0
import { logInAsAdministrator } from '../support/flows'
import { seed } from '../support/seed'
import { expect, test } from '../support/test'

// Issues #143 and #150: an administrator reads the audit log, filtered and
// sorted by the API (decision 0019), and the address keeps the view. The
// log holds the events of every test and every run, so the test looks for
// its own administrator's row instead of counting.
test('an administrator finds their own sign-in in the audit log, filters it and sorts it', async ({ page }) => {
  const administrator = seed.administrators.audit

  await logInAsAdministrator(page, administrator)
  await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'Audit log' }).click()
  await expect(page.getByRole('heading', { name: 'Audit log', level: 1 })).toBeFocused()

  const filters = page.getByRole('search', { name: 'Filter the audit log' })
  await filters.getByLabel('Action').selectOption('auth.two_factor.challenge_passed')
  await filters.getByLabel('Actor type').selectOption('administrator')
  await filters.getByRole('button', { name: 'Apply filters' }).click()

  await expect(page).toHaveURL(/action=auth\.two_factor\.challenge_passed/)
  const own = page.getByRole('row').filter({ hasText: administrator.name })
  await expect(own.first()).toContainText('Two-factor code accepted')
  await expect(page.getByRole('row').filter({ hasText: 'Login succeeded' })).toHaveCount(0)

  // Oldest first, from the first page, with the filters kept.
  await page.getByRole('button', { name: 'Time' }).click()
  await expect(page).toHaveURL(/sort=created_at/)
  await expect(page.getByRole('columnheader', { name: 'Time' })).toHaveAttribute('aria-sort', 'ascending')

  // The address holds the view.
  await page.reload()
  await expect(page.getByRole('search', { name: 'Filter the audit log' }).getByLabel('Action')).toHaveValue(
    'auth.two_factor.challenge_passed',
  )
  await expect(page.getByRole('columnheader', { name: 'Time' })).toHaveAttribute('aria-sort', 'ascending')
})
