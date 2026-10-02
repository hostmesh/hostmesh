// SPDX-License-Identifier: Apache-2.0
import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    name: 'api-client',
    environment: 'node',
    include: ['src/**/*.test.ts'],
    // Type-level tests: Vitest runs tsc over them and reports each type
    // error as a failed test.
    typecheck: {
      enabled: true,
      include: ['src/**/*.test-d.ts'],
      tsconfig: './tsconfig.json',
    },
  },
})
