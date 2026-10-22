<!--
  SPDX-License-Identifier: Apache-2.0 AND MIT
  Copyright (c) 2023 unovue (the table markup follows shadcn-vue's data-table
  guide; license: ../ui/LICENSE)
-->
<script setup lang="ts" generic="TData extends RowData">
import { ArrowDownIcon, ArrowUpDownIcon, ArrowUpIcon } from '@lucide/vue'
import { FlexRender, functionalUpdate, useTable, type ColumnDef, type RowData, type Updater } from '@tanstack/vue-table'

import { Button } from '#ui/components/ui/button'
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '#ui/components/ui/table'

import { dataTableFeatures, type DataTableFeatures, type DataTableSorting } from './features'

const props = withDefaults(
  defineProps<{
    columns: ColumnDef<DataTableFeatures, TData>[]
    data: TData[]
    /** Whether the API has a page before and after this one. Without it, no pager is shown. */
    pagination?: { previous: boolean; next: boolean }
    /** A page is on its way: the table is marked busy and asks for nothing else meanwhile. */
    loading?: boolean
  }>(),
  { pagination: undefined, loading: false },
)

const emit = defineEmits<{
  previous: []
  next: []
}>()

/** The order the API was asked for. The table shows it and never sorts rows itself. */
const sorting = defineModel<DataTableSorting>('sorting', { default: () => [] })

// Getters, so that the table always reads the current props.
const table = useTable({
  features: dataTableFeatures,
  get data() {
    return props.data
  },
  get columns() {
    return props.columns
  },
  state: {
    get sorting() {
      return sorting.value
    },
  },
  onSortingChange: (updater: Updater<DataTableSorting>) => {
    sorting.value = functionalUpdate(updater, sorting.value)
  },
  // The rows arrive in the API's order. A column offers sorting only when
  // its definition says `enableSorting: true`, for a field the API sorts by.
  manualSorting: true,
  enableMultiSort: false,
  enableSortingRemoval: false,
  defaultColumn: { enableSorting: false },
})

function ariaSort(sorted: false | 'asc' | 'desc'): 'ascending' | 'descending' | 'none' {
  if (sorted === false) {
    return 'none'
  }
  return sorted === 'asc' ? 'ascending' : 'descending'
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <slot name="toolbar" />
    <div :aria-busy="loading">
      <Table>
        <TableHeader>
          <TableRow
            v-for="headerGroup in table.getHeaderGroups()"
            :key="headerGroup.id"
          >
            <TableHead
              v-for="header in headerGroup.headers"
              :key="header.id"
              scope="col"
              :aria-sort="header.column.getCanSort() ? ariaSort(header.column.getIsSorted()) : undefined"
            >
              <template v-if="!header.isPlaceholder">
                <Button
                  v-if="header.column.getCanSort()"
                  variant="ghost"
                  size="sm"
                  class="-ml-2"
                  :disabled="loading"
                  @click="header.column.getToggleSortingHandler()?.($event)"
                >
                  <FlexRender :header="header" />
                  <ArrowUpIcon
                    v-if="header.column.getIsSorted() === 'asc'"
                    aria-hidden="true"
                  />
                  <ArrowDownIcon
                    v-else-if="header.column.getIsSorted() === 'desc'"
                    aria-hidden="true"
                  />
                  <ArrowUpDownIcon
                    v-else
                    aria-hidden="true"
                    class="text-muted-foreground"
                  />
                </Button>
                <FlexRender
                  v-else
                  :header="header"
                />
              </template>
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <template v-if="table.getRowModel().rows.length > 0">
            <TableRow
              v-for="row in table.getRowModel().rows"
              :key="row.id"
            >
              <TableCell
                v-for="cell in row.getAllCells()"
                :key="cell.id"
              >
                <FlexRender :cell="cell" />
              </TableCell>
            </TableRow>
          </template>
          <TableEmpty
            v-else
            :colspan="columns.length"
          >
            <template v-if="loading">
              Loading…
            </template>
            <slot
              v-else
              name="empty"
            >
              No results.
            </slot>
          </TableEmpty>
        </TableBody>
      </Table>
    </div>
    <nav
      v-if="pagination"
      aria-label="Pagination"
      class="flex justify-end gap-2"
    >
      <Button
        variant="outline"
        size="sm"
        :disabled="loading || !pagination.previous"
        @click="emit('previous')"
      >
        Previous page
      </Button>
      <Button
        variant="outline"
        size="sm"
        :disabled="loading || !pagination.next"
        @click="emit('next')"
      >
        Next page
      </Button>
    </nav>
  </div>
</template>
