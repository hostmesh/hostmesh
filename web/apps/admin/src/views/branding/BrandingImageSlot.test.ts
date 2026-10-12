// SPDX-License-Identifier: Apache-2.0
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { api } from '../../api'
import { UNREACHABLE } from '../../lib/result'
import { brandingResource } from '../../test/fixtures'
import BrandingImageSlot from './BrandingImageSlot.vue'

afterEach(() => {
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

const file = new File(['png'], 'logo.png', { type: 'image/png' })

function mountSlot(props: Partial<InstanceType<typeof BrandingImageSlot>['$props']> = {}) {
  return mount(BrandingImageSlot, {
    props: {
      name: 'logo-light',
      label: 'Logo, light mode',
      hint: 'PNG, JPEG or WebP, at most 1 MB and 2000 by 2000 pixels.',
      url: brandingResource.logos.light,
      canUpdate: true,
      ...props,
    },
    attachTo: document.body,
  })
}

// What a call of the client resolves to. The spies take any shape: only this file builds one.
function answer(status: number, body: unknown) {
  const ok = status >= 200 && status < 300
  return { data: ok ? body : undefined, error: ok ? undefined : body, response: new Response(null, { status }) } as never
}

async function choose(wrapper: ReturnType<typeof mountSlot>, chosen: File) {
  const input = wrapper.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: [chosen], configurable: true })
  await input.trigger('change')
  await flushPromises()
}

describe('BrandingImageSlot', () => {
  it('labels the file input and describes it by its hint', () => {
    const wrapper = mountSlot()

    const input = wrapper.get('input[type="file"]')
    expect(wrapper.get(`label[for="${input.attributes('id')}"]`).text()).toBe('Logo, light mode')
    const hint = wrapper.get(`[id="${input.attributes('aria-describedby')}"]`)
    expect(hint.text()).toBe('PNG, JPEG or WebP, at most 1 MB and 2000 by 2000 pixels.')
  })

  it('shows the current image, or says that there is none', async () => {
    const wrapper = mountSlot()
    expect(wrapper.get('img').attributes('src')).toBe(brandingResource.logos.light)
    expect(wrapper.get('img').attributes('alt')).toBe('Current image: Logo, light mode')

    await wrapper.setProps({ url: null })
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('No image. The default is used.')
    expect(wrapper.find('[data-test="remove"]').exists()).toBe(false)
  })

  it('uploads the chosen file to its slot and reports the new branding', async () => {
    const next = { ...brandingResource, logos: { ...brandingResource.logos, light: 'https://api.hostmesh.test/x?v=new' } }
    const post = vi.spyOn(api, 'POST').mockResolvedValue(answer(200, { data: next }))
    const wrapper = mountSlot()

    await choose(wrapper, file)

    expect(post).toHaveBeenCalledOnce()
    const [path, init] = post.mock.calls[0] as unknown as [string, { params: unknown, bodySerializer: () => FormData }]
    expect(path).toBe('/admin/branding/images/{slot}')
    expect(init.params).toEqual({ path: { slot: 'logo-light' } })
    expect(init.bodySerializer().get('file')).toBe(file)
    expect(wrapper.emitted('changed')).toEqual([[next]])
  })

  it('shows why the API refused the file, on the input', async () => {
    vi.spyOn(api, 'POST').mockResolvedValue(
      answer(422, { message: 'x', errors: { file: ['The logo must be a PNG, JPEG or WebP file.'] } }),
    )
    const wrapper = mountSlot()

    await choose(wrapper, file)

    const input = wrapper.get('input[type="file"]')
    expect(input.attributes('aria-invalid')).toBe('true')
    const described = (input.attributes('aria-describedby') ?? '').split(' ')
    const error = wrapper.get('[role="alert"]')
    expect(error.text()).toBe('The logo must be a PNG, JPEG or WebP file.')
    expect(described).toContain(error.attributes('id'))
    expect(wrapper.emitted('changed')).toBeUndefined()
  })

  it('reports an upload that got no answer', async () => {
    vi.spyOn(api, 'POST').mockRejectedValue(new TypeError('Failed to fetch'))
    const wrapper = mountSlot()

    await choose(wrapper, file)

    expect(wrapper.get('[role="alert"]').text()).toBe(UNREACHABLE)
  })

  it('lets the same file be chosen again after a failure', async () => {
    vi.spyOn(api, 'POST').mockResolvedValue(answer(422, { message: 'x', errors: { file: ['Too large.'] } }))
    const wrapper = mountSlot()

    await choose(wrapper, file)

    expect((wrapper.get('input[type="file"]').element as HTMLInputElement).value).toBe('')
  })

  it('removes the image and reports the new branding', async () => {
    const next = { ...brandingResource, logos: { ...brandingResource.logos, light: null } }
    const remove = vi.spyOn(api, 'DELETE').mockResolvedValue(answer(200, { data: next }))
    const wrapper = mountSlot()

    await wrapper.get('[data-test="remove"]').trigger('click')
    await flushPromises()

    expect(remove).toHaveBeenCalledWith('/admin/branding/images/{slot}', { params: { path: { slot: 'logo-light' } } })
    expect(wrapper.emitted('changed')).toEqual([[next]])
  })

  it('offers neither upload nor removal without the update ability', () => {
    const wrapper = mountSlot({ canUpdate: false })

    expect(wrapper.find('input[type="file"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="remove"]').exists()).toBe(false)
    expect(wrapper.get('img').attributes('src')).toBe(brandingResource.logos.light)
  })
})
