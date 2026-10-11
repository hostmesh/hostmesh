// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it, vi } from 'vitest'

import { createSessionClient } from './index'

const baseUrl = 'https://api.hostmesh.test/api/v1'

function client(cookies: string) {
  const fetchMock = vi.fn<(request: Request) => Promise<Response>>(() =>
    Promise.resolve(new Response(null, { status: 204 })),
  )
  const api = createSessionClient({
    baseUrl,
    csrfCookie: 'XSRF-TOKEN-ADMIN',
    readCookies: () => cookies,
    fetch: fetchMock,
  })
  const sent = (): Request => {
    const request = fetchMock.mock.calls.at(-1)?.[0]
    if (!request) throw new Error('No request was sent.')
    return request
  }
  return { api, sent }
}

describe('createSessionClient', () => {
  it('sends cookies cross-origin and asks for JSON', async () => {
    const { api, sent } = client('')

    await api.GET('/admin/auth/csrf-cookie')

    expect(sent().credentials).toBe('include')
    expect(sent().headers.get('Accept')).toBe('application/json')
  })

  it('echoes the CSRF cookie of its realm on a state-changing request', async () => {
    const { api, sent } = client('other=1; XSRF-TOKEN-PORTAL=portal; XSRF-TOKEN-ADMIN=admin-token')

    await api.POST('/admin/auth/logout')

    expect(sent().headers.get('X-XSRF-TOKEN')).toBe('admin-token')
  })

  it('decodes the cookie value', async () => {
    const { api, sent } = client('XSRF-TOKEN-ADMIN=eyJpdiI6IkFiYyJ9%3D%3D')

    await api.POST('/admin/auth/logout')

    expect(sent().headers.get('X-XSRF-TOKEN')).toBe('eyJpdiI6IkFiYyJ9==')
  })

  it('sends no CSRF header on a read', async () => {
    const { api, sent } = client('XSRF-TOKEN-ADMIN=admin-token')

    await api.GET('/branding')

    expect(sent().headers.has('X-XSRF-TOKEN')).toBe(false)
  })

  it('sends no CSRF header when the cookie is missing', async () => {
    const { api, sent } = client('XSRF-TOKEN-PORTAL=portal')

    await api.POST('/admin/auth/logout')

    expect(sent().headers.has('X-XSRF-TOKEN')).toBe(false)
  })

  it('looks up the global fetch at call time when none is given', async () => {
    const api = createSessionClient({ baseUrl, csrfCookie: 'XSRF-TOKEN-ADMIN', readCookies: () => '' })
    const fetchMock = vi.fn(() => Promise.resolve(Response.json({ status: 'ok' })))
    vi.stubGlobal('fetch', fetchMock)
    try {
      const { data } = await api.GET('/health')

      expect(data).toEqual({ status: 'ok' })
      expect(fetchMock).toHaveBeenCalledOnce()
    } finally {
      vi.unstubAllGlobals()
    }
  })
})
