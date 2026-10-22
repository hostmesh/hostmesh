<!-- SPDX-License-Identifier: Apache-2.0 -->
<!-- A list driven by an API, for the tests only. It is not exported by the package. -->
<script setup lang="ts">
import { ref } from 'vue'

import DataTable from '../DataTable.vue'
import { createDataTableColumnHelper, type DataTableSorting } from '../features'
import type { Website } from './ExampleTable.vue'

const props = withDefaults(
  defineProps<{
    websites: Website[]
    sorting?: DataTableSorting
    pagination?: { previous: boolean; next: boolean }
    loading?: boolean
  }>(),
  { sorting: () => [], pagination: undefined, loading: false },
)

const columnHelper = createDataTableColumnHelper<Website>()

const columns = columnHelper.columns([
  columnHelper.accessor('domain', { header: 'Domain', enableSorting: true }),
  columnHelper.accessor('status', { header: 'Status' }),
])

// What the screen would send to the API.
const sorting = ref<DataTableSorting>(props.sorting)
const pages = ref<string[]>([])
</script>

<template>
  <DataTable
    v-model:sorting="sorting"
    :columns="columns"
    :data="websites"
    :pagination="pagination"
    :loading="loading"
    @previous="pages.push('previous')"
    @next="pages.push('next')"
  >
    <template #toolbar>
      <label>Search <input type="search"></label>
    </template>
  </DataTable>
  <output data-testid="sorting">{{ JSON.stringify(sorting) }}</output>
  <output data-testid="pages">{{ pages.join(',') }}</output>
</template>
