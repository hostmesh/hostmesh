// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'
import { render } from 'vitest-browser-vue'

import NativeSelectSample from './NativeSelectSample.vue'

// The browser's own select: a list filter that works the same with a
// keyboard, a screen reader and on a phone.
describe('native select', () => {
  it('names the select by its label and starts at its first option', async () => {
    const screen = await render(NativeSelectSample)

    const select = screen.getByRole('combobox', { name: 'Status' })
    await expect.element(select).toBeVisible()
    await expect.element(select).toHaveValue('')
  })

  it('binds the chosen option', async () => {
    const screen = await render(NativeSelectSample)

    await screen.getByRole('combobox', { name: 'Status' }).selectOptions('suspended')

    await expect.element(screen.getByTestId('chosen')).toHaveTextContent('suspended')
  })

  it('offers every option', async () => {
    const screen = await render(NativeSelectSample)

    expect(screen.getByRole('option').elements().map((option) => option.textContent.trim())).toEqual([
      'Any',
      'Active',
      'Suspended',
    ])
  })
})
