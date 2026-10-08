# 0014. Job history in `job_history`, attempts counted in PostgreSQL

- Status: accepted
- Date: 2026-10-08
- Issue: Job infrastructure and job history (#28)

## Context

Spec section 6 calls the reconcile history table `jobs` and reserves true jobs for one-shot actions. Section 8 defines three failure classes: retryable (exponential backoff from 10 s with jitter, capped at 10 minutes, 8 attempts), waiting (never counts toward the attempt limit) and terminal (fails immediately). Section 24 and non-negotiable 7 keep Redis out of correctness.

Checked on 2026-10-08 against the installed `laravel/framework` 13.34.0:

- Laravel's stock migration creates a table `jobs` for the database queue driver. It is unused here: queues run on Redis and `config/queue.php` has no `database` connection.
- Laravel counts an attempt each time a job is reserved. A job that releases itself to wait therefore uses up `tries`. Reproduced: a job with `tries = 0` was released 12 times and `attempts()` reached 12.
- Laravel's limit on exceptions (as opposed to attempts) keeps its counter in the cache store, which is Redis.
- A released job honours its delay, and `->afterCommit()` holds a push until the transaction commits.

## Options considered

1. **Keep the name `jobs` and drop Laravel's table.** It follows the spec to the letter. The table would then sit beside `failed_jobs` and `job_batches`, which are Laravel's, and every Laravel developer would read it as the queue table. Rejected for that reason alone.
2. **Laravel's `tries`, `backoff()` and the exception limit.** No code to write. It cannot express "waiting never counts", and its exception counter lives in Redis, so a flushed cache changes how often a job is retried.
3. **Count attempts in the history row.** Chosen. The row is needed anyway, PostgreSQL is authoritative, and the three classes map to three plain branches.
4. **Laravel Horizon.** Supervises workers and shows a dashboard. There is one queue and one worker in v0.1. Horizon ships Blade views and routes, while the control plane serves no UI, and it stores its metrics in Redis. Not added. Revisit in Slice 7 if worker supervision becomes a problem.

## Decision

- **Table `job_history`**, one row per operation, updated in place: `id` (UUIDv7), `type`, `resource_type`, `resource_id`, `generation` (nullable), `node_id` (nullable), `status`, `failure_class`, `message`, `attempts`, `started_at`, `finished_at`, `created_at`, `updated_at`. No foreign keys: a row outlives the resource and the node.
- **Statuses:** `pending`, `running`, `waiting`, `retrying`, `succeeded`, `failed`.
- **The row is created in the caller's transaction and the job is pushed after commit.** A push that Redis loses leaves a visible `pending` row.
- **Jobs run with `tries = 0`.** The base class `OneShotJob` catches every exception and settles the outcome itself:
  - success: `succeeded`;
  - retryable: `attempts` + 1, then released with the policy's delay, or `failed` at the limit;
  - waiting: `waiting`, released with the recheck delay, `attempts` unchanged;
  - terminal: `failed` at once;
  - a row found `running` when the job is picked up: the previous run was interrupted, which counts as one retryable attempt.
- **A failure without a class is retryable** and is reported to the exception handler.
- **Delay:** `min(cap, base * 2^(attempts - 1))`, shortened by a random share of up to the jitter ratio (default 0.5). Defaults 10 s, 600 s, 8 attempts, recheck 120 s, all in `config/hostmesh.php` under `jobs`.
- **A failed job is also stored in `failed_jobs`** (PostgreSQL) by Laravel. Re-pushing it does nothing: its row is finished. A new attempt is a new job and a new row.
- **No locks.** The application uses no cache lock, unique job or overlap guard. A test enforces it. Section 24 permits best-effort locks; an allow-list is added when the first real use appears.
- **Messages are stored.** A failure message must never contain a secret.

## Consequences

- The spec and decision 0013 say `jobs`. Issue #SC carries the rename and the details the spec does not have.
- Existing development databases keep Laravel's empty `jobs` table until they are rebuilt with `php artisan migrate:fresh`. It is harmless.
- One row per operation loses the message of every attempt but the last. If that history is wanted, a child table of attempts can be added without changing the row.
- `waiting` has no deadline here. A job that waits forever is rechecked forever. The first real waiting job (domain verification, section 10) brings its own deadline.
- Nothing calls `retry_reconcile` yet. The admin endpoint and its audit event come with the resources it acts on.
- Cost of changing our mind: low. The policy is one value object and the outcome handling is one method.
