// SPDX-License-Identifier: Apache-2.0
import createClient, { type Client, type ClientOptions } from 'openapi-fetch'

import type { paths } from './generated/schema'

export type { components, operations, paths } from './generated/schema'

/** The typed client for the Hostmesh API described by contracts/api/openapi.json. */
export type ApiClient = Client<paths>

/**
 * Creates the API client. `baseUrl` is the API root with its version prefix,
 * for example `https://api.example.com/api/v1`.
 */
export function createApiClient(options: ClientOptions): ApiClient {
  return createClient<paths>(options)
}
