// SPDX-License-Identifier: Apache-2.0
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { json, mockApi } from '../test/api'
import { brandingBody } from '../test/fixtures'
import { DEFAULT_BRANDING, useBrandingStore } from './branding'

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('branding store', () => {
  it('starts with the built-in defaults', () => {
    const store = useBrandingStore()

    expect(store.branding).toEqual(DEFAULT_BRANDING)
    expect(store.branding.product_name).toBe('Hostmesh')
    expect(store.branding.logos).toEqual({ light: null, dark: null })
  })

  it('loads the branding of the installation', async () => {
    mockApi({ 'GET /branding': json(brandingBody) })
    const store = useBrandingStore()

    await store.load()

    expect(store.branding).toEqual(brandingBody.data)
  })

  it.each([
    ['answers 500', () => json({ message: 'Server Error' }, 500)],
    ['cannot be reached', () => Promise.reject(new TypeError('Failed to fetch'))],
  ])('keeps the defaults when the request %s', async (_name, reply) => {
    mockApi({ 'GET /branding': reply })
    const store = useBrandingStore()

    await store.load()

    expect(store.branding).toEqual(DEFAULT_BRANDING)
  })

  it('takes a branding the admin screen just saved', () => {
    const store = useBrandingStore()

    store.apply(brandingBody.data)

    expect(store.branding.product_name).toBe('Acme Hosting')
  })
})
