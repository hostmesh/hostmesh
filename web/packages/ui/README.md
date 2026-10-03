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

Apps import only `@hostmesh/ui` and `@hostmesh/ui/styles.css`. The stylesheet registers the package's files with Tailwind (`@source`), so an app needs no further configuration.

## Layout

| Path                      | Contents                                                                                        |
|---------------------------|-------------------------------------------------------------------------------------------------|
| `src/index.ts`            | The public exports.                                                                             |
| `src/styles/tokens.css`   | The design tokens, light under `:root` and dark under `.dark`.                                  |
| `src/styles/index.css`    | The stylesheet apps import: tokens, the Tailwind mapping, the base layer.                       |
| `src/styles/variants.css` | Tailwind variants the components use, copied from shadcn-vue.                                   |
| `src/components/ui/`      | What the shadcn-vue CLI wrote, and nothing else. MIT, see its `LICENSE`.                        |
| `src/lib/utils.ts`        | `cn()`, from shadcn-vue.                                                                        |
| `src/test/`               | The test stylesheet with its token fixtures, and the tests and samples of the CLI's components. |

Inside the package, CLI code is imported as `#ui/components/ui/<name>` and `#ui/lib/utils`. These are Node subpath imports, declared under `imports` in `package.json` and mirrored in the `paths` of `tsconfig.json` for the CLI.

## Design tokens

Components read only these variables, through Tailwind utilities such as `bg-primary` and `rounded-lg`. No component has a hard-coded color.

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
| `--border`, `--input`, `--ring`             | Borders, input borders, focus rings.              |
| `--radius`                                  | The corner radius every radius step derives from. |
| `--font-sans`, `--font-mono`                | The font stacks.                                  |

A theme redefines any subset of them in a stylesheet loaded after `@hostmesh/ui/styles.css`, under `:root` and, for dark mode, under `.dark`. Tokens it leaves out keep their defaults.

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

None.

## Tests

`pnpm vitest run --project ui` runs the tests in headless Chromium (Vitest browser mode with Playwright), with Tailwind compiled, so a test can assert a computed style. `src/test/styles.css` defines `tokens-non-default`, a token set in which every value is distinct, and `tokens-partial`; a test puts the class on `<html>`. The `node` service of the development stack has Chromium; elsewhere run `pnpm --filter @hostmesh/ui exec playwright install chromium` once.

## Licensing

The package is Apache-2.0. The code from shadcn-vue is MIT (Copyright (c) 2023 unovue): everything in `src/components/ui/`, `src/lib/utils.ts` and `src/styles/variants.css`. Files that combine both say `Apache-2.0 AND MIT` in their header. The MIT text is in `src/components/ui/LICENSE`, and the repository's `NOTICE` names it.
