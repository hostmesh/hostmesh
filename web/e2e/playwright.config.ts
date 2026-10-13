// SPDX-License-Identifier: Apache-2.0
import { defineConfig, devices } from '@playwright/test'

// The Slice 0 exit test (issue #32, decision 0016). It runs against the
// stack in end-to-end mode: `make -C infrastructure/development e2e-up e2e`.
export default defineConfig({
  testDir: './tests',
  // A test that passes only on a second try is a failing test.
  retries: 0,
  forbidOnly: !!process.env.CI,
  // One at a time: the suite takes seconds, and the login throttle counts
  // every attempt from this address.
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    ...devices['Desktop Chrome'],
    // Brand colors differ by color mode; the tests read the light ones.
    colorScheme: 'light',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
})
