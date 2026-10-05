# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- This changelog.
- Smoke test for the local development stack (`infrastructure/development/smoke.sh`). It checks PostgreSQL, Redis and the four hostnames over HTTPS and exits non-zero on failure.
- Local development stack in `infrastructure/development/`: PostgreSQL 18.6, Redis 8.10.2 and a Caddy 2.11.4 reverse proxy serving `app.`, `admin.`, `api.` and `agent.hostmesh.test` over HTTPS with certificates from Caddy's local CA. Each hostname serves a placeholder for now.
- `make up`, `make down` and `make reset` to start the stack, stop it, and stop it while wiping its volumes.
- `make hosts` and `make trust` (`hosts.sh`, `trust.sh`) to add the `hostmesh.test` names to `/etc/hosts` and to trust Caddy's local CA system-wide on Fedora/RHEL and Debian/Ubuntu.
- Setup and troubleshooting guide for the local development environment (`infrastructure/development/README.md`).
- Decision record 0003: development runtimes (PHP, Node) run in containers.
- CI workflow that starts the development stack and runs its smoke test on pull requests that touch `infrastructure/development/`.
- `php` service in the development stack: PHP-FPM 8.5.11 with the PostgreSQL, Redis, `intl`, `pcntl` and code-coverage extensions and Composer 2.10.3. It runs as the host user and mounts `control-plane/`.
- `hostmesh_test` database, created with the PostgreSQL volume, for the control plane's test suite.
- Control plane application in `control-plane/`: Laravel 13 on PHP 8.5, API-only (no frontend tooling, views or user model yet), tested with Pest 5.
- Control plane configuration through env: PostgreSQL as the only database connection, Redis (phpredis) for the queue and cache, with `control-plane/.env.example` matching the development stack. Tests run against a real PostgreSQL database (`hostmesh_test`) and real Redis.
- `composer check` in the control plane: Pint, Larastan at level 10 and Pest in parallel, also available as `composer lint`, `lint:fix`, `analyse` and `test`.
- Control plane layering (`Domain`, `Application`, `Infrastructure`, `Http/Api/V1/{Admin,Customer}`) with architecture rules: the domain layer depends on neither Http nor Infrastructure, Http does not use the database facade or query builder (Pest `arch()`), Http does not call Eloquent write methods (PHPStan), and no debugging helpers are left in the code.
- `GET /api/v1/health` in the control plane: a public liveness endpoint that returns `{"status":"ok"}` through an application-layer query. The API is served under `/api/v1`; the framework's `/up` page and the welcome page are removed.
- Coverage gate for the control plane: `composer test:coverage` fails below 85% overall, with service providers excluded as framework boilerplate. `composer check` now includes it.
- CI workflow `Control plane`: Pint, Larastan and Pest in parallel with the coverage gate, against PostgreSQL 18.6 and Redis 8.10.2 service containers, with a Composer cache. Runs on pull requests that touch `control-plane/`.
- `make install` for the development stack: installs the control plane's dependencies, creates its `.env` and key, and runs its migrations.
- `control-plane/README.md` with the layout, commands and testing rules, and a control plane section in the development environment guide.
- Decision record 0004: PHP 8.5 for the control plane.
- Decision record 0005: Python 3.13 and uv for the agent.
- Agent project in `agent/`: Python 3.13 managed by uv, with the package layout of spec section 26. Every service and top-level package other than the ones below is an empty stub.
- Agent tooling: ruff (lint and format, with shell-invoking APIs banned), mypy in strict mode, and pytest with pytest-asyncio, respx and Hypothesis. `make -C agent check` runs them all, also available as `install`, `lint`, `lint-fix`, `typecheck`, `test` and `test-coverage`.
- Coverage gate for the agent: `make -C agent test-coverage` fails below 85% branch coverage.
- `agent/README.md` with the layout, commands and requirements.
- Stub run loop for the agent (`agent.runner.run`), which idles until it is asked to stop.
- No-inbound-listener test for the agent: starting the run loop must leave the process without a listening TCP socket or a bound UDP socket, on IPv4 or IPv6. The detector behind it is tested against real loopback sockets.
- Agent service interfaces `Users` and `Filesystem` (`agent/services/users`, `agent/services/filesystem`): typed, synchronous Protocols whose methods never take a command.
- In-memory fakes `FakeUsers` and `FakeFilesystem`, sharing a mutation `Journal` that records every state-changing call and can inject a fault before any of them.
- Reusable contract test classes for both interfaces in `agent/tests/support/`, run against the fakes now and against the real implementations later.
- `ensure_user` in the agent (`agent/reconciliation/user.py`): the first idempotent operation, built on the `Users` and `Filesystem` interfaces. It creates or corrects a user and its home directory, reports the changes it made, refuses a home path that is not a directory, and rejects relative paths, `..` components, `/` as home and out-of-range modes.
- Tests for `ensure_user` covering create, no-op when converged, drift repair, apply twice and resume after an interruption at every step, with a Hypothesis property over arbitrary starting states and interruption points.
- `hostmesh-agent` command-line entry point with a `version` command.
- CI workflow `Agent`: ruff, mypy and pytest (unit and contract tests) with the coverage gate, on Python 3.13 with a uv dependency cache. Runs on pull requests that touch `agent/` or `contracts/agent-protocol/`.
- OpenAPI specification of the control plane's API in `contracts/api/openapi.json`, generated from the Laravel code by Scramble (`dedoc/scramble` 0.13). `composer openapi` in the control plane regenerates it. It is never edited by hand.
- Contract test for the OpenAPI specification: the control plane's test suite fails when `contracts/api/openapi.json` is missing or differs from the freshly generated document.
- The OpenAPI specification is identical on every machine: its server URL is the relative `/api/v1` instead of one built from `APP_URL`, its title and version are fixed in `control-plane/config/scramble.php`, and its paths are in alphabetical order whatever the order of the routes.
- Tests that the control plane serves no API documentation: Scramble's `/docs/api` page, its `/docs/api.json` route and its browser dev tools are switched off, so the package only generates the committed specification.
- The `Control plane` CI workflow regenerates the OpenAPI specification and fails on any difference from the committed `contracts/api/openapi.json`, including when the file is missing. The workflow also runs on pull requests that touch `contracts/api/`.
- "API contract" section in `control-plane/README.md`: how to regenerate the OpenAPI specification and how the test and CI enforce it.
- Decision record 0006: the OpenAPI specification is generated with Scramble, a runtime dependency with its documentation routes disabled.
- `node` service in the development stack: Node 24.21.0 with pnpm 12.8.1, for the web workspace. It runs as the host user and mounts `web/` and (read-only) `contracts/`. pnpm keeps its store in `web/node_modules/.pnpm-store`. Run `make -C infrastructure/development up` once to build it.
- `web/.nvmrc` and the `packageManager` field of `web/package.json`, which pin Node and pnpm for the web workspace.
- Decision record 0007: Node 24, pnpm and TypeScript 6 for the web workspace.
- Web workspace in `web/`: a pnpm workspace (`apps/*`, `packages/*`) with one catalog of dependency versions and a committed lockfile.
- Web tooling: TypeScript 6 in strict mode with `noUncheckedIndexedAccess`, ESLint 10 with a flat config, vue-tsc and Vitest 5. Each app typechecks its browser code without Node types, and its Vite configuration separately. `pnpm check` runs lint, typecheck, tests and builds, also available as `lint`, `lint:fix`, `typecheck`, `test` and `build`.
- Admin app skeleton in `web/apps/admin`: Vue 3, Vite 8, Tailwind 4, Pinia and Vue Router, with a placeholder page and a smoke test that mounts the root component. It builds to static output in `dist/`.
- `@hostmesh/ui` in `web/packages/ui`: an empty skeleton for the shared components. It is already a Vitest project, so the tests of its first components run without further setup.
- ESLint rules for the apps and `@hostmesh/ui` that reject the direct ways around the generated client: `fetch` and `XMLHttpRequest` (also through `window`, `globalThis` and `self`), `navigator.sendBeacon`, and imports of `openapi-fetch` and other HTTP clients.
- `web/README.md` with the layout, commands and rules, and the web commands in the development environment guide.
- Customer portal skeleton in `web/apps/portal`, with the same stack as the admin app, a placeholder page and a smoke test that mounts the root component. It builds to static output in `dist/`.
- `@hostmesh/api-client` in `web/packages/api-client`: TypeScript types generated from `contracts/api/openapi.json` by openapi-typescript, and `createApiClient`, a thin openapi-fetch client typed by them that requires the base URL. `pnpm generate:api` empties the generated directory and regenerates the types. The directory is never edited by hand.
- Type-level tests for the API client, compiled by `pnpm typecheck`: they fail when the client and the OpenAPI specification disagree, checked on the health endpoint, on a path the specification does not have and on a missing base URL. A runtime test types its mock response from the generated schema, so a contract change breaks the typecheck.
- Decision record 0008: the typed client is generated with openapi-typescript and openapi-fetch.
- Both apps depend on `@hostmesh/ui` and `@hostmesh/api-client` as workspace packages, with a test in each app that they resolve.
- CI workflow `Web`: ESLint, vue-tsc, Vitest and the builds of both apps, on the Node and pnpm versions the workspace pins, with a pnpm store cache. It regenerates the typed API client and fails on any difference from the committed files, including a committed file that the generator does not write. Runs on pull requests that touch `web/` or `contracts/api/`.
- Decision record 0009: shadcn-vue in the shared component package, design tokens, and component tests in a browser.
- Design tokens in `@hostmesh/ui`: semantic CSS variables for surfaces and their text (background, card, popover, primary, secondary, muted, accent, destructive), border, input, ring, radius and fonts, with dark mode under `.dark`, mapped into Tailwind v4. Apps load them with `@import "@hostmesh/ui/styles.css"`.
- Button, input and card components in `@hostmesh/ui`, written by the shadcn-vue CLI (style `reka-nova`) on Reka UI. `web/packages/ui/README.md` documents the tokens and how to add a component.
- Tests that render the components with a non-default token set and assert the computed colors, radius and fonts.
- Third-party notice for the MIT-licensed shadcn-vue code in `NOTICE` and `web/packages/ui/src/components/ui/LICENSE`.
- `useColorMode()` in `@hostmesh/ui`: applies dark mode from the system setting, follows it when it changes, and remembers an explicit choice of light or dark.
- Label, form field (label, description, error), separator and dialog components in `@hostmesh/ui`, with accessibility checks: the field's input is named by its label and described by its description, and the dialog is named by its title, traps focus, closes on Escape and returns focus to its trigger.
- Table and toast components in `@hostmesh/ui`. Toasts use Sonner: an app mounts `<Toaster />` once and calls `toast()`, both exported by the package.
- Data-table pattern in `@hostmesh/ui`: `DataTable` renders typed column definitions with TanStack Table v9 and shows an empty state, with `createDataTableColumnHelper()` for the columns.
- Form pattern in `@hostmesh/ui`: `FormField` binds a vee-validate field to its label, description and errors with the accessibility attributes, and `toTypedSchema()` lets vee-validate 4 validate with a Zod 4 schema.
- `HOSTMESH_DOMAIN`: the one hostname setting of the control plane. The API URL, the two SPA origins, the CORS origins, the cookie domain and the Content Security Policy derive from it (`config/hostmesh.php`, `App\Support\Hostnames`). A malformed value stops the application at boot.
- Content Security Policy definitions for the portal and the admin app in `config/hostmesh.php`, rendered by `App\Support\ContentSecurityPolicy`: nothing inline, `connect-src` limited to the API origin.
- CORS for the API (`config/cors.php`): exactly the portal and admin origins are allowed, with credentials. Every other origin, including the API host itself, look-alike domains, other ports and plain HTTP, gets no CORS headers.
- Example Nginx configuration for the four hostnames (`infrastructure/nginx/hostmesh.conf.example`) with the strict Content Security Policy on both static apps, and a README that documents the agent hostname as never behind a CDN. A test keeps the policy in the example in step with `config/hostmesh.php`.
- Identities in the control plane: `Administrator` (table `administrators`) and `User` (table `users`, portal logins) in `app/Domain/Identity`, with UUIDv7 keys, bcrypt-hashed passwords and emails stored in lower case. Each has its own session guard (`admin`, `customer`) and auth provider, so an account of one side can never authenticate on the other.
- Laravel Sanctum 4.3 for cookie-based SPA authentication.
- Session realms (`realm:admin`, `realm:customer`, `App\Http\Middleware\UseSessionRealm`): each route group accepts only its own SPA origin and has its own guard, session cookie (`__Host-hostmesh-admin-session`, `__Host-hostmesh-portal-session`), session table and idle lifetime (30 and 120 minutes, `HOSTMESH_ADMIN_SESSION_LIFETIME` and `HOSTMESH_PORTAL_SESSION_LIFETIME`). Session cookies are host-only, `Secure`, `HttpOnly` and `SameSite=Lax`.
- `GET /api/v1/admin/auth/csrf-cookie` and `GET /api/v1/customer/auth/csrf-cookie`: start the CSRF cookie flow. Each app has its own CSRF cookie (`XSRF-TOKEN-ADMIN`, `XSRF-TOKEN-PORTAL`) and echoes it in `X-XSRF-TOKEN`.

