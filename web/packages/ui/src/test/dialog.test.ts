// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it } from 'vitest'
import { page, userEvent } from 'vitest/browser'
import { render } from 'vitest-browser-vue'

import DialogSample from './DialogSample.vue'
import DialogScrollSample from './DialogScrollSample.vue'

// The dialog is rendered in a portal on <body>, so it is found through
// `page`, not through the rendered component.
const dialog = () => page.getByRole('dialog', { name: 'Delete example.com?' })

// What the browser computes for a utility such as `bg-overlay/10`: Tailwind
// mixes the token with transparent.
function mixed(color: string, percent: number): string {
  const probe = document.createElement('div')
  probe.style.backgroundColor = `color-mix(in oklab, ${color} ${String(percent)}%, transparent)`
  document.body.append(probe)
  const value = getComputedStyle(probe).backgroundColor
  probe.remove()
  return value
}

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

  it('takes its scrim from --overlay', async () => {
    document.documentElement.classList.add('tokens-non-default')
    const screen = await render(DialogSample)
    await screen.getByRole('button', { name: 'Delete website' }).click()
    await expect.element(dialog()).toBeVisible()

    const overlay = document.querySelector('[data-slot="dialog-overlay"]')
    expect(overlay).not.toBeNull()
    expect(getComputedStyle(overlay as Element).backgroundColor).toBe(mixed('rgb(90 60 30)', 10))
  })
})

describe('scrolling dialog', () => {
  const scrolling = () => page.getByRole('dialog', { name: 'Terms of service' })

  it('opens from its trigger and closes with its close button', async () => {
    const screen = await render(DialogScrollSample)

    await screen.getByRole('button', { name: 'Show terms' }).click()
    await expect.element(scrolling()).toBeVisible()
    await expect.element(scrolling()).toHaveAccessibleDescription('A long text that scrolls with the page.')

    await page.getByRole('button', { name: 'Close' }).click()

    await expect.element(scrolling()).not.toBeInTheDocument()
  })

  it('takes its scrim from --overlay', async () => {
    document.documentElement.classList.add('tokens-non-default')
    const screen = await render(DialogScrollSample)
    await screen.getByRole('button', { name: 'Show terms' }).click()
    await expect.element(scrolling()).toBeVisible()

    // The scrim wraps the content, so that the page scrolls a long dialog.
    const overlay = scrolling().element().parentElement
    expect(overlay).not.toBeNull()
    expect(getComputedStyle(overlay as Element).backgroundColor).toBe(mixed('rgb(90 60 30)', 80))
  })
})
