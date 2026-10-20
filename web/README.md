# Hostmesh web workspace

The two Vue 3 single-page applications and the packages they share. Both apps are static builds and ordinary clients of the API: they have no privileged path (spec sections 28 and 29). See `docs/spec/HostMesh_v0.1.md`, sections 27, 29 and 37.

## Layout

| Path                   | Package                | Contents                                                                                                                                                       |
|------------------------|------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `apps/admin/`          | `@hostmesh/admin`      | The admin app: login with mandatory two-factor, the application layout, the branding screen.                                                                   |
| `apps/portal/`         | `@hostmesh/portal`     | The customer portal: login, the two-factor challenge, the account screen with two-factor enrollment, the application layout, brand colors applied at run time. |
| `packages/ui/`         | `@hostmesh/ui`         | Shared components and design tokens, built with shadcn-vue. See its [README](packages/ui/README.md).                                                           |
| `packages/api-client/` | `@hostmesh/api-client` | The typed API client. `src/generated/` is generated from `contracts/api/openapi.json`.                                                                         |
| `e2e/`                 | `@hostmesh/e2e`        | Playwright tests against the stack in end-to-end mode: the Slice 0 exit test.                                                                                  |
| `themes/`              |                        | Optional operator themes, selected at build time with `THEME=<name>`.                                                                                          |

Shared packages export their TypeScript source, so they have no build step. Both apps depend on them as `workspace:*` packages, and a test in each app (`src/workspace.test.ts`) checks that they resolve.

## Commands

The runtime is the `node` service of the development stack (`infrastructure/development/README.md`): Node 24 and pnpm 12, pinned by `.nvmrc` and the `packageManager` field of `package.json`. From the repository root:

```bash
make -C infrastructure/development up install
docker compose -f infrastructure/development/compose.yaml exec node pnpm check
```

| pnpm script    | What it does                                                                                                                                                                  |
|----------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `check`        | `lint`, `typecheck`, `test:coverage` and `build`. Run it before every push.                                                                                                   |
| `lint`         | ESLint (flat config) over the whole workspace, warnings fail.                                                                                                                 |
| `lint:fix`     | ESLint with `--fix`.                                                                                                                                                          |
| `typecheck`    | TypeScript strict in every package: `vue-tsc` for browser code (no Node types), `tsc` for the Vite and Vitest configuration and for the API client with its type-level tests. |
| `test`         | Vitest, every project: each app (jsdom) and each package. The `ui` project runs in headless Chromium.                                                                         |
| `test:coverage`| The same with code coverage of the whole workspace; fails below 95% of statements, branches, functions or lines.                                                              |
| `build`        | Builds each app to `apps/<name>/dist/`.                                                                                                                                       |
| `generate:api` | Empties `packages/api-client/src/generated/` and regenerates `schema.d.ts` from `contracts/api/openapi.json`.                                                                 |
| `e2e`          | Playwright, against the stack in end-to-end mode. Not part of `check`. Run it with `make -C infrastructure/development e2e`, which seeds first.                               |

One test: `… exec node pnpm vitest run -t "<name>"`. One project: `… exec node pnpm vitest run --project admin` (or `portal`, `ui`, `api-client`).

Coverage (spec section 37) is measured by `@vitest/coverage-v8` over the source of both apps and both packages in one run, configured in `vitest.config.ts`. Not measured: tests and their helpers, the generated API client, and `packages/ui/src/components/ui/`, which the shadcn-vue CLI writes. Run it for the whole workspace only: with `--project <name>` Vitest measures nothing and the thresholds pass on an empty report. The provider's version must equal Vitest's; raise the two together.

The `ui` project needs Chromium: the `node` service has it, and CI installs it. Outside the container run `pnpm --filter @hostmesh/ui exec playwright install chromium` once.

CI (`.github/workflows/web.yml`) runs the same scripts on every pull request that touches `web/` or `contracts/api/`, on the Node and pnpm versions pinned here, installs Chromium for the component tests, and regenerates the API client, failing on any difference.

## End-to-end tests

`e2e/` holds the Playwright suite (decision 0016). It drives the built apps on `admin.` and `app.hostmesh.test` against the real control plane, PostgreSQL and Redis, and runs in the `node` service:

```bash
make -C infrastructure/development e2e-up   # build, switch the stack to end-to-end mode
make -C infrastructure/development e2e      # seed, then run
make -C infrastructure/development e2e ARGS="tests/admin-login.spec.ts"
make -C infrastructure/development up       # back to development mode
```

After a change to an app, run `e2e-up` again: it rebuilds. The report is in `e2e/playwright-report/` and the traces of failed tests in `e2e/test-results/`. Open them with Playwright's server in the `node` image, on http://localhost:9323, and stop it with Ctrl+C. The report lists every test and opens the trace of a failed one:

```bash
docker compose -f infrastructure/development/compose.yaml run --rm --no-deps \
  -p 127.0.0.1:9323:9323 -w /workspace/web/e2e node \
  pnpm exec playwright show-report --host 0.0.0.0 --port 9323 playwright-report
```

One trace on its own:

```bash
docker compose -f infrastructure/development/compose.yaml run --rm --no-deps \
  -p 127.0.0.1:9323:9323 -w /workspace/web/e2e node \
  pnpm exec playwright show-trace --host 0.0.0.0 --port 9323 test-results/<test>/trace.zip
```

Opened as a file, `playwright-report/index.html` lists the tests but shows no trace: the trace viewer must be loaded over HTTP.

When the suite fails in CI, the run keeps the report and the traces for 7 days as the artifact `playwright-report`. Its zip holds the folders `playwright-report/` and `test-results/`, so `show-report` refuses the zip itself. Unpack it under `test-results/`, which is ignored by git and emptied by the next local run, and serve the report from there:

