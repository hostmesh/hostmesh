// SPDX-License-Identifier: Apache-2.0
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { api } from './api'
import { json, mockApi, noContent } from './test/api'
import { mountApp } from './test/app'
import { brandingBody, guestRoutes, loginBody, pending, signedInRoutes, unauthenticated } from './test/fixtures'
import { submitted } from './test/view'

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

function owing(step: 'challenge' | 'enrollment') {
  return {
    'GET /branding': json(brandingBody),
    'GET /admin/auth/user': pending(step),
    'POST /admin/auth/two-factor': json({ data: { secret: 'JBSWY3DPEHPK3PXP', provisioning_uri: 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP' } }),
  }
}

describe('the guard', () => {
  it('sends a guest to the login form', async () => {
    mockApi(guestRoutes())
    const { router, wrapper } = await mountApp('/')

    expect(router.currentRoute.value.fullPath).toBe('/login')
    expect(wrapper.get('h1').text()).toBe('Log in')
  })

  it('remembers where a guest wanted to go', async () => {
    mockApi(guestRoutes())
    const { router } = await mountApp('/?tab=nodes')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/?tab=nodes')
  })

  it('shows the start page to a logged-in user', async () => {
    mockApi(signedInRoutes())
    const { router, wrapper } = await mountApp('/')

    expect(router.currentRoute.value.fullPath).toBe('/')
    expect(wrapper.get('main h1').text()).toBe('Dashboard')
  })

  it('sends a session that owes a code to the challenge', async () => {
    mockApi(owing('challenge'))
    const { router, wrapper } = await mountApp('/')

    expect(router.currentRoute.value.name).toBe('two-factor-challenge')
    expect(wrapper.get('h1').text()).toBe('Two-factor authentication')
  })

  it('sends a session that must enroll to the setup', async () => {
    mockApi(owing('enrollment'))
    const { router, wrapper } = await mountApp('/login')

    expect(router.currentRoute.value.name).toBe('two-factor-setup')
    expect(wrapper.get('h1').text()).toBe('Set up two-factor authentication')
  })

  it.each(['/login', '/two-factor', '/two-factor/setup'])('keeps a logged-in user away from %s', async (path) => {
    mockApi(signedInRoutes())
    const { router } = await mountApp(path)

    expect(router.currentRoute.value.fullPath).toBe('/')
  })

  it('does not follow a redirect to another site for a logged-in user', async () => {
    mockApi(signedInRoutes())
    const { router } = await mountApp('/login?redirect=//evil.example')

    expect(router.currentRoute.value.fullPath).toBe('/')
  })

  it('keeps a guest away from the two-factor screens', async () => {
    mockApi(guestRoutes())
    const { router } = await mountApp('/two-factor/setup')

    expect(router.currentRoute.value.path).toBe('/login')
  })

  it('sends an unknown path to the start page', async () => {
    mockApi(signedInRoutes())
    const { router } = await mountApp('/no/such/page')

    expect(router.currentRoute.value.fullPath).toBe('/')
  })

  it('asks the API for the session once', async () => {
    const mock = mockApi(signedInRoutes())
    const { router } = await mountApp('/')

    await router.push('/login')
    await router.push('/')

    expect(mock.sent('GET /admin/auth/user')).toHaveLength(1)
  })
})

describe('logging in through the app', () => {
  it('goes from the login form through the challenge to the page asked for', async () => {
    const mock = mockApi({
      ...guestRoutes(),
      'GET /admin/auth/csrf-cookie': noContent(),
      'POST /admin/auth/login': json(loginBody('challenge')),
      'POST /admin/auth/two-factor/challenge': noContent(),
    })
    const { router, wrapper, auth } = await mountApp('/?tab=nodes')

    await wrapper.get('input[type="email"]').setValue('ada@example.com')
    await wrapper.get('input[type="password"]').setValue('correct-password')
    await wrapper.get('form').trigger('submit')
    await submitted()

    expect(router.currentRoute.value.name).toBe('two-factor-challenge')
    expect(router.currentRoute.value.query.redirect).toBe('/?tab=nodes')

    mock.routes['GET /admin/auth/user'] = signedInRoutes()['GET /admin/auth/user'] as Response
    await wrapper.get('input').setValue('123456')
    await wrapper.get('form').trigger('submit')
    await submitted()

    expect(auth.status).toBe('authenticated')
    expect(router.currentRoute.value.fullPath).toBe('/?tab=nodes')
  })

  it('moves focus to the heading of the new page', async () => {
    mockApi({
      ...guestRoutes(),
      'GET /admin/auth/csrf-cookie': noContent(),
      'POST /admin/auth/login': json(loginBody('challenge')),
    })
    const { wrapper } = await mountApp('/')

    await wrapper.get('input[type="email"]').setValue('ada@example.com')
    await wrapper.get('input[type="password"]').setValue('correct-password')
    await wrapper.get('form').trigger('submit')
    await submitted()

    expect(wrapper.get('h1').text()).toBe('Two-factor authentication')
    expect(document.activeElement).toBe(wrapper.get('h1').element)
  })
})

describe('a session that ends', () => {
  it('goes to the login form when a call answers 401', async () => {
    const mock = mockApi(signedInRoutes())
    const { router, auth } = await mountApp('/?tab=nodes')
    expect(auth.status).toBe('authenticated')

    mock.routes['GET /admin/auth/user'] = unauthenticated()
    await api.GET('/admin/auth/user')
    await flushPromises()

    expect(auth.status).toBe('guest')
    expect(auth.user).toBeNull()
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/?tab=nodes')
  })
})
