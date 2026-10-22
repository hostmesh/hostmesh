# @hostmesh/ui

The shared components and design tokens of the admin app and the customer portal (spec section 29). The component code is the project's own: it is written into this package by the shadcn-vue CLI and built on Reka UI and Tailwind v4. See decision [0009](../../../docs/decisions/0009-shadcn-vue-in-the-ui-package-and-browser-mode-tests.md).

## Using it

```css
/* an app's style.css */
@import "tailwindcss";
@import "@hostmesh/ui/styles.css";
```

```ts
import { Button } from '@hostmesh/ui'
```

Apps import only `@hostmesh/ui` and `@hostmesh/ui/styles.css`: the package re-exports what an app needs from the libraries behind it (`toast`, `useForm`, `z`). The stylesheet registers the package's components with Tailwind (`@source`), so an app needs no further configuration.

## Layout

| Path                         | Contents                                                                                                            |
|------------------------------|---------------------------------------------------------------------------------------------------------------------|
| `src/index.ts`               | The public exports.                                                                                                 |
| `src/styles/tokens.css`      | The design tokens, light under `:root` and dark under `.dark`.                                                      |
| `src/styles/index.css`       | The stylesheet apps import: tokens, the Tailwind mapping, the base layer.                                           |
| `src/styles/variants.css`    | Tailwind variants the components use, copied from shadcn-vue.                                                       |
| `src/components/ui/`         | What the shadcn-vue CLI wrote, and nothing else. MIT, see its `LICENSE`.                                            |
| `src/components/data-table/` | The data-table pattern: `DataTable`, driven by the API, and its column helper.                                      |
| `src/components/form/`       | The form pattern: `FormField` and `toTypedSchema`.                                                                  |
| `src/components/qr-code/`    | `QrCode`: a QR code as an inline SVG, in the scannable pair of tokens. Used by both apps for two-factor enrollment. |
| `src/lib/utils.ts`           | `cn()`, from shadcn-vue.                                                                                            |
| `src/composables/`           | `useColorMode()`.                                                                                                   |
| `src/test/`                  | The test stylesheet with its token fixtures, and the tests and samples of the CLI's components.                     |

Inside the package, CLI code is imported as `#ui/components/ui/<name>`, `#ui/lib/<name>` and `#ui/composables/<name>`. These are Node subpath imports, declared under `imports` in `package.json`: one entry for each directory the CLI writes to. The `paths` of `tsconfig.json` are for the CLI and are wider (`#ui/*`), so a path outside those directories typechecks and then fails in Vite; add it to `imports` first.

## Design tokens

Components read only these variables, through Tailwind utilities such as `bg-primary` and `rounded-lg`. No component has a hard-coded color: `src/styles/no-hard-coded-colors.test.ts` fails on a Tailwind palette class (`bg-black`, `text-zinc-500`) or a color literal in the package's source.

| Token                                       | Use                                               |
|---------------------------------------------|---------------------------------------------------|
| `--background`, `--foreground`              | The page and its text.                            |
| `--card`, `--card-foreground`               | Cards.                                            |
| `--popover`, `--popover-foreground`         | Dialogs, toasts and other floating surfaces.      |
| `--primary`, `--primary-foreground`         | The main action.                                  |
| `--secondary`, `--secondary-foreground`     | Secondary actions.                                |
| `--muted`, `--muted-foreground`             | Subdued surfaces and text.                        |
| `--accent`, `--accent-foreground`           | Hover and selection.                              |
| `--destructive`, `--destructive-foreground` | Destructive actions and errors.                   |
| `--overlay`                                 | The scrim behind a dialog, without its opacity.   |
| `--scannable`, `--scannable-foreground`     | A code a camera reads; the same in both modes.    |
| `--border`, `--input`, `--ring`             | Borders, input borders, focus rings.              |
| `--radius`                                  | The corner radius every radius step derives from. |
| `--font-sans`, `--font-mono`                | The font stacks.                                  |

`--destructive-foreground` is reserved: no component reads it yet, because the destructive button puts `--destructive` text on a tint of it. It is there, and mapped to `text-destructive-foreground`, for the first component that puts text on a destructive surface.

