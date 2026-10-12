// SPDX-License-Identifier: Apache-2.0
import { apiBaseUrl, createSessionClient } from '@hostmesh/api-client'

/** The admin app's API client: the admin realm's session and CSRF cookie (decision 0010). */
export const api = createSessionClient({
  baseUrl: apiBaseUrl(window.location.hostname),
  csrfCookie: 'XSRF-TOKEN-ADMIN',
})

let unauthenticated: (() => void) | undefined

/** Registers what happens when any call answers 401: the session is gone. One listener. */
export function onUnauthenticated(listener: () => void): void {
  unauthenticated = listener
}

api.use({
  onResponse({ response }) {
    if (response.status === 401) {
      unauthenticated?.()
    }
    return undefined
  },
})