---

### Changed

- `api.hostmesh.test` now serves the control plane through PHP-FPM instead of a placeholder, and the smoke test checks `https://api.hostmesh.test/api/v1/health`.
- The development-environment CI workflow installs the control plane before running the smoke test.
- The agent's coverage gate no longer counts its in-memory fakes and the mutation journal, so it measures production code only.
- The `php` service of the development stack also mounts `contracts/` (at `/var/www/contracts`), so the control plane can read and write the committed API contract. Run `make -C infrastructure/development up` once to recreate the container.
- `GET /api/v1/health` is documented in the OpenAPI specification: a summary and description, public access, and a `200` response whose `status` is the `HealthStatus` enum (`ok`). The response body is unchanged.
- `.editorconfig` exempts the generated `contracts/api/openapi.json` from the JSON indent and final-newline rules, so saving it in an editor cannot make it differ from the generator's output.
- The smoke test of the development stack also checks that the `node` service runs the Node and pnpm versions the web workspace pins.
- The development-environment CI workflow also runs on pull requests that change `web/.nvmrc` or `web/package.json`.
- `make install` for the development stack also installs the web workspace's dependencies from its lockfile.
- `make up` for the development stack builds the images whose Dockerfile changed before it starts the stack. It used to keep an existing image, so a changed PHP or Node version was not applied.
- `.editorconfig` exempts the generated API client types from the TypeScript indent rule, so saving the file in an editor cannot make it differ from the generator's output.
- The `node` service of the development stack holds Chromium's headless shell (Playwright 1.63.0) for browser-mode component tests, and gets 1 GB of shared memory. Run `make -C infrastructure/development up` once to rebuild the image.
- The smoke test of the development stack also checks that the `node` service has the Chromium build of the Playwright version pinned in the web workspace's catalog.
- The development-environment CI workflow also runs on pull requests that change `web/pnpm-workspace.yaml`.
- The tests of `@hostmesh/ui` run in headless Chromium (Vitest browser mode with Playwright) instead of jsdom, with Tailwind compiled, so they can assert computed styles.
- CI workflow `Web` installs Chromium before running Vitest.
- ESLint checks the files the shadcn-vue CLI writes for defects only: the Vue style rules are off under `web/packages/ui/src/components/ui/`.
- The admin app and the customer portal load the design tokens of `@hostmesh/ui`, mount its toast host and follow the system's light or dark setting.
- `web/README.md` describes `@hostmesh/ui`, the browser-mode tests and the rule that apps style only through the design tokens.
- `APP_URL` is no longer read. `control-plane/.env.example` carries `HOSTMESH_DOMAIN` instead; add it to an existing `.env`.
- Session configuration is fixed in `config/session.php`: database driver, `Secure`, `HttpOnly`, `SameSite=Lax`, host-only cookies. The `SESSION_*` env keys are no longer read.

---

### Fixed

- `UserSpec` in the agent now rejects paths that start with `//` (the same directory as `/` on Linux, which slipped past the root check), `/` as a shell, `:` and control characters in the name, home and shell, an empty name, and a name starting with `-`.
- `ensure_user` now refuses a home whose parent is missing or is not a directory before changing anything. It used to create the user first and fail afterwards, leaving the account behind on every run.
