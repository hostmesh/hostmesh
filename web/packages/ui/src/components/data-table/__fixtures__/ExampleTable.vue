<!-- SPDX-License-Identifier: Apache-2.0 -->
<!-- An example for the tests only. It is not exported by the package. -->
<script setup lang="ts">
import { h } from 'vue'

import { Button } from '#ui/components/ui/button'

import DataTable from '../DataTable.vue'
import { createDataTableColumnHelper } from '../features'

export interface Website {
  domain: string
  status: 'active' | 'suspended'
}

// An explicit default: ESLint's vue/require-default-prop asks for one.
withDefaults(
  defineProps<{
    websites: Website[]
    emptyText?: string
  }>(),
  { emptyText: undefined },
)

const columnHelper = createDataTableColumnHelper<Website>()

const columns = columnHelper.columns([
  columnHelper.accessor('domain', { header: 'Domain' }),
  columnHelper.accessor('status', {
    header: 'Status',
    cell: (info) => (info.getValue() === 'active' ? 'Active' : 'Suspended'),
  }),
  columnHelper.display({
    id: 'actions',
    header: 'Actions',
    cell: ({ row }) => h(Button, { size: 'sm', variant: 'outline' }, () => `Open ${row.original.domain}`),
  }),
])
</script>

<template>
  <DataTable
    :columns="columns"
    :data="websites"
  >
    <template
      v-if="emptyText"
      #empty
    >
      {{ emptyText }}
    </template>
  </DataTable>
</template>
