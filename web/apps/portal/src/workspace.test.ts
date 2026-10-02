// SPDX-License-Identifier: Apache-2.0
import { createApiClient } from '@hostmesh/api-client'
import * as ui from '@hostmesh/ui'
import { describe, expect, it } from 'vitest'

describe('shared workspace packages', () => {
  it('resolves the API client and the component package', () => {
    expect(createApiClient).toBeTypeOf('function')
    expect(ui).toBeDefined()
  })
})
