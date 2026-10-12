// SPDX-License-Identifier: Apache-2.0
import { flushPromises } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { api } from './api'
import { json, mockApi, noContent } from './test/api'
import { mountApp } from './test/app'
import { brandingBody, guestRoutes, loginBody, pending, signedInRoutes, unauthenticated, userBody } from './test/fixtures'
import { submitted } from './test/view'

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('the guard', () => {
  it('sends a guest to the login form and remembers the page', async () => {
    mockApi(guestRoutes())
    const { router, wrapper } = await mountApp('/?tab=websites')

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/?tab=websites')
    expect(wrapper.get('h1').text()).toBe('Log in')
  })

  it('shows the start page to a logged-in customer', async () => {
    mockApi(signedInRoutes())
    const { router, wrapper } = await mountApp('/')

    expect(router.currentRoute.value.fullPath).toBe('/')
    expect(wrapper.get('main h1').text()).toBe('Dashboard')
  })

  it('sends a session that owes a code to the challenge', async () => {
    mockApi({ 'GET /branding': json(brandingBody), 'GET /customer/auth/user': pending('challenge') })
    const { router } = await mountApp('/')

    expect(router.currentRoute.value.name).toBe('two-factor-challenge')
  })

  it.each(['/login', '/two-factor', '/login?redirect=//evil.example'])(
    'keeps a logged-in customer away from %s',
    async (path) => {
      mockApi(signedInRoutes())
      const { router } = await mountApp(path)

      expect(router.currentRoute.value.fullPath).toBe('/')
    },
  )

  it('has no enrollment screen: the path is unknown', async () => {
    mockApi(signedInRoutes())
    const { router } = await mountApp('/two-factor/setup')

    expect(router.currentRoute.value.fullPath).toBe('/')
  })
})

describe('logging in through the app', () => {
  it('goes from the login form through the challenge to the page asked for', async () => {
    const mock = mockApi({
      ...guestRoutes(),
      'GET /customer/auth/csrf-cookie': noContent(),
      'POST /customer/auth/login': json(loginBody('challenge')),
      'POST /customer/auth/two-factor/challenge': noContent(),
    })
    const { router, wrapper, auth } = await mountApp('/?tab=websites')

    await wrapper.get('input[type="email"]').setValue('ada@example.com')
    await wrapper.get('input[type="password"]').setValue('correct-password')
    await wrapper.get('form').trigger('submit')
    await submitted()
    expect(router.currentRoute.value.name).toBe('two-factor-challenge')
    expect(document.activeElement).toBe(wrapper.get('h1').element)

    mock.routes['GET /customer/auth/user'] = json(userBody)
    await wrapper.get('input').setValue('123456')
    await wrapper.get('form').trigger('submit')
    await submitted()

    expect(auth.status).toBe('authenticated')
    expect(router.currentRoute.value.fullPath).toBe('/?tab=websites')
  })
})

describe('a session that ends', () => {
  it('goes to the login form when a call answers 401', async () => {
    const mock = mockApi(signedInRoutes())
    const { router, auth } = await mountApp('/?tab=websites')

    mock.routes['GET /customer/auth/user'] = unauthenticated()
    await api.GET('/customer/auth/user')
    await flushPromises()

    expect(auth.status).toBe('guest')
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe('/?tab=websites')
  })
})
