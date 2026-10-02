// SPDX-License-Identifier: Apache-2.0
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  test: {
    name: 'admin',
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
  },
})
