# 0009. shadcn-vue in the shared package, tested in a browser

- Status: accepted
- Date: 2026-10-02
- Issue: packages/ui: shadcn-vue components and design tokens (#22)

## Context

Spec section 29 puts the component code in `web/packages/ui`, built with shadcn-vue on Reka UI and Tailwind v4, themed through semantic CSS variables. Both apps consume the package as TypeScript source (decision 0007). How the shadcn-vue CLI works in that layout, how the tokens reach Tailwind, how the result is tested and which form libraries are used had to be decided.

Checked on 2026-10-02:

- shadcn-vue 2.8.2 (MIT) has no monorepo template and `init --monorepo` is unsupported. `add --cwd <dir>` works in any directory with a `components.json` and rewrites the registry's `@/…` imports to the aliases declared there, resolved through the `paths` of `tsconfig.json`.
- Components are a base (`reka`) plus a visual style. The CLI writes the style's Tailwind utilities into the component at install time. The Nova components also use custom variants (`data-open:` and others) that upstream ships in `shadcn-vue/tailwind.css`, a file of the CLI package.
- Tailwind 4.3 has no configuration file. Variables are mapped with `@theme inline`, dark mode is a custom variant, and `node_modules` is not scanned unless a stylesheet declares `@source`.
- Upstream components import `@vueuse/core`. reka-ui 2.10.5 depends on `^14.1.0`; 15.0.0 is the newest release.
- vee-validate 4.15.1 is the stable release. `@vee-validate/zod` 4.15.1 requires Zod `^3.24.0`, while Zod is at 4.6.5. vee-validate 5, which accepts any Standard Schema, has been a beta since August 2025.
- TanStack Table for Vue is at 9.2.4, and upstream's data-table guide targets it.
- jsdom does not run Tailwind's output through a cascade, so it cannot verify a computed color. Vitest 5.0.3 browser mode with `@vitest/browser-playwright`, Playwright 1.63.0 and `vitest-browser-vue` 3.1.0 runs the same test files in headless Chromium. Playwright is already in the spec's stack (sections 30 and 37).

## Options considered

Internal imports of the package:

- **`@/` alias.** What the CLI writes by default, but each consuming app would resolve it against its own `@`.
- **The package's own name (`@hostmesh/ui/lib/utils`).** The convention of the shadcn/ui monorepo template, but from inside the package it is a self-reference, and it widens `exports`.
- **Node subpath imports (`#ui/lib/utils`).** Scoped to the package by definition, supported by Vite and by TypeScript's bundler resolution, and `exports` stays at the barrel and the stylesheet.

Custom variants:

- **Depend on `shadcn-vue` and import its `tailwind.css`.** Upstream's way, but it puts the CLI and its dependencies in the lockfile for one stylesheet.
- **Copy the variants.** About fifty lines under the MIT notice, owned like the components.

Verifying computed styles:

- **jsdom.** Already used by the apps; cannot do it.
- **Playwright end-to-end against a built app.** Verifies it, but needs an app page per component and a separate runner.
- **Vitest browser mode.** The same runner and test style, in a real browser.

Forms:

- **Zod 3 with `@vee-validate/zod`.** No code of our own, but every schema is written against a superseded major.
- **vee-validate 5 beta.** No adapter, but a beta.
- **vee-validate 4.15.1, Zod 4 and an adapter of our own** over Standard Schema, removed when vee-validate 5 is stable.

## Decision

`web/packages/ui/components.json` is written by hand with the style `reka-nova`, the base color neutral, and aliases under `#ui/`. Components are added with `pnpm dlx shadcn-vue@2.8.2 add <name> --cwd packages/ui` into `src/components/ui/`, which holds only CLI output and its MIT license. The package's `imports` map `#ui/lib/*` and `#ui/components/ui/*`; its `exports` are the barrel and `./styles.css`. The custom variants the components need are copied into `src/styles/variants.css`.

Tokens live in `src/styles/tokens.css` as CSS variables under `:root` and `.dark`, and `src/styles/index.css` maps them into Tailwind with `@theme inline` and declares the package's files with `@source`. An app imports `tailwindcss` and then `@hostmesh/ui/styles.css`. `useColorMode()` wraps VueUse's composable: without a stored choice the system setting decides.

`@vueuse/core` is `^14.4.0`, the major reka-ui uses.

The `ui` Vitest project runs in headless Chromium. The development image installs the Chromium build of the Playwright version in the catalog, where `playwright` is pinned exactly; CI installs it from the lockfile. The apps' tests stay on jsdom.

The data table uses TanStack Table v9 with the core features only. Forms use vee-validate 4.15.1 and Zod 4 through `toTypedSchema` in `@hostmesh/ui`.

## Consequences

- Upgrading a component means re-running `add --overwrite` and reading the diff. Local changes to CLI files are listed in the package README, so they can be re-applied.
- A component that uses a utility or variant of upstream's `tailwind.css` that was not copied renders unstyled in that respect. The README says how to check after adding one.
- The style is fixed. Changing it means re-adding every component.
- The Playwright version appears in the catalog and in the Dockerfile. The smoke test fails when they differ.
- `pnpm test` needs Chromium: the development image has it, CI installs it, and a host without Docker must run `playwright install`.
- The adapter is removed, and `@vueuse/core` is raised to 15, when vee-validate 5 is stable and reka-ui moves. Both are new changes, not new decisions.
- shadcn-vue code is MIT inside an Apache-2.0 repository. The notice lives in `src/components/ui/LICENSE` and `NOTICE`; files that mix both carry `Apache-2.0 AND MIT`.
