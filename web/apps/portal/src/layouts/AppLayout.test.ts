// SPDX-License-Identifier: Apache-2.0
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import { mockApi, noContent } from '../test/api'
import { signedInRoutes } from '../test/fixtures'
import { mountView } from '../test/view'
import AppLayout from './AppLayout.vue'

const Page = defineComponent({
  setup: () => () => h(AppLayout, null, { default: () => h('h1', 'Dashboard') }),
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('AppLayout', () => {
  it('frames the page with the product, the user and a main landmark', async () => {
    mockApi(signedInRoutes())
    const { wrapper, auth } = await mountView(Page, '/somewhere')
    await auth.restore()
    await flushPromises()

    expect(wrapper.get('header').text()).toContain('Hostmesh')
    expect(wrapper.get('header').text()).toContain('Ada Lovelace')
    expect(wrapper.get('main h1').text()).toBe('Dashboard')
  })

  it('logs out and goes to the login form', async () => {
    const api = mockApi({ ...signedInRoutes(), 'POST /customer/auth/logout': noContent() })
    const { wrapper, auth, router } = await mountView(Page, '/somewhere')
    await auth.restore()
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(wrapper.get('button').text()).toBe('Log out')
    expect(api.sent('POST /customer/auth/logout')).toHaveLength(1)
    expect(auth.status).toBe('guest')
    expect(router.currentRoute.value.path).toBe('/login')
  })

  it('stays put when logging out fails', async () => {
    mockApi({
      ...signedInRoutes(),
      'POST /customer/auth/logout': () => Promise.reject(new TypeError('Failed to fetch')),
    })
    const { wrapper, auth, router } = await mountView(Page, '/somewhere')
    await auth.restore()
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(auth.status).toBe('authenticated')
    expect(router.currentRoute.value.path).toBe('/somewhere')
  })
})
