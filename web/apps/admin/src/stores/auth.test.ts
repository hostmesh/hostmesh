// SPDX-License-Identifier: Apache-2.0
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { UNREACHABLE } from '../lib/result'
import { json, mockApi, noContent } from '../test/api'
import { account, loginBody, pending, unauthenticated, userBody } from '../test/fixtures'
import { useAuthStore } from './auth'

beforeEach(() => {
  setActivePinia(createPinia())
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('restore', () => {
  it('starts without knowing the session', () => {
    expect(useAuthStore().status).toBe('unknown')
  })

  it('restores a complete session with its user and abilities', async () => {
    mockApi({ 'GET /admin/auth/user': json(userBody) })
    const auth = useAuthStore()

    await auth.restore()

    expect(auth.status).toBe('authenticated')
    expect(auth.user).toEqual(account)
    expect(auth.user?.abilities.manage_two_factor).toBe(true)
  })

  it('is a guest without a session', async () => {
    mockApi({ 'GET /admin/auth/user': unauthenticated() })
    const auth = useAuthStore()

    await auth.restore()

    expect(auth.status).toBe('guest')
    expect(auth.user).toBeNull()
  })

  it.each(['challenge', 'enrollment'] as const)('restores a session that still owes its %s', async (step) => {
    mockApi({ 'GET /admin/auth/user': pending(step) })
    const auth = useAuthStore()

    await auth.restore()

    expect(auth.status).toBe(step)
    expect(auth.user).toBeNull()
  })

  it('is a guest when the API cannot be reached', async () => {
    mockApi({ 'GET /admin/auth/user': () => Promise.reject(new TypeError('Failed to fetch')) })
    const auth = useAuthStore()

    await auth.restore()

    expect(auth.status).toBe('guest')
  })
})

describe('login', () => {
  const credentials = { email: 'ada@example.com', password: 'correct-password' }

  it('fetches the CSRF cookie, then posts the credentials', async () => {
    const api = mockApi({
      'GET /admin/auth/csrf-cookie': noContent(),
      'POST /admin/auth/login': json(loginBody('none')),
    })

    const result = await useAuthStore().login(credentials)

    expect(result.ok).toBe(true)
    expect(api.requests.map((request) => `${request.method} ${new URL(request.url).pathname}`)).toEqual([
      'GET /api/v1/admin/auth/csrf-cookie',
      'POST /api/v1/admin/auth/login',
    ])
    expect(await api.sent('POST /admin/auth/login')[0]?.json()).toEqual(credentials)
  })

  it('is authenticated when no second factor is owed', async () => {
    mockApi({ 'GET /admin/auth/csrf-cookie': noContent(), 'POST /admin/auth/login': json(loginBody('none')) })
    const auth = useAuthStore()

    await auth.login(credentials)

    expect(auth.status).toBe('authenticated')
    expect(auth.user).toEqual(account)
  })

  it.each(['challenge', 'enrollment'] as const)('owes the %s after the password', async (step) => {
    mockApi({ 'GET /admin/auth/csrf-cookie': noContent(), 'POST /admin/auth/login': json(loginBody(step)) })
    const auth = useAuthStore()

    const result = await auth.login(credentials)

    expect(result.ok).toBe(true)
    expect(auth.status).toBe(step)
    expect(auth.user).toBeNull()
  })

  it('returns the field errors of wrong credentials and stays a guest', async () => {
    const errors = { email: ['These credentials do not match our records.'] }
    mockApi({
      'GET /admin/auth/csrf-cookie': noContent(),
      'POST /admin/auth/login': json({ message: errors.email[0], errors }, 422),
    })
    const auth = useAuthStore()
    auth.expire()

    const result = await auth.login(credentials)

    expect(result).toEqual({ ok: false, errors, message: '' })
    expect(auth.status).toBe('guest')
  })

  it('reports throttling', async () => {
    mockApi({
      'GET /admin/auth/csrf-cookie': noContent(),
      'POST /admin/auth/login': json({ message: 'Too Many Attempts.' }, 429),
    })

    const result = await useAuthStore().login(credentials)

    expect(result).toMatchObject({ ok: false, message: 'Too many attempts. Wait a minute and try again.' })
  })

  it('reports an API that cannot be reached', async () => {
    mockApi({ 'GET /admin/auth/csrf-cookie': () => Promise.reject(new TypeError('Failed to fetch')) })

    const result = await useAuthStore().login(credentials)

    expect(result).toEqual({ ok: false, errors: {}, message: UNREACHABLE })
  })
})

describe('two-factor challenge', () => {
  it.each([{ code: '123456' }, { recovery_code: 'abcde-fghij' }])('sends %j and loads the user', async (input) => {
    const api = mockApi({
      'POST /admin/auth/two-factor/challenge': noContent(),
      'GET /admin/auth/user': json(userBody),
    })
    const auth = useAuthStore()

    const result = await auth.completeChallenge(input)

    expect(result.ok).toBe(true)
    expect(await api.sent('POST /admin/auth/two-factor/challenge')[0]?.json()).toEqual(input)
    expect(auth.status).toBe('authenticated')
    expect(auth.user).toEqual(account)
  })

  it('returns the error of a wrong code and keeps owing the challenge', async () => {
    const errors = { code: ['The code is not valid.'] }
    mockApi({
      'GET /admin/auth/user': pending('challenge'),
      'POST /admin/auth/two-factor/challenge': json({ message: errors.code[0], errors }, 422),
    })
    const auth = useAuthStore()
    await auth.restore()

    const result = await auth.completeChallenge({ code: '000000' })

    expect(result).toEqual({ ok: false, errors, message: '' })
    expect(auth.status).toBe('challenge')
  })
})

describe('two-factor enrollment', () => {
  const enrollment = { secret: 'JBSWY3DPEHPK3PXP', provisioning_uri: 'otpauth://totp/Hostmesh:ada?secret=JBSWY3DPEHPK3PXP' }

  it('starts enrollment and returns the secret and its URI', async () => {
    mockApi({ 'POST /admin/auth/two-factor': json({ data: enrollment }) })

    const result = await useAuthStore().startEnrollment()

    expect(result).toEqual({ ok: true, value: enrollment })
  })

  it('confirms with a code, returns the recovery codes and keeps the step open', async () => {
    const codes = ['aaaaa-bbbbb', 'ccccc-ddddd']
    const api = mockApi({
      'GET /admin/auth/user': pending('enrollment'),
      'POST /admin/auth/two-factor/confirm': json({ data: { recovery_codes: codes } }),
    })
    const auth = useAuthStore()
    await auth.restore()

    const result = await auth.confirmEnrollment('123456')

    expect(result).toEqual({ ok: true, value: codes })
    expect(await api.sent('POST /admin/auth/two-factor/confirm')[0]?.json()).toEqual({ code: '123456' })
    // The view still has to show the codes: the guard must not move on yet.
    expect(auth.status).toBe('enrollment')
  })

  it('returns the error of a wrong code', async () => {
    const errors = { code: ['The code is not valid.'] }
    mockApi({ 'POST /admin/auth/two-factor/confirm': json({ message: errors.code[0], errors }, 422) })

    const result = await useAuthStore().confirmEnrollment('000000')

    expect(result).toEqual({ ok: false, errors, message: '' })
  })

  it('finishes by loading the user', async () => {
    mockApi({ 'GET /admin/auth/user': json(userBody) })
    const auth = useAuthStore()

    await auth.finishEnrollment()

    expect(auth.status).toBe('authenticated')
  })
})

describe('logout', () => {
  it('ends the session', async () => {
    const api = mockApi({ 'GET /admin/auth/user': json(userBody), 'POST /admin/auth/logout': noContent() })
    const auth = useAuthStore()
    await auth.restore()

    const result = await auth.logout()

    expect(result.ok).toBe(true)
    expect(api.sent('POST /admin/auth/logout')).toHaveLength(1)
    expect(auth.status).toBe('guest')
    expect(auth.user).toBeNull()
  })

  it('is a guest too when the session had already ended', async () => {
    mockApi({ 'GET /admin/auth/user': json(userBody), 'POST /admin/auth/logout': unauthenticated() })
    const auth = useAuthStore()
    await auth.restore()

    const result = await auth.logout()

    expect(result.ok).toBe(true)
    expect(auth.status).toBe('guest')
  })

  it('stays logged in when the API cannot be reached', async () => {
    mockApi({
      'GET /admin/auth/user': json(userBody),
      'POST /admin/auth/logout': () => Promise.reject(new TypeError('Failed to fetch')),
    })
    const auth = useAuthStore()
    await auth.restore()

    const result = await auth.logout()

    expect(result).toEqual({ ok: false, errors: {}, message: UNREACHABLE })
    expect(auth.status).toBe('authenticated')
  })
})
