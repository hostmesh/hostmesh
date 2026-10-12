// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'

import { apiBaseUrl } from './index'

describe('apiBaseUrl', () => {
  it.each([
    ['admin.hostmesh.test', 'https://api.hostmesh.test/api/v1'],
    ['app.hostmesh.test', 'https://api.hostmesh.test/api/v1'],
    ['app.hosting.example.co.uk', 'https://api.hosting.example.co.uk/api/v1'],
  ])('derives the API root of %s', (hostname, expected) => {
    expect(apiBaseUrl(hostname)).toBe(expected)
  })

  it('refuses a hostname without a subdomain', () => {
    expect(() => apiBaseUrl('localhost')).toThrow('localhost')
  })
})
