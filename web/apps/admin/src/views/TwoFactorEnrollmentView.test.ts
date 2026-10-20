// SPDX-License-Identifier: Apache-2.0
import { QrCode } from '@hostmesh/ui'
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { json, mockApi, noContent } from '../test/api'
import { userBody } from '../test/fixtures'
import { mountView, submitted } from '../test/view'
import TwoFactorEnrollmentView from './TwoFactorEnrollmentView.vue'

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

const enrollment = {
  secret: 'JBSWY3DPEHPK3PXP',
  provisioning_uri: 'otpauth://totp/Hostmesh:ada%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Hostmesh',
}
const codes = ['aaaaa-bbbbb', 'ccccc-ddddd', 'eeeee-fffff']

function routes(confirm: Response = json({ data: { recovery_codes: codes } })) {
  return {
    'POST /admin/auth/two-factor': json({ data: enrollment }),
    'POST /admin/auth/two-factor/confirm': confirm,
    'GET /admin/auth/user': json(userBody),
  }
}

describe('TwoFactorEnrollmentView', () => {
  it('starts enrollment and shows the QR code and the secret', async () => {
    const api = mockApi(routes())
    const { wrapper } = await mountView(TwoFactorEnrollmentView, '/two-factor/setup')

    expect(api.sent('POST /admin/auth/two-factor')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Set up two-factor authentication')
    expect(wrapper.getComponent(QrCode).props('value')).toBe(enrollment.provisioning_uri)
    expect(wrapper.get('[data-test="secret"]').text()).toBe(enrollment.secret)
  })

  it('labels the code field', async () => {
    mockApi(routes())
    const { wrapper } = await mountView(TwoFactorEnrollmentView, '/two-factor/setup')

    const input = wrapper.get('input')
    expect(wrapper.get(`label[for="${input.attributes('id')}"]`).text()).toBe('Authentication code')
    expect(input.attributes('autocomplete')).toBe('one-time-code')
  })

  it('says so when enrollment cannot be started', async () => {
    mockApi({ 'POST /admin/auth/two-factor': json({ message: 'Two-factor authentication is already on.' }, 409) })
    const { wrapper } = await mountView(TwoFactorEnrollmentView, '/two-factor/setup')

    expect(wrapper.get('[role="alert"]').text()).toBe('Two-factor authentication is already on.')
    expect(wrapper.findComponent(QrCode).exists()).toBe(false)
  })

  it('confirms with a code and shows the recovery codes once', async () => {
    const api = mockApi(routes())
    const { wrapper, router } = await mountView(TwoFactorEnrollmentView, '/two-factor/setup')

    await wrapper.get('input').setValue('123456')
    await wrapper.get('form').trigger('submit')
    await submitted()

    expect(await api.sent('POST /admin/auth/two-factor/confirm')[0]?.json()).toEqual({ code: '123456' })
    expect(wrapper.get('h1').text()).toBe('Save your recovery codes')
    expect(wrapper.findAll('[data-test="recovery-code"]').map((item) => item.text())).toEqual(codes)
    expect(document.activeElement).toBe(wrapper.get('h1').element)
    // Not moved on yet: the codes are still on screen.
    expect(router.currentRoute.value.path).toBe('/two-factor/setup')
    expect(api.sent('GET /admin/auth/user')).toHaveLength(0)
  })

  it('goes on after the user has saved the codes', async () => {
    mockApi(routes())
    const { wrapper, auth, router } = await mountView(TwoFactorEnrollmentView, '/two-factor/setup?redirect=/branding')
    await wrapper.get('input').setValue('123456')
    await wrapper.get('form').trigger('submit')
    await submitted()

    await wrapper.get('[data-test="finish"]').trigger('click')
    await flushPromises()

    expect(auth.status).toBe('authenticated')
    expect(router.currentRoute.value.fullPath).toBe('/branding')
  })

  it('shows a wrong code on the field and focuses it', async () => {
    const errors = { code: ['The code is not valid.'] }
    mockApi(routes(json({ message: errors.code[0], errors }, 422)))
    const { wrapper } = await mountView(TwoFactorEnrollmentView, '/two-factor/setup')

    await wrapper.get('input').setValue('000000')
    await wrapper.get('form').trigger('submit')
    await submitted()

    expect(wrapper.text()).toContain('The code is not valid.')
    expect(document.activeElement).toBe(wrapper.get('input').element)
    expect(wrapper.get('h1').text()).toBe('Set up two-factor authentication')
  })

  it('lets the user log out instead', async () => {
    const api = mockApi({ ...routes(), 'POST /admin/auth/logout': noContent() })
    const { wrapper, router } = await mountView(TwoFactorEnrollmentView, '/two-factor/setup')

    await wrapper.get('[data-test="cancel"]').trigger('click')
    await flushPromises()

    expect(api.sent('POST /admin/auth/logout')).toHaveLength(1)
    expect(router.currentRoute.value.path).toBe('/login')
  })
})
