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

### Changed

- `api.hostmesh.test` now serves the control plane through PHP-FPM instead of a placeholder, and the smoke test checks `https://api.hostmesh.test/api/v1/health`.
- The development-environment CI workflow installs the control plane before running the smoke test.
