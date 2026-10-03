// SPDX-License-Identifier: Apache-2.0
import { createColumnHelper, tableFeatures, type RowData } from '@tanstack/vue-table'

// TanStack Table v9 is feature-based: a table has only the behavior that is
// registered here. The core row model is always included. Sorting, filtering
// and pagination are not registered: lists are paginated by the API, so they
// are added, as server-driven state, with the first screen that needs them.
export const dataTableFeatures = tableFeatures({})

export type DataTableFeatures = typeof dataTableFeatures

/** A column helper for `DataTable` columns over rows of type `TData`. */
export function createDataTableColumnHelper<TData extends RowData>() {
  return createColumnHelper<DataTableFeatures, TData>()
}
