// SPDX-License-Identifier: Apache-2.0
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { UNREACHABLE } from '../lib/result'
import { json, mockApi, noContent } from '../test/api'
import { account, loginBody, pending, unauthenticated, userBody } from '../test/fixtures'
import { useAuthStore } from './auth'

const credentials = { email: 'ada@example.com', password: 'correct-password' }

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('restore', () => {
  it('restores a complete session with its user and abilities', async () => {
    mockApi({ 'GET /customer/auth/user': json(userBody) })
    const auth = useAuthStore()

    await auth.restore()

    expect(auth.status).toBe('authenticated')
    expect(auth.user).toEqual(account)
  })

  it('is a guest without a session', async () => {
    mockApi({ 'GET /customer/auth/user': unauthenticated() })
    const auth = useAuthStore()

    await auth.restore()

    expect(auth.status).toBe('guest')
  })

  it('restores a session that still owes its challenge', async () => {
    mockApi({ 'GET /customer/auth/user': pending('challenge') })
    const auth = useAuthStore()

    await auth.restore()

    expect(auth.status).toBe('challenge')
  })

  it('is a guest when the API cannot be reached', async () => {
    mockApi({ 'GET /customer/auth/user': () => Promise.reject(new TypeError('Failed to fetch')) })
    const auth = useAuthStore()

    await auth.restore()

    expect(auth.status).toBe('guest')
  })
})

describe('login', () => {
  it('fetches the CSRF cookie of the customer realm, then posts the credentials', async () => {
    const api = mockApi({
      'GET /customer/auth/csrf-cookie': noContent(),
      'POST /customer/auth/login': json(loginBody('none')),
    })
    const auth = useAuthStore()

    const result = await auth.login(credentials)

    expect(result.ok).toBe(true)
    expect(api.requests.map((request) => new URL(request.url).pathname)).toEqual([
      '/api/v1/customer/auth/csrf-cookie',
      '/api/v1/customer/auth/login',
    ])
    expect(auth.status).toBe('authenticated')
    expect(auth.user).toEqual(account)
  })

  it('echoes the portal CSRF cookie, not the admin one', async () => {
    document.cookie = 'XSRF-TOKEN-ADMIN=admin-token'
    document.cookie = 'XSRF-TOKEN-PORTAL=portal-token'
    const api = mockApi({
      'GET /customer/auth/csrf-cookie': noContent(),
      'POST /customer/auth/login': json(loginBody('none')),
    })

    await useAuthStore().login(credentials)

    expect(api.sent('POST /customer/auth/login')[0]?.headers.get('X-XSRF-TOKEN')).toBe('portal-token')
  })

  it('owes the challenge when the customer has two-factor on', async () => {
    mockApi({
      'GET /customer/auth/csrf-cookie': noContent(),
      'POST /customer/auth/login': json(loginBody('challenge')),
    })
    const auth = useAuthStore()

    await auth.login(credentials)

    expect(auth.status).toBe('challenge')
    expect(auth.user).toBeNull()
  })

  it('returns the field errors of wrong credentials', async () => {
    const errors = { email: ['These credentials do not match our records.'] }
    mockApi({
      'GET /customer/auth/csrf-cookie': noContent(),
      'POST /customer/auth/login': json({ message: errors.email[0], errors }, 422),
    })

    expect(await useAuthStore().login(credentials)).toEqual({ ok: false, errors, message: '' })
  })

  it('reports an API that cannot be reached', async () => {
    mockApi({ 'GET /customer/auth/csrf-cookie': () => Promise.reject(new TypeError('Failed to fetch')) })

    expect(await useAuthStore().login(credentials)).toEqual({ ok: false, errors: {}, message: UNREACHABLE })
  })
})

describe('two-factor challenge', () => {
  it('sends the code and loads the user', async () => {
    const api = mockApi({
      'POST /customer/auth/two-factor/challenge': noContent(),
      'GET /customer/auth/user': json(userBody),
    })
    const auth = useAuthStore()

    const result = await auth.completeChallenge({ code: '123456' })

    expect(result.ok).toBe(true)
    expect(await api.sent('POST /customer/auth/two-factor/challenge')[0]?.json()).toEqual({ code: '123456' })
    expect(auth.status).toBe('authenticated')
  })

  it('returns the error of a wrong code', async () => {
    const errors = { recovery_code: ['The code is not valid.'] }
    mockApi({ 'POST /customer/auth/two-factor/challenge': json({ message: 'x', errors }, 422) })

    expect(await useAuthStore().completeChallenge({ recovery_code: 'nope' })).toEqual({
      ok: false,
      errors,
      message: '',
    })
  })
})

describe('logout', () => {
  it('ends the session', async () => {
    const api = mockApi({ 'GET /customer/auth/user': json(userBody), 'POST /customer/auth/logout': noContent() })
    const auth = useAuthStore()
    await auth.restore()

    const result = await auth.logout()

    expect(result.ok).toBe(true)
    expect(api.sent('POST /customer/auth/logout')).toHaveLength(1)
    expect(auth.status).toBe('guest')
    expect(auth.user).toBeNull()
  })
})
