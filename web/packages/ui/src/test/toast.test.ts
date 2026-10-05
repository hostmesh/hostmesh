// SPDX-License-Identifier: Apache-2.0
import { afterEach, describe, expect, it } from 'vitest'
import { page } from 'vitest/browser'
import { render } from 'vitest-browser-vue'

import { toast } from '../index'
import ToastSample from './ToastSample.vue'

afterEach(() => {
  toast.dismiss()
  document.documentElement.classList.remove('tokens-non-default')
})

describe('toast', () => {
  it('shows a message', async () => {
    const screen = await render(ToastSample)

    await screen.getByRole('button', { name: 'Notify' }).click()

    await expect.element(page.getByText('Website created')).toBeVisible()
  })

  it('takes its surface from --popover and --popover-foreground', async () => {
    document.documentElement.classList.add('tokens-non-default')
    const screen = await render(ToastSample)
    await screen.getByRole('button', { name: 'Notify' }).click()
    await expect.element(page.getByText('Website created')).toBeVisible()

    // vue-sonner marks each toast with this attribute.
    const element = document.querySelector('[data-sonner-toast]')
    expect(element).not.toBeNull()
    const style = getComputedStyle(element as Element)
    expect(style.backgroundColor).toBe('rgb(30, 60, 90)')
    expect(style.color).toBe('rgb(4, 5, 6)')
  })
})
