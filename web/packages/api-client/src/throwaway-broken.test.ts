// SPDX-License-Identifier: Apache-2.0
import { expect, it } from 'vitest'

// Throwaway (issue #105): proves that a failing suite turns its gate red.
it('fails on purpose', () => {
  expect(1 + 1).toBe(3)
})
