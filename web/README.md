# Hostmesh web workspace

The two Vue 3 single-page applications and the packages they share. Both apps are static builds and ordinary clients of the API: they have no privileged path (spec sections 28 and 29). See `docs/spec/HostMesh_v0.1.md`, sections 27, 29 and 37.

## Layout

| Path                   | Package                | Contents                                                                               |
|------------------------|------------------------|----------------------------------------------------------------------------------------|
| `apps/admin/`          | `@hostmesh/admin`      | The admin app. A placeholder page for now.                                             |
| `apps/portal/`         | `@hostmesh/portal`     | The customer portal. A placeholder page for now.                                       |
| `packages/ui/`         | `@hostmesh/ui`         | Shared components and design tokens. An empty skeleton until #22.                      |
| `packages/api-client/` | `@hostmesh/api-client` | The typed API client. `src/generated/` is generated from `contracts/api/openapi.json`. |
| `themes/`              |                        | Optional operator themes, selected at build time with `THEME=<name>`.                  |

Shared packages export their TypeScript source, so they have no build step. Both apps depend on them as `workspace:*` packages, and a test in each app (`src/workspace.test.ts`) checks that they resolve.

## Commands

The runtime is the `node` service of the development stack (`infrastructure/development/README.md`): Node 24 and pnpm 12, pinned by `.nvmrc` and the `packageManager` field of `package.json`. From the repository root:

```bash
make -C infrastructure/development up install
docker compose -f infrastructure/development/compose.yaml exec node pnpm check
```

| pnpm script    | What it does                                                                                   |
|----------------|------------------------------------------------------------------------------------------------|
| `check`        | `lint`, `typecheck`, `test` and `build`. Run it before every push.                             |
| `lint`         | ESLint (flat config) over the whole workspace, warnings fail.                                  |
| `lint:fix`     | ESLint with `--fix`.                                                                           |
| `typecheck`    | `vue-tsc --noEmit` in every package, TypeScript strict.                                        |
| `test`         | Vitest, every project.                                                                         |
| `build`        | Builds each app to `apps/<name>/dist/`.                                                        |
| `generate:api` | Regenerates `packages/api-client/src/generated/schema.d.ts` from `contracts/api/openapi.json`. |

One test: `… exec node pnpm vitest run -t "<name>"`. One project: `… exec node pnpm vitest run --project admin` (or `portal`, or `api-client`).

CI (`.github/workflows/web.yml`) runs the same scripts on every pull request that touches `web/` or `contracts/api/`, on the Node and pnpm versions pinned here, and regenerates the API client, failing on any difference.

## API client

The apps reach the API only through `@hostmesh/api-client` (decision 0008):

```ts
import { createApiClient } from '@hostmesh/api-client'

const api = createApiClient({ baseUrl: 'https://api.example.com/api/v1' })
const { data, error } = await api.GET('/health')
```

After any API change, regenerate the specification and then the client, and commit both:

```bash
docker compose -f infrastructure/development/compose.yaml exec php composer openapi
docker compose -f infrastructure/development/compose.yaml exec node pnpm generate:api
```

Never edit `packages/api-client/src/generated/` by hand. CI regenerates it and fails on any difference. `src/health.test-d.ts` holds the type-level tests: `pnpm typecheck` compiles them, and they fail when the client and the specification disagree. In tests, type mock responses from the generated `paths` (see `src/client.test.ts`), so that a contract change breaks the typecheck.

## Rules

- Versions live in the `catalog` of `pnpm-workspace.yaml`; packages refer to them with `catalog:`. `pnpm-lock.yaml` is committed.
- The apps and `@hostmesh/ui` never call the network themselves. ESLint rejects the direct ways to do it there: `fetch` and `XMLHttpRequest` (also through `window`, `globalThis` and `self`), `navigator.sendBeacon`, and imports of `openapi-fetch` and other HTTP clients. It cannot catch every indirection, so review still applies.
- Tests come first (spec section 37). A test file sits next to the code it tests, as `*.test.ts`.
