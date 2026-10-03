// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'
import { render } from 'vitest-browser-vue'

import TableSample from './TableSample.vue'

describe('table', () => {
  it('is a table named by its caption', async () => {
    const screen = await render(TableSample)

    await expect.element(screen.getByRole('table', { name: 'Websites' })).toBeVisible()
  })

  it('has column headers and cells', async () => {
    const screen = await render(TableSample)

    await expect.element(screen.getByRole('columnheader', { name: 'Domain' })).toBeVisible()
    await expect.element(screen.getByRole('columnheader', { name: 'Status' })).toBeVisible()
    await expect.element(screen.getByRole('cell', { name: 'example.org' })).toBeVisible()
    // The header row and two body rows.
    expect(screen.getByRole('row').elements()).toHaveLength(3)
  })
})
