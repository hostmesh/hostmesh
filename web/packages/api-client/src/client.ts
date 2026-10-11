// SPDX-License-Identifier: Apache-2.0
import createClient, { type Client, type ClientOptions } from 'openapi-fetch'

import type { paths } from './generated/schema'

/** The typed client for the Hostmesh API described by contracts/api/openapi.json. */
export type ApiClient = Client<paths>

/**
 * The options of openapi-fetch, with the base URL required: the generated
 * paths are relative to the API root, and the apps are served from another
 * origin than the API.
 */
export type ApiClientOptions = ClientOptions & { baseUrl: string }

/**
 * Creates the API client. `baseUrl` is the API root with its version prefix,
 * for example `https://api.example.com/api/v1`.
 */
export function createApiClient(options: ApiClientOptions): ApiClient {
  return createClient<paths>(options)
}
