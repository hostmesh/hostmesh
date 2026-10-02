# Hostmesh control plane

The Laravel 13 application that stores desired state and serves the versioned REST API (`/api/v1`). It serves no UI: the admin app and the customer portal in `web/` are ordinary API clients. See `docs/spec/HostMesh_v0.1.md`, sections 25, 28, 35 and 37.

## Layout

| Path                  | Contents                                                                                             |
|-----------------------|------------------------------------------------------------------------------------------------------|
| `app/Domain/`         | Business rules, one folder per bounded context. Depends on neither `Http` nor `Infrastructure`.      |
| `app/Application/`    | `Commands`, `Queries` and `Services`: the single entry point for all behavior.                       |
| `app/Infrastructure/` | Persistence, payment providers, node agents, notifications.                                          |
| `app/Http/Api/V1/`    | Thin controllers, with `Admin/` and `Customer/` endpoints. No business logic and no database access. |

## Commands

The runtime is the `php` service of the development stack (`infrastructure/development/README.md`). From the repository root:

```bash
make -C infrastructure/development up install
docker compose -f infrastructure/development/compose.yaml exec php composer check
```

| Composer script     | What it does                                                     |
|---------------------|------------------------------------------------------------------|
| `check`             | `lint`, `analyse` and `test:coverage`. Run it before every push. |
| `lint` / `lint:fix` | Pint: check or fix code style.                                   |
| `analyse`           | Larastan (PHPStan) at level 10.                                  |
| `test`              | Pest in parallel.                                                |
| `test:coverage`     | Pest in parallel with code coverage; fails below 85%.            |
| `openapi`           | Regenerates `contracts/api/openapi.json` from the code.          |

One test: `docker compose -f infrastructure/development/compose.yaml exec php vendor/bin/pest --filter=<name>`.

## API contract

`contracts/api/openapi.json` is the OpenAPI 3.1 document of the API. [Scramble](https://scramble.dedoc.co) generates it from the routes, controllers and enums, and it is committed. Never edit it by hand.

```bash
docker compose -f infrastructure/development/compose.yaml exec php composer openapi
```

Run it after any change to a route, a request, a response or an API enum, and commit the result in the same pull request. `tests/Feature/Contracts/OpenApiSpecTest.php` fails when the committed file is missing or differs from the generated one, and CI regenerates it and fails on any diff.

The document is the same on every machine: the server URL is the relative `/api/v1`, the title and version are fixed in `config/scramble.php`, and paths are sorted. Scramble's documentation pages are switched off, because the control plane serves no UI. See [decision 0006](../docs/decisions/0006-openapi-generated-with-scramble.md).

## Testing

- Pest only, written test-first.
- Tests run against real PostgreSQL (database `hostmesh_test`) and real Redis (database 15). There is no SQLite connection.
- `tests/Arch` holds the architecture rules. Eloquent write methods in `app/Http` are reported by PHPStan (`phpstan/http-no-database-writes.neon`), because `arch()` only sees class-level dependencies.
- Coverage counts `app/` without `app/Providers`.

## Requirements

PHP 8.5 with the extensions `pdo_pgsql`, `redis`, `intl`, `pcntl` and PCOV (or Xdebug) for coverage. See [decision 0004](../docs/decisions/0004-php-8-5-for-the-control-plane.md).
