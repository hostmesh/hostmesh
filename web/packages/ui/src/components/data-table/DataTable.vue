<!--
  SPDX-License-Identifier: Apache-2.0 AND MIT
  Copyright (c) 2023 unovue (the table markup follows shadcn-vue's data-table
  guide; license: ../ui/LICENSE)
-->
<script setup lang="ts" generic="TData extends RowData">
import { FlexRender, useTable, type ColumnDef, type RowData } from '@tanstack/vue-table'

import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '#ui/components/ui/table'

import { dataTableFeatures, type DataTableFeatures } from './features'

const props = defineProps<{
  columns: ColumnDef<DataTableFeatures, TData>[]
  data: TData[]
}>()

// Getters, so that the table always reads the current props.
const table = useTable({
  features: dataTableFeatures,
  get data() {
    return props.data
  },
  get columns() {
    return props.columns
  },
})
</script>

<template>
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
        >
          <FlexRender
            v-if="!header.isPlaceholder"
            :header="header"
          />
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
        <slot name="empty">
          No results.
        </slot>
      </TableEmpty>
    </TableBody>
  </Table>
</template>
