// SPDX-License-Identifier: Apache-2.0
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [
    vue(),
    tailwindcss(),
  ],
  // The development stack: Caddy proxies https://admin.hostmesh.test here
  // (infrastructure/development/Caddyfile). Vite refuses other Host headers.
  server: {
    host: true,
    port: 5173,
    strictPort: true,
    allowedHosts: [
      'admin.hostmesh.test',
    ],
  },
  test: {
    name: 'admin',
    environment: 'jsdom',
    // The page the tests run on: the API host and the CSRF cookie derive from it.
    environmentOptions: {
      jsdom: {
        url: 'https://admin.hostmesh.test/',
      },
    },
    include: ['src/**/*.test.ts'],
  },
})
