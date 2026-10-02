// SPDX-License-Identifier: Apache-2.0
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'api-client',
    environment: 'node',
    // Runtime tests only. The type-level tests (*.test-d.ts) are checked by
    // `pnpm typecheck`, which compiles everything in tsconfig.json once.
    include: ['src/**/*.test.ts'],
  },
})
