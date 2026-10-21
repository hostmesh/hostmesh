// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it, vi } from 'vitest'

describe('the z of @hostmesh/ui', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  // The production policy has no 'unsafe-eval': a probe with `new Function`
  // raises a `securitypolicyviolation` event even when zod catches the error
  // (issue #144).
  it('never calls the Function constructor', async () => {
    const constructor = vi.spyOn(globalThis, 'Function')
    // Imported after the spy: zod probes once and keeps the answer.
    const { z } = await import('../../index')

    const schema = z.object({ email: z.email(), name: z.string().min(1) })

    expect(schema.parse({ email: 'owner@example.com', name: 'Owner' })).toEqual({ email: 'owner@example.com', name: 'Owner' })
    expect(constructor).not.toHaveBeenCalled()
  })
})
