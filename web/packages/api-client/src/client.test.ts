// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it, vi } from 'vitest'

import { createApiClient, type paths } from './index'

type HealthBody = paths['/health']['get']['responses'][200]['content']['application/json']

describe('createApiClient', () => {
  it('requests the health endpoint under the base URL and returns the body', async () => {
    // The mock body is typed from the generated schema, so a contract
    // change makes this file fail the typecheck.
    const body = { status: 'ok' } satisfies HealthBody
    const fetchMock = vi.fn<(request: Request) => Promise<Response>>(() =>
      Promise.resolve(Response.json(body)),
    )
    const client = createApiClient({
      baseUrl: 'https://api.hostmesh.test/api/v1',
      fetch: fetchMock,
    })

    const { data, error, response } = await client.GET('/health')

    expect(fetchMock).toHaveBeenCalledOnce()
    const request = fetchMock.mock.calls[0]?.[0]
    expect(request?.method).toBe('GET')
    expect(request?.url).toBe('https://api.hostmesh.test/api/v1/health')
    expect(response.status).toBe(200)
    expect(error).toBeUndefined()
    expect(data).toEqual({ status: 'ok' })
  })
})
