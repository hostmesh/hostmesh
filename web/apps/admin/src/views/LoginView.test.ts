// SPDX-License-Identifier: Apache-2.0
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { json, mockApi, noContent } from '../test/api'
import { loginBody } from '../test/fixtures'
import { mountView, submitted } from '../test/view'
import LoginView from './LoginView.vue'

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

function loginRoutes(reply: Response) {
  return { 'GET /admin/auth/csrf-cookie': noContent(), 'POST /admin/auth/login': reply }
}

async function fillAndSubmit(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper'], email: string, password: string) {
  await wrapper.get('input[type="email"]').setValue(email)
  await wrapper.get('input[type="password"]').setValue(password)
  await wrapper.get('form').trigger('submit')
  await submitted()
}

describe('LoginView', () => {
  it('labels both fields and names the page', async () => {
    mockApi({})
    const { wrapper } = await mountView(LoginView, '/login')

    const email = wrapper.get('input[type="email"]')
    const password = wrapper.get('input[type="password"]')
    expect(wrapper.get(`label[for="${email.attributes('id')}"]`).text()).toBe('Email')
    expect(wrapper.get(`label[for="${password.attributes('id')}"]`).text()).toBe('Password')
    expect(email.attributes('autocomplete')).toBe('username')
    expect(password.attributes('autocomplete')).toBe('current-password')
    expect(wrapper.get('h1').text()).toBe('Log in')
  })

  it('logs in and goes to the start page', async () => {
    const api = mockApi(loginRoutes(json(loginBody('none'))))
    const { wrapper, auth, router } = await mountView(LoginView, '/login')

    await fillAndSubmit(wrapper, 'ada@example.com', 'correct-password')

    expect(await api.sent('POST /admin/auth/login')[0]?.json()).toEqual({
      email: 'ada@example.com',
      password: 'correct-password',
    })
    expect(auth.status).toBe('authenticated')
    expect(router.currentRoute.value.fullPath).toBe('/')
  })

  it('goes back to the page the user came for', async () => {
    mockApi(loginRoutes(json(loginBody('none'))))
    const { wrapper, router } = await mountView(LoginView, '/login?redirect=/branding')

    await fillAndSubmit(wrapper, 'ada@example.com', 'correct-password')

    expect(router.currentRoute.value.fullPath).toBe('/branding')
  })

  it('does not follow a redirect to another site', async () => {
    mockApi(loginRoutes(json(loginBody('none'))))
    const { wrapper, router } = await mountView(LoginView, '/login?redirect=//evil.example')

    await fillAndSubmit(wrapper, 'ada@example.com', 'correct-password')

    expect(router.currentRoute.value.fullPath).toBe('/')
  })

  it('validates before it sends anything, and focuses the first invalid field', async () => {
    const api = mockApi({})
    const { wrapper } = await mountView(LoginView, '/login')

    await wrapper.get('form').trigger('submit')
    await submitted()

    const email = wrapper.get('input[type="email"]')
    expect(email.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Enter a valid email address.')
    expect(wrapper.text()).toContain('Enter your password.')
    expect(document.activeElement).toBe(email.element)
    expect(api.requests).toHaveLength(0)
  })

  it('shows wrong credentials on the email field and focuses it', async () => {
    const errors = { email: ['These credentials do not match our records.'] }
    mockApi(loginRoutes(json({ message: errors.email[0], errors }, 422)))
    const { wrapper, router } = await mountView(LoginView, '/login')

    await fillAndSubmit(wrapper, 'ada@example.com', 'wrong')

    const email = wrapper.get('input[type="email"]')
    expect(email.attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('These credentials do not match our records.')
    expect(document.activeElement).toBe(email.element)
    expect(router.currentRoute.value.path).toBe('/login')
  })

  it('announces a message that belongs to no field', async () => {
    mockApi(loginRoutes(json({ message: 'Too Many Attempts.' }, 429)))
    const { wrapper } = await mountView(LoginView, '/login')

    await fillAndSubmit(wrapper, 'ada@example.com', 'correct-password')

    expect(wrapper.get('form > [role="alert"]').text()).toBe('Too many attempts. Wait a minute and try again.')
  })

  it('disables the button while the request is on its way', async () => {
    let answer: (response: Response) => void = () => undefined
    mockApi({
      'GET /admin/auth/csrf-cookie': noContent(),
      'POST /admin/auth/login': () => new Promise<Response>((resolve) => (answer = resolve)),
    })
    const { wrapper } = await mountView(LoginView, '/login')

    await fillAndSubmit(wrapper, 'ada@example.com', 'correct-password')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()

    answer(json(loginBody('none')))
    await flushPromises()
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })
})
