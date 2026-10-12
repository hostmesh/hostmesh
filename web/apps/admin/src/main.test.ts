// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockApi } from './test/api'
import { guestRoutes } from './test/fixtures'

// jsdom has no matchMedia: answer as a system set to dark would.
function stubSystemColorScheme(dark: boolean): void {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: dark && query === '(prefers-color-scheme: dark)',
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  }))
}

afterEach(() => {
  vi.unstubAllGlobals()
  vi.resetModules()
  document.documentElement.classList.remove('dark')
  document.body.innerHTML = ''
})

describe('entry point', () => {
  it('applies the system color mode and mounts the app', async () => {
    stubSystemColorScheme(true)
    document.body.innerHTML = '<div id="app"></div>'

    await import('./main')

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(document.querySelector('#app')?.innerHTML).not.toBe('')
  })

  it('applies the system color mode and mounts the app', async () => {
    stubSystemColorScheme(true)
    mockApi(guestRoutes())
    document.body.innerHTML = '<div id="app"></div>'

    await import('./main')

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(document.querySelector('#app')?.innerHTML).not.toBe('')
  })
})
