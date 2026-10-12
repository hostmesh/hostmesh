// SPDX-License-Identifier: Apache-2.0

/**
 * The path to go to after logging in, from the `redirect` query parameter.
 * Only a path inside the app is followed: anything else answers `/`, so a
 * crafted link cannot send a user who just logged in to another site.
 */
export function safeRedirect(value: unknown): string {
  if (typeof value !== 'string' || !value.startsWith('/')) {
    return '/'
  }
  // `//host` and `/\host` leave the origin in a browser.
  return value.startsWith('//') || value.startsWith('/\\') ? '/' : value
}
