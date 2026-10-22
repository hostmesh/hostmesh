// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it, vi } from 'vitest'

import { COLOR_MODE_STORAGE_KEY } from '../composables/color-mode'
import { colorModeEarly, colorModeScript, colorModeScriptPath } from './color-mode'

// Issue #142: a dark system must not see the light page first. A classic
// script in <head> applies the mode before the first paint, from 'self',
// so the Content Security Policy needs no change. It does what
// useColorMode() does (VueUse 14): the stored choice, else the system.

function system(dark: boolean): void {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: dark && query === '(prefers-color-scheme: dark)',
    media: query,
  }))
}

/** Runs the script as the browser would, on this document. */
function runScript(): string[] {
  new Function(colorModeScript())()
  return [...document.documentElement.classList]
}

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  localStorage.clear()
  document.documentElement.classList.remove('light', 'dark')
})

describe('the early color-mode script', () => {
  it('follows a dark system without a choice', () => {
    system(true)

    expect(runScript()).toContain('dark')
  })

  it('follows a light system without a choice', () => {
    system(false)

    expect(runScript()).toEqual(expect.arrayContaining(['light']))
    expect(document.documentElement.classList.contains('dark')).toBe(false)
  })

  it.each([
    ['dark', false, 'dark'],
    ['light', true, 'light'],
    ['auto', true, 'dark'],
    ['auto', false, 'light'],
  ] as const)('applies a stored %s on a %s-dark system as %s', (stored, dark, applied) => {
    system(dark)
    localStorage.setItem(COLOR_MODE_STORAGE_KEY, stored)

    expect(runScript()).toContain(applied)
    expect(document.documentElement.classList.contains(applied === 'dark' ? 'light' : 'dark')).toBe(false)
  })

  it('follows the system when the stored value is unknown', () => {
    system(true)
    localStorage.setItem(COLOR_MODE_STORAGE_KEY, 'sepia')

    expect(runScript()).toContain('dark')
  })

  // Blocked storage (a privacy setting) throws on access.
  it('follows the system when storage cannot be read', () => {
    system(true)
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('denied', 'SecurityError')
    })

    expect(runScript()).toContain('dark')
  })
})

describe('the Vite plugin', () => {
  const plugin = colorModeEarly()

  it('serves the script under a name that changes with its content', () => {
    expect(colorModeScriptPath()).toMatch(/^\/assets\/color-mode-[0-9a-f]{8}\.js$/)
    expect(colorModeScriptPath()).toBe(colorModeScriptPath())
  })

  it('loads the script in <head>, as a classic script that blocks the first paint', () => {
    const transform = plugin.transformIndexHtml as () => unknown

    expect(transform()).toEqual([
      { tag: 'script', attrs: { src: colorModeScriptPath() }, injectTo: 'head' },
    ])
  })

  it('emits the script into the build', () => {
    const emitted: unknown[] = []
    const generateBundle = plugin.generateBundle as (this: { emitFile: (file: unknown) => string }) => void

    generateBundle.call({ emitFile: (file) => (emitted.push(file), 'ref') })

    expect(emitted).toEqual([
      { type: 'asset', fileName: colorModeScriptPath().slice(1), source: colorModeScript() },
    ])
  })

  it('serves the script from the development server', () => {
    const routes = new Map<string, (request: unknown, response: unknown) => void>()
    const configureServer = plugin.configureServer as (server: unknown) => void
    configureServer({ middlewares: { use: (path: string, handler: (request: unknown, response: unknown) => void) => routes.set(path, handler) } })

    const headers: Record<string, string> = {}
    let body = ''
    routes.get(colorModeScriptPath())?.({}, {
      setHeader: (name: string, value: string) => (headers[name] = value),
      end: (text: string) => (body = text),
    })

    expect(headers['Content-Type']).toBe('text/javascript; charset=utf-8')
    expect(body).toBe(colorModeScript())
  })
})
