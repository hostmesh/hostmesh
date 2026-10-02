# Hostmesh web workspace

The two Vue 3 single-page applications and the packages they share. Both apps are static builds and ordinary clients of the API: they have no privileged path (spec sections 28 and 29). See `docs/spec/HostMesh_v0.1.md`, sections 27, 29 and 37.

## Layout

| Path           | Package            | Contents                                                              |
|----------------|--------------------|-----------------------------------------------------------------------|
| `apps/admin/`  | `@hostmesh/admin`  | The admin app. A placeholder page for now.                            |
| `apps/portal/` | `@hostmesh/portal` | The customer portal. A placeholder page for now.                      |
| `packages/ui/` | `@hostmesh/ui`     | Shared components and design tokens. An empty skeleton until #22.     |
| `themes/`      |                    | Optional operator themes, selected at build time with `THEME=<name>`. |

Shared packages export their TypeScript source, so they have no build step.

## Commands

The runtime is the `node` service of the development stack (`infrastructure/development/README.md`): Node 24 and pnpm 12, pinned by `.nvmrc` and the `packageManager` field of `package.json`. From the repository root:

```bash
make -C infrastructure/development up install
docker compose -f infrastructure/development/compose.yaml exec node pnpm check
```

| pnpm script | What it does                                                       |
|-------------|--------------------------------------------------------------------|
| `check`     | `lint`, `typecheck`, `test` and `build`. Run it before every push. |
| `lint`      | ESLint (flat config) over the whole workspace, warnings fail.      |
| `lint:fix`  | ESLint with `--fix`.                                               |
| `typecheck` | `vue-tsc --noEmit` in every package, TypeScript strict.            |
| `test`      | Vitest, every project.                                             |
| `build`     | Builds each app to `apps/<name>/dist/`.                            |

One test: `… exec node pnpm vitest run -t "<name>"`. One project: `… exec node pnpm vitest run --project admin` (or `portal`).

## Rules

- Versions live in the `catalog` of `pnpm-workspace.yaml`; packages refer to them with `catalog:`. `pnpm-lock.yaml` is committed.
- The apps and `@hostmesh/ui` never call the network themselves. ESLint rejects `fetch`, `XMLHttpRequest` and a direct `openapi-fetch` import there.
- Tests come first (spec section 37). A test file sits next to the code it tests, as `*.test.ts`.