A theme redefines any subset of them in a stylesheet loaded after `@hostmesh/ui/styles.css`, under `:root` and, for dark mode, under `.dark`. Tokens it leaves out keep their defaults in both modes.

A token set under `:root` only applies in dark mode too: the theme's `:root` and the default `.dark` have the same specificity, and the theme comes later. So a theme that changes a color for light sets it under `.dark` as well, unless the same value suits both:

```css
:root {
  --primary: rgb(0 128 128);
}

.dark {
  --primary: rgb(128 128 0);
}
```

## Dark mode

Dark mode is the class `dark` on `<html>`. An app calls `useColorMode()` once at start:

```ts
import { useColorMode } from '@hostmesh/ui'

const { preference, mode } = useColorMode()
```

`preference` is `'auto'`, `'light'` or `'dark'`. `'auto'`, the default, follows the system setting, also when it changes. Setting it to `'light'` or `'dark'` stores the choice in `localStorage` under `hostmesh-color-mode`. `mode` is what is applied.

## Data tables

`DataTable` renders columns and rows with TanStack Table v9 and the table components, and shows an empty state (the `empty` slot, `No results.` by default) when there are no rows:

```ts
import { createDataTableColumnHelper } from '@hostmesh/ui'

const columnHelper = createDataTableColumnHelper<Website>()

const columns = columnHelper.columns([
  columnHelper.accessor('domain', { header: 'Domain' }),
  columnHelper.display({ id: 'actions', header: 'Actions', cell: ({ row }) => h(RowActions, { website: row.original }) }),
])
```

```vue
<DataTable :columns="columns" :data="websites" />
```

A list from the API is sorted, filtered and paged by the API ([decision 0019](../../../docs/decisions/0019-list-endpoints.md)). `DataTable` shows that state and asks for the next one; it never reorders or filters the rows it has:

```vue
<DataTable
  v-model:sorting="sorting"
  :columns="columns"
  :data="events"
  :loading="loading"
  :pagination="{ previous: prevCursor !== null, next: nextCursor !== null }"
  @previous="showPage(prevCursor)"
  @next="showPage(nextCursor)"
>
  <template #toolbar>
    <form role="search">…the screen's filters…</form>
  </template>
</DataTable>
```

- **Sorting:** a column offers it when its definition says `enableSorting: true`, for a field the API sorts by. Its header is then a button and carries `aria-sort`. `v-model:sorting` (`DataTableSorting`, one entry) holds the order: the screen turns it into the API's `sort` parameter and starts again at the first page. A column, once sorted, toggles between ascending and descending; the API always has an order.
- **Pages:** with `pagination`, a "Previous page" and a "Next page" button, enabled for the cursors the API returned, emit `previous` and `next`. Without it there is no pager.
- **Filters:** the `toolbar` slot, above the table. Each screen builds its own form and maps it to the API's parameters. `NativeSelect` is the browser's select, for a filter by enum.
- **Loading:** `loading` marks the table's region `aria-busy`, disables the pager and the sort buttons, and shows `Loading…` instead of the empty state.

Only sorting is registered (`src/components/data-table/features.ts`). TanStack's filtering and pagination features work on the rows the table holds, which is one page. Hideable columns would register the column-visibility feature and switch to `getVisibleCells()`. Examples are in `src/components/data-table/__fixtures__/` (`ExampleTable.vue`, `ListTable.vue`); they exist for the tests and are not exported. The admin app's audit log (`apps/admin/src/views/audit/AuditView.vue`) is the first real list.

## Forms

Forms use vee-validate with a Zod schema, and `FormField` for each field. `FormField` renders the label, the description and the errors, and gives the control its `id`, `aria-invalid` and `aria-describedby`:

