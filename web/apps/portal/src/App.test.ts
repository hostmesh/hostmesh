// SPDX-License-Identifier: Apache-2.0
import { Toaster } from '@hostmesh/ui'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { stubSystemColorScheme } from './test/view'
import { json, mockApi } from './test/api'
import { mountApp } from './test/app'
import { brandingBody, guestRoutes, signedInRoutes } from './test/fixtures'

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
  document.title = ''
  document.documentElement.removeAttribute('style')
  document.documentElement.classList.remove('dark')
  document.head.querySelectorAll('link[rel="icon"]').forEach((icon) => icon.remove())
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

// Spec section 29, theming layer 2, and section 37 ("Themability is tested").
describe('brand colors', () => {
  function token(name: string): string {
    return document.documentElement.style.getPropertyValue(name)
  }

  it('overrides the primary tokens on the root element from branding', async () => {
    stubSystemColorScheme(false)
    mockApi(guestRoutes())
    await mountApp('/login')

    expect(token('--primary')).toBe('#005f73')
    expect(token('--primary-foreground')).toBe('#ffffff')
  })

  it('uses the dark pair in dark mode', async () => {
    stubSystemColorScheme(true)
    mockApi(guestRoutes())
    await mountApp('/login')

    expect(token('--primary')).toBe('#94d2bd')
    expect(token('--primary-foreground')).toBe('#001219')
  })

  it('leaves the tokens alone when branding fails to load', async () => {
    mockApi({ ...guestRoutes(), 'GET /branding': () => Promise.reject(new TypeError('Failed to fetch')) })
    const { wrapper } = await mountApp('/login')

    expect(document.documentElement.getAttribute('style') ?? '').toBe('')
    expect(wrapper.get('h1').text()).toBe('Log in')
  })

  it('sets the favicon from branding', async () => {
    mockApi(guestRoutes())
    await mountApp('/login')

    expect(document.head.querySelector<HTMLLinkElement>('link[rel="icon"]')?.href).toBe(brandingBody.data.favicon)
  })
})
