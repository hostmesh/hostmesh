// SPDX-License-Identifier: Apache-2.0
import { test as base, expect } from '@playwright/test'

/**
 * Every test fails on an uncaught error in the page or a Content Security
 * Policy violation: the stack serves the apps with the production policy.
 * Console errors as such are not failures: the browser logs one for every
 * 401, 403 and 422 answer, which these tests cause on purpose.
 *
 * A violation is caught twice: by its console message and by the
 * `securitypolicyviolation` event. The browser logs no message for a
 * blocked `new Function` whose error the page catches (issue #144).
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
      await page.exposeFunction('reportPolicyViolation', (violation: string) => problems.push(violation))
      // Before any script of the page runs, so that a violation on load counts.
      await page.addInitScript(() => {
        const page = window as unknown as { reportPolicyViolation: (violation: string) => Promise<void> }
        document.addEventListener('securitypolicyviolation', (event) => {
          void page.reportPolicyViolation(`Policy violation: ${event.effectiveDirective} blocked ${event.blockedURI} at ${event.sourceFile}:${String(event.lineNumber)}`)
        }, { capture: true })
      })
      await use(problems)
      expect(problems, 'errors in the page').toEqual([])
    },
    { auto: true },
  ],
})

export { expect }