```bash
gh run download <run-id> -n playwright-report -D web/e2e/test-results/ci
# or, with the zip from the run's page:
unzip playwright-report.zip -d web/e2e/test-results/ci

docker compose -f infrastructure/development/compose.yaml run --rm --no-deps \
  -p 127.0.0.1:9323:9323 -w /workspace/web/e2e node \
  pnpm exec playwright show-report --host 0.0.0.0 --port 9323 test-results/ci/playwright-report
```

Use the `node` image, not pnpm on the host: there `show-trace` fails without Playwright's Chromium, and `pnpm exec` first reinstalls `web/node_modules` from the host's store. Run `… exec node pnpm install` afterwards if that happened.

Rules for a test:

- Import `test` and `expect` from `support/test`, never from `@playwright/test`: that fixture fails a test on an uncaught error or a Content Security Policy violation.
- Find elements by role or label, or by an existing `data-test` attribute. Wait with `expect(…)`, never with a timeout.
- Accounts come from `support/seed` (`php artisan e2e:seed`, `control-plane/app/Console/EndToEnd/Fixtures.php`). A test that passes a two-factor challenge gets an administrator of its own: add a key to `Fixtures::ADMINISTRATORS` and to the `Seed` type. A test that changes a customer's two-factor gets a customer of its own, in `Fixtures::CUSTOMERS`; the seed turns two-factor off for every customer it makes. A test that needs several codes for one account takes each from `codeAfter()` in `support/totp`: the API accepts every time step only once.
- Type only credentials that `e2e:seed` made for the run, never a fixed one. The trace of a failed test records what was typed, and the CI artifact can be downloaded by anyone for 7 days (decision 0016, amended).
- Do not repeat tests with `--repeat-each`: the login throttle counts every attempt. Repeat `make e2e`.

## API client

The apps reach the API only through `@hostmesh/api-client` (decision 0008):

```ts
import { apiBaseUrl, createSessionClient } from '@hostmesh/api-client'

export const api = createSessionClient({
  baseUrl: apiBaseUrl(window.location.hostname), // admin.<domain> -> https://api.<domain>/api/v1
  csrfCookie: 'XSRF-TOKEN-ADMIN',
})
```

Each app has one client, in `src/api.ts`. `createSessionClient` sends the session cookie cross-origin and echoes the realm's CSRF cookie in `X-XSRF-TOKEN` on every state-changing request (decision 0010). The API host is derived from the page's hostname, so a build is not tied to a domain.

After any API change, regenerate the specification and then the client, and commit both:

```bash
docker compose -f infrastructure/development/compose.yaml exec php composer openapi
docker compose -f infrastructure/development/compose.yaml exec node pnpm generate:api
```

Never edit `packages/api-client/src/generated/` by hand. CI regenerates it and fails on any difference. `src/health.test-d.ts` holds the type-level tests: `pnpm typecheck` compiles them, and they fail when the client and the specification disagree. In tests, type mock responses from the generated `paths` (see `src/client.test.ts`), so that a contract change breaks the typecheck.

## How an app is put together

| Path in `apps/<name>/src/`           | Contents                                                                                                                                                                                      |
|--------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `api.ts`                             | The client, and `onUnauthenticated()` for a 401 on any call.                                                                                                                                  |
| `stores/auth.ts`                     | The session: `unknown`, `guest`, `challenge`, `enrollment` (admin only) or `authenticated`, the user with the `abilities` the API reports, and the auth calls.                                |
| `stores/branding.ts`                 | `GET /branding`, with built-in defaults that stay when the request fails.                                                                                                                     |
| `router.ts`                          | The routes and one guard. A route's `meta.requires` names the session state it is for; the guard sends every other state to its own entry route and keeps the page asked for in `?redirect=`. |
| `layouts/`, `components/AppLogo.vue` | `LoginLayout` and `AppLayout`, with the logo of the current color mode or the product name.                                                                                                   |
| `views/`                             | One file per screen, with its test next to it.                                                                                                                                                |
| `lib/`                               | `result.ts` (what an action returns), `form-errors.ts` (API errors onto vee-validate fields, focus on the first invalid one), `redirect.ts`.                                                  |
| `test/`                              | `mockApi()` (a table of replies in place of `fetch`), bodies typed from the generated schema, `mountView()` and `mountApp()`, and `submitted()` to wait for a submitted form.                 |

The admin app uses the product name and the logo of branding only. The portal also applies the brand colors as overrides of `--primary` and `--primary-foreground` on the root element (`lib/brand-colors.ts`) and sets the favicon (`lib/document-branding.ts`). The files the two apps have in common are copies; keep them in step when one changes.

A form is vee-validate with a Zod schema from `@hostmesh/ui`. The schema is input ergonomics: the API validates again, and its errors are put on their fields. A screen decides what to offer from the `abilities` in the API's response, never from a role.

## Rules

- Versions live in the `catalog` of `pnpm-workspace.yaml`; packages refer to them with `catalog:`. `pnpm-lock.yaml` is committed.
- The apps and `@hostmesh/ui` never call the network themselves. ESLint rejects the direct ways to do it there: `fetch` and `XMLHttpRequest` (also through `window`, `globalThis` and `self`), `navigator.sendBeacon`, and imports of `openapi-fetch` and other HTTP clients. It cannot catch every indirection, so review still applies.
- Tests come first (spec section 37). A test file sits next to the code it tests, as `*.test.ts`.
- Components come from `@hostmesh/ui`, and styling from its design tokens: use `bg-primary`, `text-muted-foreground` and the other token utilities, never a hard-coded color. Apps import only `@hostmesh/ui` and `@hostmesh/ui/styles.css`.
- A new shared component is added to `packages/ui` with the shadcn-vue CLI (see its README), not written into an app.
