// SPDX-License-Identifier: Apache-2.0
import { describe, expectTypeOf, it } from 'vitest'

import { createApiClient, type paths } from './index'

type HealthBody = paths['/health']['get']['responses'][200]['content']['application/json']

const baseUrl = 'https://api.hostmesh.test/api/v1'

describe('the client agrees with contracts/api/openapi.json', () => {
  it('types the body of the health response from the spec', () => {
    expectTypeOf<HealthBody>().toEqualTypeOf<{ status: 'ok' }>()
  })

  it('types a call to the health endpoint from the spec', async () => {
    const client = createApiClient({ baseUrl })

    const { data } = await client.GET('/health')

    expectTypeOf(data).exclude<undefined>().toHaveProperty('status').toEqualTypeOf<'ok'>()
  })

  it('rejects a path the spec does not have', () => {
    const client = createApiClient({ baseUrl })

    // @ts-expect-error -- "/does-not-exist" is not a path in the spec.
    void client.GET('/does-not-exist')
  })
})
