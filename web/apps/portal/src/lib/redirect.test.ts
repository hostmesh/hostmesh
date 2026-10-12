// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'

import { safeRedirect } from './redirect'

describe('safeRedirect', () => {
  it.each(['/branding', '/branding?tab=logos#top', '/'])('keeps the in-app path %s', (path) => {
    expect(safeRedirect(path)).toBe(path)
  })

  it.each([
    ['another site', 'https://evil.example/'],
    ['a protocol-relative address', '//evil.example'],
    ['a backslash address', '/\\evil.example'],
    ['a relative path', 'branding'],
    ['a script address', 'javascript:alert(1)'],
    ['a repeated parameter', ['/a', '/b']],
    ['nothing', undefined],
    ['null', null],
  ])('answers / for %s', (_name, value) => {
    expect(safeRedirect(value)).toBe('/')
  })
})
