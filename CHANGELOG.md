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
- Web tooling: TypeScript 6 in strict mode with `noUncheckedIndexedAccess`, ESLint 10 with a flat config, vue-tsc and Vitest 5. `pnpm check` runs lint, typecheck, tests and builds, also available as `lint`, `lint:fix`, `typecheck`, `test` and `build`.
- Admin app skeleton in `web/apps/admin`: Vue 3, Vite 8, Tailwind 4, Pinia and Vue Router, with a placeholder page and a smoke test that mounts the root component. It builds to static output in `dist/`.
- `@hostmesh/ui` in `web/packages/ui`: an empty skeleton for the shared components.
- ESLint rule for the apps and `@hostmesh/ui`: `fetch`, `XMLHttpRequest` and a direct `openapi-fetch` import are errors, so the API is reached only through the generated client.
- `web/README.md` with the layout, commands and rules, and the web commands in the development environment guide.
- Customer portal skeleton in `web/apps/portal`, with the same stack as the admin app, a placeholder page and a smoke test that mounts the root component. It builds to static output in `dist/`.
- `@hostmesh/api-client` in `web/packages/api-client`: TypeScript types generated from `contracts/api/openapi.json` by openapi-typescript, and `createApiClient`, a thin openapi-fetch client typed by them. `pnpm generate:api` regenerates the types. The generated directory is never edited by hand.
- Type-level test for the API client: it fails when the client and the OpenAPI specification disagree, checked on the health endpoint and on a path the specification does not have. A runtime test types its mock response from the generated schema, so a contract change breaks the typecheck.
- Decision record 0008: the typed client is generated with openapi-typescript and openapi-fetch.
- Both apps depend on `@hostmesh/ui` and `@hostmesh/api-client` as workspace packages, with a test in each app that they resolve.
- CI workflow `Web`: ESLint, vue-tsc, Vitest and the builds of both apps, on the Node and pnpm versions the workspace pins, with a pnpm store cache. It regenerates the typed API client and fails on any difference from the committed files. Runs on pull requests that touch `web/` or `contracts/api/`.

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

---

### Fixed

- `UserSpec` in the agent now rejects paths that start with `//` (the same directory as `/` on Linux, which slipped past the root check), `/` as a shell, `:` and control characters in the name, home and shell, an empty name, and a name starting with `-`.
- `ensure_user` now refuses a home whose parent is missing or is not a directory before changing anything. It used to create the user first and fail afterwards, leaving the account behind on every run.
