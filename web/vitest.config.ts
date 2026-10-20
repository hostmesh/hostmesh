// SPDX-License-Identifier: Apache-2.0
import { defineConfig } from 'vitest/config'

import CoverageGuard from './coverage-guard'

export default defineConfig({
  test: {
    // Each project is configured by the Vite or Vitest config in its
    // directory, except `workspace`: the tooling at the root of the workspace.
    projects: [
      'apps/*',
      'packages/*',
      { test: { name: 'workspace', environment: 'node', include: ['*.test.ts'] } },
    ],
    // The guard acts on runs with coverage only (issue #145).
    reporters: ['default', new CoverageGuard()],
    // `pnpm test:coverage` (spec section 37, issue #121). One measurement
    // for the four projects: jsdom, Node and the browser all report to it.
    coverage: {
      provider: 'v8',
      // Every source file counts, also one that no test loads.
      include: ['apps/*/src/**/*.{ts,vue}', 'packages/*/src/**/*.{ts,vue}'],
      exclude: [
        '**/*.test.ts',
        '**/*.test-d.ts',
        // Test helpers and fixtures.
        '**/src/test/**',
        // Generated from the OpenAPI document; never edited (decision 0008).
        '**/generated/**',
        // Written by the shadcn-vue CLI and kept as upstream ships them
        // (decision 0009). Measuring them would count the parts of
        // shadcn-vue the apps do not use yet.
        'packages/ui/src/components/ui/**',
      ],
      reporter: ['text-summary'],
      // The run fails below any of these, over the workspace as a whole. On
      // a report that covers no file they pass; CoverageGuard fails it.
      thresholds: {
        statements: 95,
        branches: 95,
        functions: 95,
        lines: 95,
      },
    },
  },
})
