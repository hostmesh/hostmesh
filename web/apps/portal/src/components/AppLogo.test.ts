// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it, vi } from 'vitest'

import { useBrandingStore } from '../stores/branding'
import { brandingBody } from '../test/fixtures'
import { mountView, stubSystemColorScheme } from '../test/view'
import AppLogo from './AppLogo.vue'

afterEach(() => {
  vi.unstubAllGlobals()
  document.documentElement.classList.remove('dark')
  document.body.innerHTML = ''
})

describe('AppLogo', () => {
  it('shows the product name as text while there is no logo', async () => {
    const { wrapper } = await mountView(AppLogo)

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toBe('Hostmesh')
  })

  it('shows the light logo, named by the product', async () => {
    stubSystemColorScheme(false)
    const { wrapper } = await mountView(AppLogo)
    useBrandingStore().apply(brandingBody.data)
    await wrapper.vm.$nextTick()

    const image = wrapper.get('img')
    expect(image.attributes('src')).toBe(brandingBody.data.logos.light)
    expect(image.attributes('alt')).toBe('Acme Hosting')
  })

  it('shows the dark logo in dark mode', async () => {
    stubSystemColorScheme(true)
    const { wrapper } = await mountView(AppLogo)
    useBrandingStore().apply(brandingBody.data)
    await wrapper.vm.$nextTick()

    expect(wrapper.get('img').attributes('src')).toBe(brandingBody.data.logos.dark)
  })

  it('falls back to the name when the logo of the mode is not configured', async () => {
    stubSystemColorScheme(true)
    const { wrapper } = await mountView(AppLogo)
    useBrandingStore().apply({ ...brandingBody.data, logos: { light: brandingBody.data.logos.light, dark: null } })
    await wrapper.vm.$nextTick()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toBe('Acme Hosting')
  })
})
