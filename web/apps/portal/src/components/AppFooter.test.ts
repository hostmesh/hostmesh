// SPDX-License-Identifier: Apache-2.0
import type { DOMWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'

import { useBrandingStore } from '../stores/branding'
import { brandingBody } from '../test/fixtures'
import { mountView } from '../test/view'
import AppFooter from './AppFooter.vue'

afterEach(() => {
  document.body.innerHTML = ''
})

const allLinks = {
  support: 'https://acme.example/support',
  terms: 'https://acme.example/terms',
  privacy: 'https://acme.example/privacy',
}

function links(footer: Pick<DOMWrapper<Element>, 'findAll'>): [string, string | undefined][] {
  return footer.findAll('a').map((link) => [link.text(), link.attributes('href')])
}

describe('AppFooter', () => {
  it('renders nothing while branding has no link', async () => {
    const { wrapper } = await mountView(AppFooter)

    expect(wrapper.find('footer').exists()).toBe(false)
  })

  it('links to support, the terms and the privacy policy', async () => {
    const { wrapper } = await mountView(AppFooter)
    useBrandingStore().apply({ ...brandingBody.data, links: allLinks })
    await wrapper.vm.$nextTick()

    expect(links(wrapper.get('footer'))).toEqual([
      ['Support', 'https://acme.example/support'],
      ['Terms of service', 'https://acme.example/terms'],
      ['Privacy policy', 'https://acme.example/privacy'],
    ])
  })

  it('leaves out a link that is not set', async () => {
    const { wrapper } = await mountView(AppFooter)
    useBrandingStore().apply({ ...brandingBody.data, links: { ...allLinks, terms: null } })
    await wrapper.vm.$nextTick()

    expect(links(wrapper.get('footer'))).toEqual([
      ['Support', 'https://acme.example/support'],
      ['Privacy policy', 'https://acme.example/privacy'],
    ])
  })
})
