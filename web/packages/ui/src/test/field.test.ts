// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'
import { render } from 'vitest-browser-vue'

import FieldSample from './FieldSample.vue'

describe('form field', () => {
  it('names the input by its label', async () => {
    const screen = await render(FieldSample)

    await expect.element(screen.getByRole('textbox', { name: 'Domain' })).toBeVisible()
  })

  it('focuses the input when the label is clicked', async () => {
    const screen = await render(FieldSample)

    await screen.getByText('Domain', { exact: true }).click()

    await expect.element(screen.getByRole('textbox', { name: 'Domain' })).toHaveFocus()
  })

  it('describes the input by its description', async () => {
    const screen = await render(FieldSample)

    await expect
      .element(screen.getByRole('textbox', { name: 'Domain' }))
      .toHaveAccessibleDescription('Without the protocol.')
  })

  it('accepts typed text', async () => {
    const screen = await render(FieldSample)
    const input = screen.getByRole('textbox', { name: 'Domain' })

    await input.fill('example.com')

    await expect.element(input).toHaveValue('example.com')
  })
})
