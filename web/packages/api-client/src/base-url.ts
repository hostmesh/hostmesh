// SPDX-License-Identifier: Apache-2.0

/**
 * The API root for an app served from `app.<domain>` or `admin.<domain>`
 * (spec section 28, "Hostname layout"): the first label becomes `api`.
 * Always HTTPS: the session cookies are `Secure`.
 */
export function apiBaseUrl(hostname: string): string {
  const labels = hostname.split('.')
  if (labels.length < 2) {
    throw new Error(`Cannot derive the API host from "${hostname}": the app must be served from a subdomain.`)
  }
  return `https://${['api', ...labels.slice(1)].join('.')}/api/v1`
}
