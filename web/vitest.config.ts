// SPDX-License-Identifier: Apache-2.0
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    // Each project is configured by the Vite or Vitest config in its directory.
    projects: ['apps/*', 'packages/*'],
  },
})
