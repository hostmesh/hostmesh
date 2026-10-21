// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it, vi } from 'vitest'

import { z } from '../../index'

describe('the z of @hostmesh/ui', () => {
  afterEach(() => {
    vi.restoreAllMocks()
  })

  // The production policy has no 'unsafe-eval': a probe with `new Function`
  // raises a `securitypolicyviolation` event even when zod catches the error
  // (issue #144). zod probes when the first object schema is built, not on
  // import, and compiles the parser on its first parse: both come after the
  // spy.
  it('never calls the Function constructor', () => {
    const constructor = vi.spyOn(globalThis, 'Function')

    const schema = z.object({ email: z.email(), name: z.string().min(1) })

    expect(schema.parse({ email: 'owner@example.com', name: 'Owner' })).toEqual({ email: 'owner@example.com', name: 'Owner' })
    expect(constructor).not.toHaveBeenCalled()
  })
})
