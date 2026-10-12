// SPDX-License-Identifier: Apache-2.0
import type { components, paths } from '@hostmesh/api-client'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { json, mockApi } from '../../test/api'
import { brandingResource } from '../../test/fixtures'
import { submitted } from '../../test/view'
import BrandingForm from './BrandingForm.vue'

type UpdateBody = paths['/admin/branding']['put']['responses'][200]['content']['application/json']

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

// Typed by the schema: the fixture's own type has `update: true`, not `boolean`.
function mountForm(branding: components['schemas']['BrandingResource'] = brandingResource) {
  return mount(BrandingForm, { props: { branding }, attachTo: document.body })
}

// The input of the field with this label.
function field(wrapper: ReturnType<typeof mountForm>, label: string) {
  const match = wrapper.findAll('label').find((candidate) => candidate.text() === label)
  if (!match) throw new Error(`No field is labelled "${label}".`)
  return wrapper.get(`[id="${match.attributes('for')}"]`)
}

async function submit(wrapper: ReturnType<typeof mountForm>) {
  await wrapper.get('form').trigger('submit')
  await submitted()
}

describe('BrandingForm', () => {
  it('shows the current branding in labelled fields', () => {
    const wrapper = mountForm()

    expect((field(wrapper, 'Product name').element as HTMLInputElement).value).toBe('Acme Hosting')
    expect((field(wrapper, 'Primary color, light mode').element as HTMLInputElement).value).toBe('#005f73')
    expect((field(wrapper, 'Text on primary, light mode').element as HTMLInputElement).value).toBe('#ffffff')
    expect((field(wrapper, 'Primary color, dark mode').element as HTMLInputElement).value).toBe('#94d2bd')
    expect((field(wrapper, 'Text on primary, dark mode').element as HTMLInputElement).value).toBe('#001219')
    expect((field(wrapper, 'Support link').element as HTMLInputElement).value).toBe('https://acme.example/support')
    expect((field(wrapper, 'Terms of service link').element as HTMLInputElement).value).toBe('')
    expect((field(wrapper, 'Privacy policy link').element as HTMLInputElement).value).toBe('')
  })

  it('lists the contrast warnings in words', () => {
    const wrapper = mountForm()

    const warnings = wrapper.get('[role="status"]')
    expect(warnings.findAll('li')).toHaveLength(1)
    expect(warnings.text()).toContain('Dark mode: the text color on primary has a contrast of 2.13:1')
  })

  it('has no warning list when every pair passes', () => {
    const wrapper = mountForm({ ...brandingResource, warnings: [] })

    expect(wrapper.get('[role="status"]').findAll('li')).toHaveLength(0)
  })

  it('saves every field, null for an empty one, and reports the saved branding', async () => {
    const saved = { ...brandingResource, product_name: 'Acme Cloud', warnings: [] }
    const api = mockApi({ 'PUT /admin/branding': json({ data: saved } satisfies UpdateBody) })
    const wrapper = mountForm()

    await field(wrapper, 'Product name').setValue('Acme Cloud')
    await field(wrapper, 'Support link').setValue('')
    await submit(wrapper)

    expect(await api.sent('PUT /admin/branding')[0]?.json()).toEqual({
      product_name: 'Acme Cloud',
      colors: brandingResource.colors,
      links: { support: null, terms: null, privacy: null },
    })
    expect(wrapper.emitted('saved')).toEqual([[saved]])
  })

  it('saves a color that fails the contrast check: the warning never blocks', async () => {
    const saved = {
      ...brandingResource,
      colors: { ...brandingResource.colors, light: { primary: '#ffff00', primary_foreground: '#ffffff' } },
      warnings: [{ mode: 'light', color: 'primary_foreground', against: 'primary', ratio: 1.07, required: 4.5 }],
    }
    mockApi({ 'PUT /admin/branding': json({ data: saved } satisfies UpdateBody) })
    const wrapper = mountForm()

    await field(wrapper, 'Primary color, light mode').setValue('#ffff00')
    await submit(wrapper)
    await wrapper.setProps({ branding: saved })

    expect(wrapper.emitted('saved')).toHaveLength(1)
    expect(wrapper.get('[role="status"]').text()).toContain('Light mode: the text color on primary has a contrast of 1.07:1')
  })

  it('validates before it sends anything, and focuses the first invalid field', async () => {
    const api = mockApi({})
    const wrapper = mountForm()

    await field(wrapper, 'Primary color, light mode').setValue('teal')
    await field(wrapper, 'Support link').setValue('http://acme.example')
    await submit(wrapper)

    const color = field(wrapper, 'Primary color, light mode')
    expect(color.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Enter a color as #rrggbb')
    expect(wrapper.text()).toContain('Enter an address that starts with https://')
    expect(document.activeElement).toBe(color.element)
    expect(api.requests).toHaveLength(0)
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('puts an error of the API on its field and focuses it', async () => {
    const errors = { 'links.terms': ['The links.terms field must be a valid URL.'] }
    mockApi({ 'PUT /admin/branding': json({ message: errors['links.terms'][0], errors }, 422) })
    const wrapper = mountForm()

    await field(wrapper, 'Terms of service link').setValue('https://acme')
    await submit(wrapper)

    const terms = field(wrapper, 'Terms of service link')
    expect(terms.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('The links.terms field must be a valid URL.')
    expect(document.activeElement).toBe(terms.element)
  })

  it('announces a failure that belongs to no field', async () => {
    mockApi({ 'PUT /admin/branding': json({ message: 'This action is unauthorized.' }, 403) })
    const wrapper = mountForm()

    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toBe('This action is unauthorized.')
  })

  it('is read-only for an administrator who may not update branding', () => {
    const wrapper = mountForm({ ...brandingResource, abilities: { view: true, update: false } })

    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined()
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
  })
})
