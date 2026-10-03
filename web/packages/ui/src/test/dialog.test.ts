// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it } from 'vitest'
import { page, userEvent } from 'vitest/browser'
import { render } from 'vitest-browser-vue'

import DialogSample from './DialogSample.vue'

// The dialog is rendered in a portal on <body>, so it is found through
// `page`, not through the rendered component.
const dialog = () => page.getByRole('dialog', { name: 'Delete example.com?' })

function focusIsInDialog(): boolean {
  return dialog().element().contains(document.activeElement)
}

afterEach(() => {
  document.documentElement.classList.remove('tokens-non-default')
})

describe('dialog', () => {
  it('opens from its trigger, named by its title and described by its description', async () => {
    const screen = await render(DialogSample)

    await screen.getByRole('button', { name: 'Delete website' }).click()

    await expect.element(dialog()).toBeVisible()
    await expect.element(dialog()).toHaveAccessibleDescription('This cannot be undone.')
  })

  it('moves focus into the dialog and keeps it there', async () => {
    const screen = await render(DialogSample)
    await screen.getByRole('button', { name: 'Delete website' }).click()
    await expect.element(dialog()).toBeVisible()

    await expect.poll(focusIsInDialog).toBe(true)

    // Two focusable elements (Cancel and Close): tabbing past both wraps.
    for (let press = 0; press < 3; press++) {
      await userEvent.keyboard('{Tab}')
      expect(focusIsInDialog()).toBe(true)
    }
  })

  it('closes on Escape and returns focus to the trigger', async () => {
    const screen = await render(DialogSample)
    const trigger = screen.getByRole('button', { name: 'Delete website' })
    await trigger.click()
    await expect.element(dialog()).toBeVisible()

    await userEvent.keyboard('{Escape}')

    await expect.element(dialog()).not.toBeInTheDocument()
    await expect.element(trigger).toHaveFocus()
  })

  it('has a close button with a name', async () => {
    const screen = await render(DialogSample)
    await screen.getByRole('button', { name: 'Delete website' }).click()

    await page.getByRole('button', { name: 'Close' }).click()

    await expect.element(dialog()).not.toBeInTheDocument()
  })

  it('takes its surface from --popover and --popover-foreground', async () => {
    document.documentElement.classList.add('tokens-non-default')
    const screen = await render(DialogSample)
    await screen.getByRole('button', { name: 'Delete website' }).click()
    await expect.element(dialog()).toBeVisible()

    const style = getComputedStyle(dialog().element())
    expect(style.backgroundColor).toBe('rgb(30, 60, 90)')
    expect(style.color).toBe('rgb(4, 5, 6)')
  })
})
