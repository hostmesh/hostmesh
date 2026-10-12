// SPDX-License-Identifier: Apache-2.0
import { Toaster } from '@hostmesh/ui'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { json, mockApi } from './test/api'
import { mountApp } from './test/app'
import { brandingBody, guestRoutes, signedInRoutes } from './test/fixtures'

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
  document.title = ''
})

describe('customer portal', () => {
  it('mounts the toast host of the component package', async () => {
    mockApi(guestRoutes())
    const { wrapper } = await mountApp('/')

    expect(wrapper.findComponent(Toaster).exists()).toBe(true)
  })

  it('shows the product name and logo from the API on the login form', async () => {
    mockApi(guestRoutes())
    const { wrapper } = await mountApp('/login')

    expect(wrapper.get('img').attributes('alt')).toBe('Acme Hosting')
    expect(document.title).toBe('Acme Hosting')
  })

  it('shows them in the application layout too', async () => {
    mockApi(signedInRoutes())
    const { wrapper } = await mountApp('/')

    expect(wrapper.get('header img').attributes('src')).toBe(brandingBody.data.logos.light)
    expect(wrapper.get('header').text()).toContain('Ada Lovelace')
  })

  it('works with the defaults when branding cannot be loaded', async () => {
    mockApi({ ...guestRoutes(), 'GET /branding': json({ message: 'Server Error' }, 500) })
    const { wrapper } = await mountApp('/login')

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('Hostmesh')
    expect(document.title).toBe('Hostmesh')
    expect(wrapper.get('h1').text()).toBe('Log in')
  })
})
