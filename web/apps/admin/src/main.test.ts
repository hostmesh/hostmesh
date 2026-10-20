// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it, vi } from 'vitest'

// Issue #203: everything main.ts imports, loaded while the file is
// collected, as every other test file loads what it tests. Collecting has no
// timeout. Imported inside the test, the whole app counted against the test's
// timeout, and its load time grows with the load of the machine. The test now
// runs main.ts alone.
import '@hostmesh/ui'
import 'pinia'
import 'vue'
import 'vue-router'
import './App.vue'
import './router'
import './style.css'
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
    mockApi(guestRoutes())
    document.body.innerHTML = '<div id="app"></div>'

    await import('./main')

    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(document.querySelector('#app')?.innerHTML).not.toBe('')
  })
})
