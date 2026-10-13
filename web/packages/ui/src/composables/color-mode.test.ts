// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, type EffectScope } from 'vue'

import { COLOR_MODE_STORAGE_KEY, useColorMode, type UseColorMode } from './color-mode'

const root = document.documentElement
let scope: EffectScope | undefined

// Replaces the browser's answer to `prefers-color-scheme: dark`, and lets a
// test change it afterwards, as the operating system would.
function stubSystem(dark: boolean) {
  const listeners = new Set<(event: MediaQueryListEvent) => void>()
  const list = {
    matches: dark,
    media: '(prefers-color-scheme: dark)',
    onchange: null,
    addEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => {
      listeners.add(listener)
    },
    removeEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => {
      listeners.delete(listener)
    },
    addListener: () => {},
    removeListener: () => {},
    dispatchEvent: () => true,
  }
  vi.spyOn(window, 'matchMedia').mockImplementation(() => list as unknown as MediaQueryList)

  return {
    change(next: boolean) {
      list.matches = next
      for (const listener of listeners) {
        listener({ matches: next } as MediaQueryListEvent)
      }
    },
  }
}

function start(): UseColorMode {
  scope = effectScope()
  const result = scope.run(() => useColorMode())
  if (!result) {
    throw new Error('The effect scope did not run')
  }
  return result
}

afterEach(() => {
  scope?.stop()
  scope = undefined
  vi.restoreAllMocks()
  localStorage.clear()
  root.classList.remove('dark', 'light')
})

describe('useColorMode', () => {
  it('applies .dark when the system prefers dark and nothing was chosen', () => {
    stubSystem(true)

    const { preference, mode } = start()

    expect(preference.value).toBe('auto')
    expect(mode.value).toBe('dark')
    expect(root.classList.contains('dark')).toBe(true)
  })

  it('leaves .dark off when the system prefers light', () => {
    stubSystem(false)

    const { mode } = start()

    expect(mode.value).toBe('light')
    expect(root.classList.contains('dark')).toBe(false)
  })

  it('follows the system when it changes', async () => {
    const system = stubSystem(false)
    const { mode } = start()
    await nextTick()

    system.change(true)
    await nextTick()

    expect(mode.value).toBe('dark')
    expect(root.classList.contains('dark')).toBe(true)
  })

  it('lets an explicit choice win over the system, and remembers it', async () => {
    stubSystem(true)
    const { preference, mode } = start()

    preference.value = 'light'
    await nextTick()

    expect(mode.value).toBe('light')
    expect(root.classList.contains('dark')).toBe(false)
    expect(localStorage.getItem(COLOR_MODE_STORAGE_KEY)).toBe('light')
  })

  it('starts from a remembered choice', () => {
    localStorage.setItem(COLOR_MODE_STORAGE_KEY, 'dark')
    stubSystem(false)

    const { preference, mode } = start()

    expect(preference.value).toBe('dark')
    expect(mode.value).toBe('dark')
    expect(root.classList.contains('dark')).toBe(true)
  })

  it('returns to the system setting when the choice is set back to auto', async () => {
    localStorage.setItem(COLOR_MODE_STORAGE_KEY, 'dark')
    stubSystem(false)
    const { preference, mode } = start()

    preference.value = 'auto'
    await nextTick()

    expect(mode.value).toBe('light')
    expect(root.classList.contains('dark')).toBe(false)
  })

  // Issue #95: the apps are served with `style-src 'self'`, which blocks
  // and reports a <style> element made by a script.
  it('adds no <style> element to the document when it applies a mode', async () => {
    stubSystem(false)
    const added: Node[] = []
    const observer = new MutationObserver((records) => {
      added.push(...records.flatMap((record) => [...record.addedNodes]))
    })
    observer.observe(document.head, { childList: true })
    const { preference } = start()

    preference.value = 'dark'
    await nextTick()
    added.push(...observer.takeRecords().flatMap((record) => [...record.addedNodes]))
    observer.disconnect()

    expect(root.classList.contains('dark')).toBe(true)
    expect(added.filter((node) => node.nodeName === 'STYLE')).toEqual([])
  })
})
