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

## Hostnames and authentication

`HOSTMESH_DOMAIN` is the only hostname setting. `app.`, `admin.`, `api.` and `agent.` under it are derived by `App\Support\Hostnames`, and `HostnameServiceProvider` writes the API URL, the CORS origins and the cookie domain. Never add another hostname setting or literal to `config/`: `tests/Feature/Platform/HostnamesTest.php` fails if you do.

The two Vue apps authenticate with Sanctum's cookie-based SPA flow, in two isolated realms ([decision 0010](../docs/decisions/0010-session-realms-on-sanctum.md)):

|                   | Admin                               | Portal                               |
|-------------------|-------------------------------------|--------------------------------------|
| Route group       | `/api/v1/admin`, `realm:admin`      | `/api/v1/customer`, `realm:customer` |
| Allowed origin    | `https://admin.<domain>`            | `https://app.<domain>`               |
| Guard and model   | `admin`, `Administrator`            | `customer`, `User`                   |
| Session cookie    | `__Host-hostmesh-admin-session`     | `__Host-hostmesh-portal-session`     |
| CSRF cookie       | `XSRF-TOKEN-ADMIN`                  | `XSRF-TOKEN-PORTAL`                  |
| Idle lifetime     | 30 minutes                          | 120 minutes                          |
| Two-factor        | mandatory                           | optional                             |

A client calls `GET …/auth/csrf-cookie`, then sends the CSRF cookie's value in `X-XSRF-TOKEN` with every state-changing request, with credentials included. Each group offers `auth/login`, `auth/logout` and `auth/user`.

Two-factor authentication is TOTP ([decision 0011](../docs/decisions/0011-totp-two-factor-on-google2fa.md)). A password login returns `meta.second_factor`: `challenge` (send a code or a recovery code to `auth/two-factor/challenge`), `enrollment` (call `auth/two-factor`, show the `provisioning_uri` as a QR code, then `auth/two-factor/confirm`) or `none`. Until that step is done, every other route answers 403 with `code: two_factor_required` or `two_factor_enrollment_required`. `DELETE auth/two-factor` and `auth/two-factor/recovery-codes` need the current password and a valid code. Secrets are encrypted at rest, recovery codes are stored hashed, and a code is accepted once.

Rules for new routes:

- Put a route inside the group of the realm it belongs to. Protect it with `auth:admin` or `auth:customer`, never `auth:sanctum` and never the default guard.
- A route outside both groups has no session and no user.
- Tests drive the API through `Tests\Support\Browser`, which keeps a cookie jar and sends `Origin` and `X-XSRF-TOKEN` like a browser. CSRF verification is active in tests.
- Put it inside the group's `two-factor` block. `tests/Feature/Auth/SecondFactorGateTest.php` reads the route list and fails for an admin route outside it; a route that must work before the second factor is added to that test's list on purpose.

## Authorization

Authorization is decided on the server, in policies, and nowhere else ([decision 0012](../docs/decisions/0012-rbac-approach.md)).

- **Permissions** are the cases of `App\Domain\Authorization\Permission`, named `resource.action`. A permission that ends in `.view` changes nothing.
- **Roles** are the cases of `App\Domain\Authorization\Role`: `owner` holds every permission, `read-only` holds every `.view` permission. Both enums are code. There is nothing to seed and nothing to cache.
- **Role assignment** is data: the table `administrator_roles` (`administrator_id`, `role`). An administrator may hold several roles, and one without a role may only view their own account and manage their own second factor.
- **Policies** live next to their model (`app/Domain/<Context>/Policies`) and are registered in `AppServiceProvider`. A method takes `Administrator|User` and branches on the type: an administrator is checked with `hasPermission()`, a customer's user against ownership. Controllers ask the Gate and never read roles or permissions; `tests/Arch/ResourcesTest.php` fails if anything in `Http` does.
- Customers have no roles.

Rules for a new resource:

- Write its policy, register it, and add its table of who may do what to `tests/Feature/Authorization/PolicyTest.php`. Add a permission for each action an administrator can be allowed, in the same pull request.
- Every route behind `two-factor` asks the Gate: `Gate::authorize()` in the controller, or `authorize()` of the form request, which runs before validation. `tests/Feature/Authorization/RouteAuthorizationTest.php` reads the route list and fails for a route that does not.
- Its API Resource uses `App\Http\Api\V1\Concerns\ReportsAbilities` and returns `abilities`: one boolean for each method of the policy that takes a record, under the method's name in snake case (`manageTwoFactor` becomes `manage_two_factor`), each from `$this->allows($request, '<method>')`. Write the keys out, so that the OpenAPI document and the typed client list them.
- Add the resource to `tests/Feature/Api/V1/AbilitiesTest.php`, which checks that every reported ability equals the policy's decision and that the endpoint behind it answers 403 exactly when the ability is false.

`abilities` describes the policy only. Until a login has completed its second factor, every route still answers 403 with a `code`, whatever the abilities in the login response say.

## Testing

- Pest only, written test-first.
- Tests run against real PostgreSQL (database `hostmesh_test`) and real Redis (database 15). There is no SQLite connection.
- `tests/Arch` holds the architecture rules. Eloquent write methods in `app/Http` are reported by PHPStan (`phpstan/http-no-database-writes.neon`), because `arch()` only sees class-level dependencies.
- Coverage counts `app/` without `app/Providers`.

## Requirements

PHP 8.5 with the extensions `pdo_pgsql`, `redis`, `intl`, `pcntl` and PCOV (or Xdebug) for coverage. See [decision 0004](../docs/decisions/0004-php-8-5-for-the-control-plane.md).
