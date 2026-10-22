# 0019. List endpoints: cursor pages, flat filters and one sort parameter

- Status: accepted
- Date: 2026-10-22
- Issue: DataTable: sorting, filtering and pagination driven by the API (#143), with the audit screen (#150)

## Context

Spec section 28 names list endpoints (`GET /api/v1/admin/nodes`, `GET /api/v1/customer/websites` …) but no convention for their parameters or their response. `DataTable` in `@hostmesh/ui` is to sort, filter and page through the API, with no rules of its own in the frontend (section 35), so every list has to answer the same way.

The only list so far is `GET /api/v1/admin/audit-events` (decision 0013, issue #27): filters as flat query parameters (`actor_type`, `action`, `from`, `to` …), Laravel's cursor pagination (`per_page` 1–100, default 50, and `cursor`), and Laravel's response with `data`, `links` and `meta.{path, per_page, next_cursor, prev_cursor}`. It had no sort parameter.

Checked on 2026-10-22: `@tanstack/vue-table` 9.2.8 registers sorting as a feature (`rowSortingFeature`) with `manualSorting` and an external `state.sorting`. Its filtering and pagination features work on the rows the table holds; a table that holds one page of an API list has nothing for them to work on.

## Options considered

1. **Cursor pages everywhere.** What the audit log already does. A page is stable while rows are added, and the cost of a page does not grow with its depth. No total and no page numbers: previous and next only.
2. **Offset pages** (`page`, `per_page`, `meta.total`, `last_page`). Page numbers and a total in the UI. Every request needs a `COUNT`, pages shift while rows are added, and the audit log would have to change or be the exception.
3. **Both, per endpoint:** cursor for append-only tables (audit, jobs), offset for small lists (nodes, plans). Two shapes in `DataTable` and in every client.

Filtering in `DataTable`, either as TanStack's column filters with manual filtering (a text input per column, awkward for enums and date ranges) or as a toolbar each screen builds. Hideable columns (TanStack's column-visibility feature), now or with the first screen that needs them.

## Decision

Chosen by the owner on 2026-10-22: option 1, filters in a toolbar of the screen, no hideable columns yet.

- **Pages:** cursor pagination for every list. `per_page`, an integer from 1 to 100, with a default the endpoint names (50 for the audit log), and `cursor`, the `meta.next_cursor` or `meta.prev_cursor` of an earlier page with the same filters and order. A cursor the endpoint did not issue answers the first page. No `page` parameter, no total.
- **Response:** `data`, `links` (`first`, `last`, `prev`, `next`) and `meta` (`path`, `per_page`, `next_cursor`, `prev_cursor`), as Laravel's cursor paginator writes them through an API Resource collection.
- **Filters:** flat query parameters named after the field (`action=…`, `actor_type=…`), ranges as `from` and `to`, both inclusive. A value the endpoint does not understand is a 422, never ignored.
- **Order:** one parameter, `sort=<field>` for ascending or `sort=-<field>` for descending, with the values each endpoint offers as an enum in the OpenAPI document. Anything else is a 422. Without `sort`, the endpoint's default order. The order always ends in a unique column, so a cursor never skips or repeats a row. The audit log offers `-created_at` (the default) and `created_at`, then `id` within one second.
- **Enforced:** `tests/Feature/Contracts/OpenApiSpecTest.php` checks every operation that answers a `data` array for these parameters, this response and the form of its `sort` values.
- **`DataTable`:** registers sorting only, with `manualSorting`. A column offers sorting when its definition says `enableSorting: true`; the header is then a button with `aria-sort`, and the order goes to the screen through `v-model:sorting`, one column at a time, never removed. Pages are a previous and a next button (`pagination` prop, `previous` and `next` events). Filters are the screen's, in the `toolbar` slot. `loading` marks the table busy and holds its controls.
- **Screens** keep filters, order and cursor in the address, so a reload and the back button show the same page, and a change of filters or order starts at the first page.

## Consequences

- No list can show "page 3 of 7" or a total. A list that needs a total adds it as its own field, at the cost of a count.
- Sorting by a column that is not unique needs the key as a second column in the order and in the cursor; the audit log does this. A cursor carries the values of the order's columns, so it belongs to one order: a screen drops it when the order or the filters change.
- A cursor is encoded by Laravel from the last row's values. The audit log checks that a cursor holds a UUID and a time before it reaches PostgreSQL; a new list checks its own columns the same way.
- Hideable columns, when a screen needs them, register TanStack's column-visibility feature and switch `DataTable` to `getVisibleCells()`.
- Spec section 28 does not describe this convention yet: a `spec-change` issue carries it.
