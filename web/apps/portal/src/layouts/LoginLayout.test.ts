// SPDX-License-Identifier: Apache-2.0
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'

import { useBrandingStore } from '../stores/branding'
import { brandingBody } from '../test/fixtures'
import { mountView } from '../test/view'
import LoginLayout from './LoginLayout.vue'

const Page = defineComponent({
  setup: () => () => h(LoginLayout, null, { default: () => h('h1', 'Log in') }),
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('LoginLayout', () => {
  it('shows the product and the page in the main landmark', async () => {
    const { wrapper } = await mountView(Page, '/login')

    expect(wrapper.get('main').text()).toContain('Hostmesh')
    expect(wrapper.get('main h1').text()).toBe('Log in')
  })

  it('ends with the footer of branding, outside the main landmark', async () => {
    const { wrapper } = await mountView(Page, '/login')
    useBrandingStore().apply(brandingBody.data)
    await flushPromises()

    expect(wrapper.get('footer a').attributes('href')).toBe('https://acme.example/support')
    expect(wrapper.find('main footer').exists()).toBe(false)
  })
})
