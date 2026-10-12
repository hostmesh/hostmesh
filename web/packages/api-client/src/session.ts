// SPDX-License-Identifier: Apache-2.0
import { createApiClient, type ApiClient } from './client'

export interface SessionClientOptions {
  /** The API root with its version prefix, for example `https://api.example.com/api/v1`. */
  baseUrl: string
  /** The CSRF cookie of the app's realm: `XSRF-TOKEN-ADMIN` or `XSRF-TOKEN-PORTAL` (decision 0010). */
  csrfCookie: string
  /** Returns the cookie string. The default reads `document.cookie`. */
  readCookies?: () => string
  /** The default looks up `globalThis.fetch` at call time. */
  fetch?: (request: Request) => Promise<Response>
}

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

function cookieValue(cookies: string, name: string): string | undefined {
  for (const pair of cookies.split('; ')) {
    const separator = pair.indexOf('=')
    if (separator !== -1 && pair.slice(0, separator) === name) {
      return decodeURIComponent(pair.slice(separator + 1))
    }
  }
  return undefined
}

/**
 * The client of an app that logs in with Sanctum's cookie flow (spec
 * section 28): it sends the session cookie cross-origin and echoes the
 * realm's CSRF cookie in `X-XSRF-TOKEN` on every state-changing request.
 */
export function createSessionClient(options: SessionClientOptions): ApiClient {
  const readCookies = options.readCookies ?? (() => document.cookie)
  const client = createApiClient({
    baseUrl: options.baseUrl,
    credentials: 'include',
    headers: { Accept: 'application/json' },
    fetch: options.fetch ?? ((request) => globalThis.fetch(request)),
  })

  client.use({
    onRequest({ request }) {
      if (SAFE_METHODS.has(request.method)) {
        return undefined
      }
      const token = cookieValue(readCookies(), options.csrfCookie)
      if (token !== undefined) {
        request.headers.set('X-XSRF-TOKEN', token)
      }
      return request
    },
  })

  return client
}
