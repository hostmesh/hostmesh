// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it, vi } from 'vitest'
import { render } from 'vitest-browser-vue'

import ExampleForm from './__fixtures__/ExampleForm.vue'

describe('form pattern', () => {
  it('names each input by its label and focuses it from the label', async () => {
    const screen = await render(ExampleForm)

    await screen.getByText('Email', { exact: true }).click()

    await expect.element(screen.getByRole('textbox', { name: 'Email' })).toHaveFocus()
  })

  it('describes an input by its description', async () => {
    const screen = await render(ExampleForm)
    const domain = screen.getByRole('textbox', { name: 'Domain' })

    await expect.element(domain).toHaveAccessibleDescription('Without the protocol.')
    await expect.element(domain).not.toHaveAttribute('aria-invalid')
  })

  it('shows the schema messages on an invalid submit and does not submit', async () => {
    const onSubmit = vi.fn()
    const screen = await render(ExampleForm, { props: { onSubmit } })

    await screen.getByRole('button', { name: 'Create website' }).click()

    const email = screen.getByRole('textbox', { name: 'Email' })
    await expect.element(email).toHaveAttribute('aria-invalid', 'true')
    await expect.element(email).toHaveAccessibleDescription('Enter a valid email address.')
    // The description stays, and the error is added to it.
    await expect
      .element(screen.getByRole('textbox', { name: 'Domain' }))
      .toHaveAccessibleDescription('Without the protocol. Enter a domain such as example.com.')
    expect(screen.getByRole('alert').elements()).toHaveLength(2)
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('clears a field error when the value becomes valid', async () => {
    const screen = await render(ExampleForm)
    await screen.getByRole('button', { name: 'Create website' }).click()
    const email = screen.getByRole('textbox', { name: 'Email' })
    await expect.element(email).toHaveAttribute('aria-invalid', 'true')

    await email.fill('ops@example.com')

    await expect.element(email).not.toHaveAttribute('aria-invalid')
    await expect.element(screen.getByText('Enter a valid email address.')).not.toBeInTheDocument()
  })

  it('submits the parsed values when the form is valid', async () => {
    const onSubmit = vi.fn()
    const screen = await render(ExampleForm, { props: { onSubmit } })

    await screen.getByRole('textbox', { name: 'Domain' }).fill('example.com')
    await screen.getByRole('textbox', { name: 'Email' }).fill('ops@example.com')
    await screen.getByRole('button', { name: 'Create website' }).click()

    await expect.poll(() => onSubmit.mock.calls).toEqual([[{ domain: 'example.com', email: 'ops@example.com' }]])
  })

  it('colors an error from --destructive', async () => {
    document.documentElement.classList.add('tokens-non-default')
    try {
      const screen = await render(ExampleForm)
      await screen.getByRole('button', { name: 'Create website' }).click()

      const error = screen.getByText('Enter a valid email address.')
      await expect.element(error).toBeVisible()
      expect(getComputedStyle(error.element()).color).toBe('rgb(128, 0, 255)')
    } finally {
      document.documentElement.classList.remove('tokens-non-default')
    }
  })
})
