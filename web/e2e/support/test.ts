// SPDX-License-Identifier: Apache-2.0
import { test as base, expect } from '@playwright/test'

/**
 * Every test fails on an uncaught error in the page or a Content Security
 * Policy violation: the stack serves the apps with the production policy.
 * Console errors as such are not failures: the browser logs one for every
 * 401, 403 and 422 answer, which these tests cause on purpose.
 */
export const test = base.extend<{ pageProblems: string[] }>({
  pageProblems: [
    async ({ page }, use) => {
      const problems: string[] = []
      page.on('pageerror', (error) => problems.push(`Uncaught error: ${error.message}`))
      page.on('console', (message) => {
        if (message.text().includes('Content Security Policy')) {
          problems.push(message.text())
        }
      })
      await use(problems)
      expect(problems, 'errors in the page').toEqual([])
    },
    { auto: true },
  ],
})

export { expect }
