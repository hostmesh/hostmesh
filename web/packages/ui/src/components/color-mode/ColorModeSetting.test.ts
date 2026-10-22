// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it, vi } from 'vitest'
import { render } from 'vitest-browser-vue'

import ColorModeSettingSample from '../../test/ColorModeSettingSample.vue'
import { COLOR_MODE_STORAGE_KEY } from '../../composables/color-mode'

// Issue #141: light, dark or the system's setting, chosen on the account
// screen and remembered in this browser.

function system(dark: boolean): void {
  vi.spyOn(window, 'matchMedia').mockImplementation(
    (query: string) =>
      ({
        matches: dark && query === '(prefers-color-scheme: dark)',
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
      }) as unknown as MediaQueryList,
  )
}

afterEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
  document.documentElement.classList.remove('light', 'dark')
})

describe('ColorModeSetting', () => {
  it('offers the system setting, light and dark, and starts at the system', async () => {
    system(false)
    const screen = await render(ColorModeSettingSample)

    const select = screen.getByRole('combobox', { name: 'Appearance' })
    await expect.element(select).toHaveValue('auto')
    expect(screen.getByRole('option').elements().map((option) => option.textContent.trim())).toEqual([
      'System',
      'Light',
      'Dark',
    ])
    await expect.element(select).toHaveAccessibleDescription('Remembered in this browser only.')
  })

  it('applies a choice at once, everywhere, and remembers it', async () => {
    system(false)
    const screen = await render(ColorModeSettingSample)

    await screen.getByRole('combobox', { name: 'Appearance' }).selectOptions('dark')

    await expect.element(screen.getByTestId('mode')).toHaveTextContent('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(localStorage.getItem(COLOR_MODE_STORAGE_KEY)).toBe('dark')
  })

  it('shows a remembered choice', async () => {
    system(true)
    localStorage.setItem(COLOR_MODE_STORAGE_KEY, 'light')
    const screen = await render(ColorModeSettingSample)

    await expect.element(screen.getByRole('combobox', { name: 'Appearance' })).toHaveValue('light')
    await expect.element(screen.getByTestId('mode')).toHaveTextContent('light')
  })

  it('goes back to the system setting', async () => {
    system(true)
    localStorage.setItem(COLOR_MODE_STORAGE_KEY, 'light')
    const screen = await render(ColorModeSettingSample)

    await screen.getByRole('combobox', { name: 'Appearance' }).selectOptions('auto')

    await expect.element(screen.getByTestId('mode')).toHaveTextContent('dark')
    expect(localStorage.getItem(COLOR_MODE_STORAGE_KEY)).toBe('auto')
  })

  // Issue #95: `style-src 'self'` blocks a <style> element made by a script.
  it('adds no <style> element when the choice changes', async () => {
    system(false)
    const screen = await render(ColorModeSettingSample)
    const before = document.head.querySelectorAll('style').length

    await screen.getByRole('combobox', { name: 'Appearance' }).selectOptions('dark')
    await expect.element(screen.getByTestId('mode')).toHaveTextContent('dark')

    expect(document.head.querySelectorAll('style').length).toBe(before)
  })
})
