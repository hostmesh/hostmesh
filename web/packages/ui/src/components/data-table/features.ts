// SPDX-License-Identifier: Apache-2.0
import { createColumnHelper, rowSortingFeature, tableFeatures, type RowData, type SortingState } from '@tanstack/vue-table'

// TanStack Table v9 is feature-based: a table has only the behavior that is
// registered here. The core row model is always included. Lists are sorted,
// filtered and paginated by the API (decision 0019), so only sorting is
// registered, as state the table shows and the screen sends to the API.
// Filters belong to the screen (the `toolbar` slot) and pages to the API's
// cursors (the `pagination` prop): TanStack's filtering and pagination
// features work on rows the table holds, and these tables hold one page.
export const dataTableFeatures = tableFeatures({ rowSortingFeature })

export type DataTableFeatures = typeof dataTableFeatures

/** Which column a list is sorted by, and which way. One entry at most: an API sorts by one field. */
export type DataTableSorting = SortingState

/** A column helper for `DataTable` columns over rows of type `TData`. */
export function createDataTableColumnHelper<TData extends RowData>() {
  return createColumnHelper<DataTableFeatures, TData>()
}
