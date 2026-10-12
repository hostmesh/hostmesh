// SPDX-License-Identifier: Apache-2.0
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { json, mockApi, noContent } from '../test/api'
import { userBody } from '../test/fixtures'
import { mountView, submitted } from '../test/view'
import TwoFactorChallengeView from './TwoFactorChallengeView.vue'

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

const passed = { 'POST /admin/auth/two-factor/challenge': noContent(), 'GET /admin/auth/user': json(userBody) }

function label(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper']): string {
  return wrapper.get(`label[for="${wrapper.get('input').attributes('id')}"]`).text()
}

describe('TwoFactorChallengeView', () => {
  it('asks for the code of the authenticator app', async () => {
    mockApi({})
    const { wrapper } = await mountView(TwoFactorChallengeView, '/two-factor')

    expect(wrapper.get('h1').text()).toBe('Two-factor authentication')
    expect(label(wrapper)).toBe('Authentication code')
    expect(wrapper.get('input').attributes('autocomplete')).toBe('one-time-code')
    expect(wrapper.get('input').attributes('inputmode')).toBe('numeric')
  })

  it('sends the code and goes on', async () => {
    const api = mockApi(passed)
    const { wrapper, auth, router } = await mountView(TwoFactorChallengeView, '/two-factor?redirect=/branding')

    await wrapper.get('input').setValue('123 456')
    await wrapper.get('form').trigger('submit')
    await submitted()

    expect(await api.sent('POST /admin/auth/two-factor/challenge')[0]?.json()).toEqual({ code: '123 456' })
    expect(auth.status).toBe('authenticated')
    expect(router.currentRoute.value.fullPath).toBe('/branding')
  })

  it('switches to a recovery code', async () => {
    const api = mockApi(passed)
    const { wrapper } = await mountView(TwoFactorChallengeView, '/two-factor')

    await wrapper.get('[data-test="toggle"]').trigger('click')
    expect(label(wrapper)).toBe('Recovery code')
    expect(wrapper.get('[data-test="toggle"]').text()).toBe('Use an authentication code')

    await wrapper.get('input').setValue('abcde-fghij')
    await wrapper.get('form').trigger('submit')
    await submitted()

    expect(await api.sent('POST /admin/auth/two-factor/challenge')[0]?.json()).toEqual({
      recovery_code: 'abcde-fghij',
    })
  })

  it('does not send an empty code', async () => {
    const api = mockApi({})
    const { wrapper } = await mountView(TwoFactorChallengeView, '/two-factor')

    await wrapper.get('form').trigger('submit')
    await submitted()

    expect(wrapper.get('input').attributes('aria-invalid')).toBe('true')
    expect(document.activeElement).toBe(wrapper.get('input').element)
    expect(api.requests).toHaveLength(0)
  })

  it.each(['code', 'recovery_code'])('shows the error the API reports under %s and focuses the field', async (field) => {
    mockApi({
      'POST /admin/auth/two-factor/challenge': json(
        { message: 'The code is not valid.', errors: { [field]: ['The code is not valid.'] } },
        422,
      ),
    })
    const { wrapper } = await mountView(TwoFactorChallengeView, '/two-factor')

    await wrapper.get('input').setValue('000000')
    await wrapper.get('form').trigger('submit')
    await submitted()

    expect(wrapper.text()).toContain('The code is not valid.')
    expect(document.activeElement).toBe(wrapper.get('input').element)
  })

  it('lets the user give up and log in as someone else', async () => {
    const api = mockApi({ 'POST /admin/auth/logout': noContent() })
    const { wrapper, auth, router } = await mountView(TwoFactorChallengeView, '/two-factor')

    await wrapper.get('[data-test="cancel"]').trigger('click')
    await flushPromises()

    expect(api.sent('POST /admin/auth/logout')).toHaveLength(1)
    expect(auth.status).toBe('guest')
    expect(router.currentRoute.value.path).toBe('/login')
  })
})