```vue
<script setup lang="ts">
import { Button, FormField, Input, toTypedSchema, useForm, z } from '@hostmesh/ui'

const schema = z.object({ email: z.email('Enter a valid email address.') })

const { handleSubmit } = useForm({
  validationSchema: toTypedSchema(schema),
  initialValues: { email: '' },
})

const onSubmit = handleSubmit((values) => {
  // values is typed from the schema
})
</script>

<template>
  <form novalidate @submit="onSubmit">
    <FormField v-slot="{ field }" name="email" label="Email">
      <Input v-bind="field" type="email" />
    </FormField>
    <Button type="submit">Save</Button>
  </form>
</template>
```

`useForm` is vee-validate's and `z` is Zod's, re-exported so that an app depends on neither and writes its schemas with the Zod version the adapter is tested against. This `z` runs with `jitless` (`src/components/form/zod.ts`): the production policy forbids the `new Function` zod would otherwise probe for. ESLint rejects importing `zod` itself in an app. `toTypedSchema` adapts a Zod 4 schema (any Standard Schema) to vee-validate 4. It reports errors only; pass `initialValues` to `useForm`. A schema describes input ergonomics; the API validates again and remains the authority (spec section 29). A full example is in `src/components/form/__fixtures__/ExampleForm.vue`; it exists for the tests and is not exported.

## Adding a component

From the repository root:

```bash
docker compose -f infrastructure/development/compose.yaml exec node \
  pnpm dlx shadcn-vue@2.8.2 add <name> --cwd packages/ui --yes --overwrite
```

Then:

1. Set the dependencies the CLI added in `package.json` back to `catalog:`, add new ones to the catalog in `web/pnpm-workspace.yaml`, and run `pnpm install`.
2. Check that the component uses no utility of upstream's `tailwind.css` that `src/styles/variants.css` lacks (its header lists what was left out).
3. Re-apply the local changes listed below if `--overwrite` replaced a changed file.
4. Export it from `src/index.ts`, with a test written first.

Do not reformat the CLI's files. ESLint checks them for defects only.

## Local changes to upstream files

| File                                               | Change                                                                                          |
|----------------------------------------------------|-------------------------------------------------------------------------------------------------|
| `src/components/ui/dialog/DialogOverlay.vue`       | `bg-black/10` is `bg-overlay/10`: the scrim reads the `--overlay` token (no hard-coded colors). |
| `src/components/ui/dialog/DialogScrollContent.vue` | `bg-black/80` is `bg-overlay/80`, for the same reason.                                          |
| `src/components/ui/native-select/*.vue`            | `cn` is imported from `#ui/lib/utils`. The CLI wrote `from 'cn'` and added an npm package `cn`. |

## Upstream quirks

Defects and oddities of the CLI's files that are left as upstream wrote them. Check them again after an `add --overwrite`.

- `sonner/Sonner.vue` sets `--gray2` to `hsl(var(--popover) / 0.9)`. The tokens are not HSL channels, so the value is invalid and the hover background of a toast's close button falls back to none.
- `sonner/Sonner.vue` does not pass `theme` to vue-sonner, which then uses its light theme. Plain toasts read the tokens and follow dark mode; `richColors` toasts take vue-sonner's light colors in both modes.
- `dialog/DialogScrollContent.vue` is in the older style (`bg-background`, `data-[state=open]:`) next to the Nova `DialogContent` (`bg-popover`, `data-open:`), so the two dialogs differ in surface and animation.

## Tests

`pnpm vitest run --project ui` runs the tests in headless Chromium (Vitest browser mode with Playwright), with Tailwind compiled, so a test can assert a computed style. `src/test/styles.css` defines `tokens-non-default`, a token set in which every value is distinct, and `tokens-partial` and `tokens-partial-dark`, themes that set one color; a test puts the class on `<html>`. The `node` service of the development stack has Chromium; elsewhere run `pnpm --filter @hostmesh/ui exec playwright install chromium` once.

## Licensing

The package is Apache-2.0. The code from shadcn-vue is MIT (Copyright (c) 2023 unovue): everything in `src/components/ui/`, `src/lib/utils.ts` and `src/styles/variants.css`. Files that combine both say `Apache-2.0 AND MIT` in their header. The MIT text is in `src/components/ui/LICENSE`, and the repository's `NOTICE` names it.
