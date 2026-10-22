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
| `test:coverage`     | Pest in parallel with code coverage; fails below 95%.            |
| `openapi`           | Regenerates `contracts/api/openapi.json` from the code.          |

One test: `docker compose -f infrastructure/development/compose.yaml exec php vendor/bin/pest --filter=<name>`.

## Dependencies held back

`composer.json` has no comments, so the reasons are here, as a table of each `conflict` entry, why it is there and when it can go. Remove an entry when its reason is gone.

None at the moment.

## API contract

`contracts/api/openapi.json` is the OpenAPI 3.1 document of the API. [Scramble](https://scramble.dedoc.co) generates it from the routes, controllers and enums, and it is committed. Never edit it by hand.

```bash
docker compose -f infrastructure/development/compose.yaml exec php composer openapi
```

Run it after any change to a route, a request, a response or an API enum, and commit the result in the same pull request. `tests/Feature/Contracts/OpenApiSpecTest.php` fails when the committed file is missing or differs from the generated one, and CI regenerates it and fails on any diff.

The document is the same on every machine: the server URL is the relative `/api/v1`, the title and version are fixed in `config/scramble.php`, paths, shared responses and tags are sorted, and no path has a server of its own. Scramble's documentation pages are switched off, because the control plane serves no UI. See [decision 0006](../docs/decisions/0006-openapi-generated-with-scramble.md).

### Operation IDs

The operation ID of an endpoint is its route name, and every route under `/api/v1` has one. The typed client's operation names come from these IDs, so they are part of the contract.

- A route in the admin realm starts with `admin.`, one in the customer realm with `customer.`. The route groups in `routes/api.php` add the prefix. A public route has no prefix: `health`, `branding.show`.
- After the prefix: the resource in the plural, as in the path, then the action: `admin.audit-events.index`, `admin.branding.images.store`. For a resource, use Laravel's action names (`index`, `show`, `store`, `update`, `destroy`); for anything else, the verb (`admin.auth.login`, `admin.auth.two-factor.confirm`).
- Never rename a route to tidy up. A new name is a breaking change for every client.

A route without a name would get an ID that Scramble derives from the controller's class, and that ID changes when a second controller with the same class name appears elsewhere. `OpenApiSpecTest.php` fails for a route without a name and for an operation whose ID is not its route name.

### When generation fails

`composer openapi` writes nothing and exits with an error when Scramble could not describe part of the API. On its own, Scramble would export what it has: a request without its body, or a field typed as a string that is not one. The message lists every problem with its code and place:

```
The OpenAPI document was not written: Scramble reported 2 problems.
- [VR001] StoreWebsiteRequest::rules() call failed (Source at app/Http/..., Message Call to a member function on null)
- [SE001] Schema `UnknownType` is not allowed (Located at /components/schemas/WebsiteResource/type/properties/settings)
```

- **`VR001`, `VR002`: the rules of a `FormRequest` could not be read.** Scramble calls `rules()` without a request, so there is no user, no route parameter and no input. Keep `rules()` free of them: put what depends on the request in `authorize()`, in `after()` or in a rule object, or guard it (`$this->user()?->id`). The rules Scramble reads are the documentation of the request body.
- **`SE001`, `UnknownType`: a type could not be inferred.** The message names the property. The usual causes are a cast (`(object) $map`), a value typed `mixed`, and a method without a return type. Say what it is where it is returned, with a PHPDoc on the array item:

  ```php
  /** @var array<string, bool|int|string|null> */
  'metadata' => (object) $event->metadata,
  ```

  or give the method that produces the value a return type.
- **`MD001`: the table of a model could not be read.** Scramble reads column types from the database. Run the migrations on the database the command uses (`php artisan migrate`); CI does so before it regenerates the document.
- **An exception with another class** comes from Scramble or from the code it analysed, and is not caught: read the trace.

Fix the cause. Do not work around it by removing the route from the document. The settings are in `AppServiceProvider` (`Scramble::throwOnError()`, the `UnknownType` rule and the check of Scramble's diagnostics), not options of the command, so the contract test and CI fail in the same way.

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
- **Indexes:** on actor, on resource, on `created_at`, and on `created_at` of the events that still hold an address (for the clearing job).
- **Retention.** Events are deleted after 24 months and `ip_address` and `user_agent` cleared after 90 days, by two daily jobs ("Scheduled jobs" under "Jobs"). Every batch that changed anything records a `system` event on the resource `audit_log`, `audit_log.events_deleted` or `audit_log.addresses_cleared`, with `count` and `cutoff` in `metadata`, in the batch's own transaction: the log explains its own gaps.
- **What an event holds:** `actor_type` (`administrator`, `user`, `node`, `system`) and `actor_id`, `action`, `resource` and `resource_id`, `metadata`, `ip_address`, `user_agent`, `request_id` and `created_at`. Identifiers only: never a name, an email address, a password, a secret or a recovery code. A failed login for an unknown address stores a keyed hash of the address in `metadata.email_hash`.
- **Request ID.** Every response from an API route carries `X-Request-Id`. A request that matches no route (404, 405) has none. The same value is stored with the events of that request. CORS exposes the header, so the two apps can read it too.
- **Reading.** `GET /api/v1/admin/audit-events`, with the permission `audit-event.view`. Names are looked up when the log is read and are null for an account that is gone.

Rules for a new audited action:

- Add a case to `App\Domain\Audit\AuditAction`. A value is stored forever: never rename one.
- Add an event in `app/Application/Events` that implements `Audited` and builds its `AuditEvent` from identifiers.
- Dispatch it from the application command, inside the transaction that makes the change. `RecordAuditEvent` writes it at once, and its exception must roll the change back. Never dispatch from a controller, and never queue the listener: `tests/Arch/AuditTest.php` fails for both.
- Test three things: the stored event, that it holds no personal data or secret (`Tests\Support\AuditTrail::assertHoldsNone()`), and that the action does not happen when the log cannot be written (`$this->app->instance(AuditLog::class, InMemoryAuditLog::failing())`).
- Do not use `Event::fake()` without arguments in a test of an audited action: it silences the audit log.

## Branding

One record holds the product name, the logos, the favicon, the brand colors and the links (spec section 28). It is a single row of the table `branding`, created by the first change. Until then every reader gets the defaults.

- **Reading.** `App\Application\Queries\GetBranding` returns the read model `Branding`. Emails and invoices read it there, not from the table.
- **Public endpoint.** `GET /api/v1/branding` has no session and sets no cookie. It carries `Cache-Control: public, max-age=300` and an `ETag`, and answers `304` to a matching `If-None-Match`. It and the image endpoint share the `branding` rate limit (see [Rate limits](#rate-limits)).
- **Admin endpoints.** `GET` and `PUT /api/v1/admin/branding`, with the permissions `branding.view` and `branding.update`. `PUT` replaces the name, colors and links: send every field.
- **Colors** are `#rrggbb`: `primary` and `primary_foreground`, for light and dark. Null keeps the token default. A pair below WCAG AA (4.5:1) is saved and listed in `warnings`: the foreground on the primary, and the primary on the default `background` and `card`. The default surfaces are in `App\Domain\Branding\ColorMode` and mirror `web/packages/ui/src/styles/tokens.css`: change both together.
- **Links** are `https` URLs: `support`, `terms`, `privacy`.
- **Images.** `POST` and `DELETE /api/v1/admin/branding/images/{slot}` with slot `logo-light`, `logo-dark` or `favicon`; the file goes in the multipart field `file`. A logo is PNG, JPEG or WebP, at most 1024 KB and 2000×2000. The favicon is a square PNG, at most 256 KB and 512×512. SVG is refused until there is a sanitization decision.
- **How an upload is checked.** `finfo` names the type of the bytes and `getimagesize()` measures them; both must agree. The client's file name and content type are never read. This is a header check, not a full decode: a stored file is harmless because the server names it (`<slot>/<start of its SHA-256>.<extension of the detected type>`) and serves it as the detected type with `X-Content-Type-Options: nosniff`.
- **Storage.** The filesystem disk `branding` (`storage/app/branding`, see `config/filesystems.php`). It is not public: `GET /api/v1/branding/images/{slot}` serves the files. The URL names the slot and carries the version as `v`, so a URL from a cached response keeps working after a replacement. Back the directory up with the database.
- **Audit.** `branding.updated` (metadata `fields`: the names of the changed columns), `branding.image_replaced` and `branding.image_removed` (metadata `slot`). Values are never recorded. A change that changes nothing writes no event.

## Rate limits

Named limiters in `AppServiceProvider`, applied with `throttle:<name>` in `routes/api.php`. Past a limit the API answers `429` with `{"message": "Too Many Attempts."}` and a `Retry-After` header in seconds. The OpenAPI document shows both on every route behind `throttle`, through the Scramble extension `App\Infrastructure\OpenApi\DocumentRateLimits`. A controller that documents its own `429`, such as the branding ones that name their limit, keeps its description.

| Limiter      | Routes                                                                            | Limit                                                                                   |
|--------------|-----------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------|
| `login`      | `POST …/auth/login` of both realms                                                | 5 a minute per email and address, 20 a minute per address; each realm counts on its own |
| `two-factor` | the challenge, `confirm`, `recovery-codes` and `disable`                          | 5 a minute per account, 20 a minute per address; each realm counts on its own           |
| `branding`   | `GET /api/v1/branding` and `GET /api/v1/branding/images/{slot}`, counted together | 300 a minute per address                                                                |

The `branding` limit is generous on purpose. A login page loads the branding once and an image or two, and browsers cache both. 300 a minute is about 100 uncached login pages a minute from one address, for the many people who can share one: an office, a campus, carrier-grade NAT.

The address is the client's ([decision 0018](../docs/decisions/0018-trusted-proxies.md)). By default it is the address that connected to the web server. Behind a CDN or a reverse proxy, that is the proxy's address, and everyone behind it would share one budget. Name the proxies in `HOSTMESH_TRUSTED_PROXIES`:

    HOSTMESH_TRUSTED_PROXIES=192.0.2.0/24,198.51.100.10,2001:db8::/32

- **Format:** IPv4 and IPv6 addresses and CIDR ranges, separated by commas. For a CDN, its published ranges, for example https://www.cloudflare.com/ips/. Keep the list current when the CDN changes it.
- **What it changes:** for a request from one of them, the client address is read from `X-Forwarded-For`, from the right, past every trusted hop. A request from any other address keeps the connecting address, whatever headers it sends.
- **Only that header:** `Forwarded`, `X-Forwarded-Host`, `-Proto`, `-Port` and `-Prefix` are never read.
- **Refused at boot:** a malformed entry, or one that trusts everyone (`*`, `REMOTE_ADDR`, `0.0.0.0/0`), stops the application.

The audit log records the same address.

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

### Scheduled jobs

Scheduled tasks are defined in `withSchedule` in `bootstrap/app.php`. A task dispatches a one-shot job through `DispatchOneShotJob` and does no work itself, so every run leaves its row in `job_history`.

- **Production:** run the scheduler once per installation, either `php artisan schedule:run` from cron every minute (`* * * * * cd /path/to/control-plane && php artisan schedule:run >> /dev/null 2>&1`) or `php artisan schedule:work` as a supervised process. Development runs it in the `scheduler` service (`infrastructure/development/README.md`).
- **No overlap guard and no single-server lock:** both are cache locks (spec section 24). A scheduled job must be safe to run twice, as every job must be.
- **A job that acts on no single resource** names what it acts on with a stable `resource_type` and a fixed UUID, kept as constants next to the domain concept (decision 0014, amendment of 2026-10-22).

| Job                      | When             | What it does                                                                                                                                               |
|--------------------------|------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `prune_audit_events`     | daily, 03:00 UTC | Deletes audit events older than `HOSTMESH_AUDIT_EVENT_RETENTION_MONTHS` (default 24). Fewer than 12 count as 12, the floor the database trigger enforces |
| `clear_audit_addresses`  | daily, 03:00 UTC | Clears `ip_address` and `user_agent` of audit events older than `HOSTMESH_AUDIT_ADDRESS_RETENTION_DAYS` (default 90, at least 1)                         |

Both read `audit.retention` in `config/hostmesh.php` and cut off at PostgreSQL's `now()`, taken once at the start of a run, the clock the trigger checks. The periods are engineering defaults (decision 0013): a data-protection adviser should confirm them before the beta gate.

They work in batches, oldest first: each batch is one statement and its own transaction, of at most `HOSTMESH_AUDIT_RETENTION_BATCH_SIZE` events (default 10,000). A run starts no new batch after `HOSTMESH_AUDIT_RETENTION_RUN_SECONDS` (default 40, well below a job's `timeout` of 60) and then dispatches a new run, with a row of its own, for the rest. A long backlog therefore shows as a chain of `succeeded` rows. Each batch that changed anything also writes one audit event in its transaction; when that event cannot be written, the batch is rolled back and the job fails as retryable. Measured on 4 million events (issue #238), a run deletes about 6 million events or clears about 500,000. Clearing finds its events through the partial index `audit_events_addresses_created_at_index`; keep the condition in `AuditRetention` the same as the index's.

## Testing

- Pest only, written test-first.
- Tests run against real PostgreSQL (database `hostmesh_test`) and real Redis (database 15). There is no SQLite connection.
- Jobs run at once under test (the `sync` connection). A test of retries or delays pushes to the real Redis queue through `Tests\Support\QueueRunner`, which gives every test a queue of its own.
- `tests/Arch` holds the architecture rules. Eloquent write methods in `app/Http` are reported by PHPStan (`phpstan/http-no-database-writes.neon`), because `arch()` only sees class-level dependencies.
- Coverage counts `app/` without `app/Providers`.

## Creating an administrator

`php artisan administrator:create <email> --name=<name> [--role=owner|read-only]` creates an administrator (spec section 28). It is how an installation gets its first one, and until there are screens for it, every further one: run it with shell access to the control plane.

- The password is generated (40 random characters) and printed once. It is never an argument, so it reaches no shell history, and it is in no log and no audit event. A lost password cannot be recovered or reset yet: create another administrator.
- The account has no second factor. Its first login leads into two-factor enrollment, as the admin app requires.
- The command refuses an address that is not one, an address that is taken, and a role that does not exist, and changes nothing then.
- It writes two audit events with the system as actor: `administrator.created` and `administrator.role_assigned`.

The command is `app/Console/Commands/CreateAdministratorCommand.php`, thin like a controller; the work is in `App\Application\Commands\CreateAdministrator`.

## Resetting an administrator's second factor

`php artisan administrator:reset-two-factor <email>` is for an administrator who lost both the authenticator and the recovery codes (spec section 28). Two-factor is required in the admin realm, so without it the account is locked out. Run it with shell access to the control plane, after you have made sure the request really comes from that administrator.

- It removes the secret and the recovery codes and ends all of the administrator's sessions (the rows of `admin_sessions` with their ID), so the next login, with the password, leads into two-factor enrollment. A session that had passed the second factor would otherwise stay signed in and could enroll a new one without the password.
- It refuses an address no administrator has, and an administrator without a second factor, and changes nothing then. A customer's address is not an administrator's.
- It writes one audit event with the system as actor: `administrator.two_factor_reset`.

The command is `app/Console/Commands/ResetAdministratorTwoFactorCommand.php`; the work is in `App\Application\Commands\ResetAdministratorTwoFactor`.

## End-to-end seed command

`php artisan e2e:seed` creates the accounts and branding of the Playwright suite (`web/e2e`) and prints their generated passwords and TOTP secrets as JSON. It exists only when `APP_ENV` is `e2e` (the stack of `infrastructure/development/compose.e2e.yaml`) or `testing`: `AppServiceProvider` does not register it anywhere else, and the command refuses again when it runs. Do not call it yourself; `make -C infrastructure/development e2e` does. Its classes are in `app/Console/EndToEnd/`. Console commands are an entry point next to `Http`: they call the application layer and nothing depends on them (`tests/Arch/LayeringTest.php`).

## Queue smoke command

`php artisan smoke:queue [--timeout=15]` dispatches a job the way the application dispatches any job, to the default connection and queue, and waits until a worker has run it. It exits non-zero when no worker did within the timeout. The smoke test of the development stack calls it, so a worker that listens on the wrong connection or queue fails (issue #156). The job writes nothing to `job_history`, only a marker in the cache that the command removes. It exists only when `APP_ENV` is `local` or `testing`: `AppServiceProvider` does not register it anywhere else. Its classes are in `app/Console/Smoke/`.

## Requirements

PHP 8.5 with the extensions `pdo_pgsql`, `redis`, `intl`, `pcntl`, `fileinfo` and PCOV (or Xdebug) for coverage. See [decision 0004](../docs/decisions/0004-php-8-5-for-the-control-plane.md). `upload_max_filesize` and `post_max_size` must be at least 2M (the PHP default) for logo uploads.
