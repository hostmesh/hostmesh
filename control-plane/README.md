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

## Audit log

Security-relevant actions are written to `audit_events` ([decision 0013](../docs/decisions/0013-audit-logging-approach.md)). It is a plain table: it gives no tamper evidence against someone who controls the database.

- **Append-only.** Triggers refuse `UPDATE`, `DELETE` and `TRUNCATE`. Two exceptions are built in: `ip_address` and `user_agent` can be set to null, and an event older than 12 months can be deleted.
- **What an event holds:** `actor_type` (`administrator`, `user`, `node`, `system`) and `actor_id`, `action`, `resource` and `resource_id`, `metadata`, `ip_address`, `user_agent`, `request_id` and `created_at`. Identifiers only: never a name, an email address, a password, a secret or a recovery code. A failed login for an unknown address stores a keyed hash of the address in `metadata.email_hash`.
- **Request ID.** Every response from an API route carries `X-Request-Id`. A request that matches no route (404, 405) has none. The same value is stored with the events of that request.
- **Reading.** `GET /api/v1/admin/audit-events`, with the permission `audit-event.view`. Names are looked up when the log is read and are null for an account that is gone.

Rules for a new audited action:

- Add a case to `App\Domain\Audit\AuditAction`. A value is stored forever: never rename one.
- Add an event in `app/Application/Events` that implements `Audited` and builds its `AuditEvent` from identifiers.
- Dispatch it from the application command, inside the transaction that makes the change. `RecordAuditEvent` writes it at once, and its exception must roll the change back. Never dispatch from a controller, and never queue the listener: `tests/Arch/AuditTest.php` fails for both.
- Test three things: the stored event, that it holds no personal data or secret (`Tests\Support\AuditTrail::assertHoldsNone()`), and that the action does not happen when the log cannot be written (`$this->app->instance(AuditLog::class, InMemoryAuditLog::failing())`).
- Do not use `Event::fake()` without arguments in a test of an audited action: it silences the audit log.

## Jobs

Desired state is authoritative; a job is a one-shot action that is not state (spec section 6). Jobs run on the Redis queue, and each one leaves a row in the PostgreSQL table `job_history` ([decision 0014](../docs/decisions/0014-job-history-and-retry-policy.md)).

- **The row is the authority.** It holds `type`, `resource_type` and `resource_id`, `generation`, `node_id`, `status` (`pending`, `running`, `waiting`, `retrying`, `succeeded`, `failed`), `failure_class`, the last `message`, `attempts`, and `started_at` and `finished_at`. Redis only carries the work.
- **Failure classes** (spec section 8). A job throws `App\Application\Exceptions\JobFailure`:

  | Thrown                      | What happens                                                                                              |
  |-----------------------------|-----------------------------------------------------------------------------------------------------------|
  | `JobFailure::retryable()`   | `attempts` + 1, then a retry after 10, 20, 40 … seconds, at most 10 minutes. `failed` after 8 attempts    |
  | `JobFailure::waiting()`     | `waiting`, looked at again after 2 minutes or after the seconds the job names. Never counts as an attempt |
  | `JobFailure::terminal()`    | `failed` at once                                                                                          |
  | anything else               | reported to the exception handler and treated as retryable. The row stores the class name only            |

  A delay is shortened by a random share of up to half. A run that was interrupted (worker killed, timeout) counts as one attempt. A job that is delivered a second time while its first run is inside the timeout waits for the timeout and counts nothing. The numbers are defaults: see `jobs` in `config/hostmesh.php`.
- **A row with no job behind it.** If Redis loses a push or its data, the row stays `pending`, `running`, `waiting` or `retrying`. It shows, and nothing repairs it yet.
- **A failed job** is also stored in `failed_jobs`. Pushing it again does nothing, because its row is finished. A new attempt is a new job and a new row.
- **Redis is never used for correctness.** No lock, unique job, overlap guard or cache-backed counter: `tests/Arch/RedisTest.php` reads the sources and fails for one.

Rules for a new one-shot job:

- Add a case to `App\Domain\Provisioning\JobType`. A value is stored: never rename one.
- Extend `App\Application\Jobs\OneShotJob`. Implement `type()` and `perform()`, and nothing else: the base class owns `handle()`, the tries and the outcome.
- Make `perform()` safe to run twice. Redis delivers at least once.
- Keep one run under the job's `timeout` (60 seconds), which must stay below `retry_after` of the queue connection (90 seconds).
- Never put a secret in a `JobFailure` message. It is stored and shown to administrators. Classify the failures you expect: of any other exception the row keeps the class name only.
- Dispatch it through `App\Application\Commands\DispatchOneShotJob`, inside the transaction that asks for it. Never call `dispatch()` on the job yourself: it would run without a row.
- Test it on the real Redis queue with `Tests\Support\QueueRunner`, and move time with `$this->travel()`.

## Testing

- Pest only, written test-first.
- Tests run against real PostgreSQL (database `hostmesh_test`) and real Redis (database 15). There is no SQLite connection.
- Jobs run at once under test (the `sync` connection). A test of retries or delays pushes to the real Redis queue through `Tests\Support\QueueRunner`, which gives every test a queue of its own.
- `tests/Arch` holds the architecture rules. Eloquent write methods in `app/Http` are reported by PHPStan (`phpstan/http-no-database-writes.neon`), because `arch()` only sees class-level dependencies.
- Coverage counts `app/` without `app/Providers`.

## Requirements

PHP 8.5 with the extensions `pdo_pgsql`, `redis`, `intl`, `pcntl` and PCOV (or Xdebug) for coverage. See [decision 0004](../docs/decisions/0004-php-8-5-for-the-control-plane.md).
