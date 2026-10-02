// SPDX-License-Identifier: Apache-2.0
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue()],
  test: {
    name: 'ui',
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
  },
})
