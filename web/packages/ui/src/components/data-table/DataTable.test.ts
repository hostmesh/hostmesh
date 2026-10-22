// SPDX-License-Identifier: Apache-2.0
import { describe, expect, it } from 'vitest'
import { render } from 'vitest-browser-vue'

import ExampleTable from './__fixtures__/ExampleTable.vue'
import ListTable from './__fixtures__/ListTable.vue'

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

// Issue #143, decision 0019: the API sorts and pages. The table shows the
// state it is given, asks for the next one, and never reorders rows itself.
describe('DataTable driven by the API', () => {
  // Deliberately out of order: the table must keep the order it is given.
  const unsorted = [
    { domain: 'example.org', status: 'active' as const },
    { domain: 'example.com', status: 'suspended' as const },
  ]

  function domains(screen: Awaited<ReturnType<typeof render>>): string[] {
    return screen
      .getByRole('row')
      .elements()
      .slice(1)
      .map((row) => row.querySelector('td')?.textContent.trim() ?? '')
  }

  /** What the region around the table says: `aria-busy` while a page is on its way. */
  function busy(screen: Awaited<ReturnType<typeof render>>): string | null | undefined {
    return screen.getByRole('table').element().closest('[aria-busy]')?.getAttribute('aria-busy')
  }

  describe('sorting', () => {
    it('offers sorting only on the columns that opt in', async () => {
      const screen = await render(ListTable, { props: { websites: unsorted } })

      await expect.element(screen.getByRole('button', { name: 'Domain' })).toBeVisible()
      await expect.element(screen.getByRole('columnheader', { name: 'Domain' })).toHaveAttribute('aria-sort', 'none')
      await expect.element(screen.getByRole('button', { name: 'Status' })).not.toBeInTheDocument()
      await expect.element(screen.getByRole('columnheader', { name: 'Status' })).not.toHaveAttribute('aria-sort')
    })

    it('shows the sorting it is given', async () => {
      const screen = await render(ListTable, {
        props: { websites: unsorted, sorting: [{ id: 'domain', desc: true }] },
      })

      await expect.element(screen.getByRole('columnheader', { name: 'Domain' })).toHaveAttribute('aria-sort', 'descending')
    })

    it('asks for the next order and leaves the rows as they came', async () => {
      const screen = await render(ListTable, { props: { websites: unsorted } })
      const header = screen.getByRole('columnheader', { name: 'Domain' })

      await screen.getByRole('button', { name: 'Domain' }).click()

      await expect.element(screen.getByTestId('sorting')).toHaveTextContent('[{"id":"domain","desc":false}]')
      await expect.element(header).toHaveAttribute('aria-sort', 'ascending')
      expect(domains(screen)).toEqual(['example.org', 'example.com'])

      await screen.getByRole('button', { name: 'Domain' }).click()

      await expect.element(screen.getByTestId('sorting')).toHaveTextContent('[{"id":"domain","desc":true}]')
      await expect.element(header).toHaveAttribute('aria-sort', 'descending')

      // The API always has an order: a third click does not remove it.
      await screen.getByRole('button', { name: 'Domain' }).click()

      await expect.element(screen.getByTestId('sorting')).toHaveTextContent('[{"id":"domain","desc":false}]')
    })
  })

  describe('pagination', () => {
    it('shows no pager for a list that is not paginated', async () => {
      const screen = await render(ListTable, { props: { websites: unsorted } })

      await expect.element(screen.getByRole('navigation', { name: 'Pagination' })).not.toBeInTheDocument()
    })

    it('offers the pages the API has', async () => {
      const screen = await render(ListTable, {
        props: { websites: unsorted, pagination: { previous: false, next: true } },
      })

      await expect.element(screen.getByRole('navigation', { name: 'Pagination' })).toBeVisible()
      await expect.element(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled()
      await expect.element(screen.getByRole('button', { name: 'Next page' })).toBeEnabled()
    })

    it('asks for the next and the previous page', async () => {
      const screen = await render(ListTable, {
        props: { websites: unsorted, pagination: { previous: true, next: true } },
      })

      await screen.getByRole('button', { name: 'Next page' }).click()
      await screen.getByRole('button', { name: 'Previous page' }).click()

      await expect.element(screen.getByTestId('pages')).toHaveTextContent('next,previous')
    })
  })

  describe('loading', () => {
    it('marks the table busy and holds the pager and the sorting', async () => {
      const screen = await render(ListTable, {
        props: { websites: unsorted, pagination: { previous: true, next: true }, loading: true },
      })

      expect(busy(screen)).toBe('true')
      await expect.element(screen.getByRole('button', { name: 'Next page' })).toBeDisabled()
      await expect.element(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled()
      await expect.element(screen.getByRole('button', { name: 'Domain' })).toBeDisabled()
    })

    it('says that it is loading instead of that there are no results', async () => {
      const screen = await render(ListTable, { props: { websites: [], loading: true } })

      await expect.element(screen.getByRole('cell', { name: 'Loading…' })).toBeVisible()
      await expect.element(screen.getByRole('cell', { name: 'No results.' })).not.toBeInTheDocument()
    })

    it('is not busy otherwise', async () => {
      const screen = await render(ListTable, { props: { websites: unsorted } })

      expect(busy(screen)).toBe('false')
    })
  })

  it('shows the screen’s filters above the table', async () => {
    const screen = await render(ListTable, { props: { websites: unsorted } })

    await expect.element(screen.getByRole('searchbox', { name: 'Search' })).toBeVisible()
  })
})
