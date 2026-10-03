// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'
import { render } from 'vitest-browser-vue'

import ExampleTable from './__fixtures__/ExampleTable.vue'

const websites = [
  { domain: 'example.com', status: 'active' as const },
  { domain: 'example.org', status: 'suspended' as const },
]

describe('DataTable', () => {
  it('renders a header per column', async () => {
    const screen = await render(ExampleTable, { props: { websites } })

    for (const name of ['Domain', 'Status', 'Actions']) {
      await expect.element(screen.getByRole('columnheader', { name })).toBeVisible()
    }
  })

  it('renders a row per item, through each column definition', async () => {
    const screen = await render(ExampleTable, { props: { websites } })

    await expect.element(screen.getByRole('cell', { name: 'example.com', exact: true })).toBeVisible()
    // The status column formats its value.
    await expect.element(screen.getByRole('cell', { name: 'Suspended' })).toBeVisible()
    // The header row and one row per website.
    expect(screen.getByRole('row').elements()).toHaveLength(3)
  })

  it('renders a component returned by a cell', async () => {
    const screen = await render(ExampleTable, { props: { websites } })

    await expect.element(screen.getByRole('button', { name: 'Open example.org' })).toBeVisible()
  })

  it('shows the default empty state across all columns', async () => {
    const screen = await render(ExampleTable, { props: { websites: [] } })

    const cell = screen.getByRole('cell', { name: 'No results.' })
    await expect.element(cell).toBeVisible()
    await expect.element(cell).toHaveAttribute('colspan', '3')
  })

  it('shows a custom empty state', async () => {
    const screen = await render(ExampleTable, { props: { websites: [], emptyText: 'No websites yet.' } })

    await expect.element(screen.getByRole('cell', { name: 'No websites yet.' })).toBeVisible()
  })

  it('renders rows that arrive after mounting', async () => {
    // Typed, so that rerender() accepts the rows: a bare [] infers never[].
    const screen = await render(ExampleTable, { props: { websites: [] as typeof websites } })
    await expect.element(screen.getByRole('cell', { name: 'No results.' })).toBeVisible()

    await screen.rerender({ websites })

    await expect.element(screen.getByRole('cell', { name: 'example.com', exact: true })).toBeVisible()
    await expect.element(screen.getByRole('cell', { name: 'No results.' })).not.toBeInTheDocument()
  })
})
