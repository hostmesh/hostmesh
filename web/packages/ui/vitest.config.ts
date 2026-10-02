// SPDX-License-Identifier: Apache-2.0
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { playwright } from '@vitest/browser-playwright'
import { defineConfig } from 'vitest/config'

// The component tests run in headless Chromium, not jsdom: only a real
// browser computes the styles Tailwind generates from the design tokens
// (decision 0009). The Tailwind plugin compiles src/test/styles.css, which
// the setup file loads before every test.
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  test: {
    name: 'ui',
    include: ['src/**/*.test.ts'],
    setupFiles: ['./src/test/setup.ts'],
    browser: {
      enabled: true,
      headless: true,
      provider: playwright(),
      // Named like the project, so that `--project ui` selects it.
      instances: [{ browser: 'chromium', name: 'ui' }],
      screenshotFailures: false,
    },
  },
})
