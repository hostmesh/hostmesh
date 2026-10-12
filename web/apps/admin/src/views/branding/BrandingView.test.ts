// SPDX-License-Identifier: Apache-2.0
import type { paths } from '@hostmesh/api-client'
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { useBrandingStore } from '../../stores/branding'
import { json, mockApi } from '../../test/api'
import { brandingResource, signedInRoutes } from '../../test/fixtures'
import { mountView } from '../../test/view'
import BrandingForm from './BrandingForm.vue'
import BrandingView from './BrandingView.vue'

type ShowBody = paths['/admin/branding']['get']['responses'][200]['content']['application/json']

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('BrandingView', () => {
  it('loads branding and shows the form', async () => {
    mockApi({ ...signedInRoutes(), 'GET /admin/branding': json({ data: brandingResource } satisfies ShowBody) })
    const { wrapper } = await mountView(BrandingView, '/branding')

    expect(wrapper.get('h1').text()).toBe('Branding')
    expect(wrapper.getComponent(BrandingForm).props('branding')).toEqual(brandingResource)
  })

  it('says so when the administrator may not view branding', async () => {
    mockApi({ ...signedInRoutes(), 'GET /admin/branding': json({ message: 'This action is unauthorized.' }, 403) })
    const { wrapper } = await mountView(BrandingView, '/branding')

    expect(wrapper.findComponent(BrandingForm).exists()).toBe(false)
    expect(wrapper.get('[role="alert"]').text()).toBe('You do not have permission to view branding.')
  })

  it('offers to try again when branding cannot be loaded', async () => {
    const api = mockApi({
      ...signedInRoutes(),
      'GET /admin/branding': () => Promise.reject(new TypeError('Failed to fetch')),
    })
    const { wrapper } = await mountView(BrandingView, '/branding')
    expect(wrapper.get('[role="alert"]').text()).toContain('Branding could not be loaded.')

    api.routes['GET /admin/branding'] = json({ data: brandingResource } satisfies ShowBody)
    await wrapper.get('[data-test="retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.findComponent(BrandingForm).exists()).toBe(true)
  })

  it('shows the saved branding at once, in the form and in the header', async () => {
    mockApi({ ...signedInRoutes(), 'GET /admin/branding': json({ data: brandingResource } satisfies ShowBody) })
    const { wrapper } = await mountView(BrandingView, '/branding')
    const saved = { ...brandingResource, product_name: 'Acme Cloud', logos: { light: null, dark: null }, warnings: [] }

    wrapper.getComponent(BrandingForm).vm.$emit('saved', saved)
    await flushPromises()

    expect(wrapper.getComponent(BrandingForm).props('branding')).toEqual(saved)
    expect(useBrandingStore().branding.product_name).toBe('Acme Cloud')
    expect(wrapper.get('header').text()).toContain('Acme Cloud')
  })
})
