# 0013. Audit log as a plain append-only table behind an interface

- Status: proposed
- Date: 2026-10-07
- Issue: Audit logging (#27)

## Context

Spec section 21 makes audit logging a first-class feature and gives an initial model: `actor`, `action`, `resource`, `resource_id`, `metadata`, `ip_address`, `created_at`. It must answer who deleted a website, who changed a subscription, why an account was suspended, what changed on a node, and which administrator started a provisioning operation. The admin app has an Audit screen (section 29); the portal does not.

The spec does not ask for tamper evidence, gives no retention period, and does not mention personal data. Section 6 already says that the `jobs` table is "the reconcile history and audit record" of what was attempted, for which generation, on which node, with what result.

Constraints from elsewhere: PostgreSQL is authoritative and Redis never carries correctness (non-negotiable 7). Tests run on real PostgreSQL. Secrets are never logged. Later slices add agents on many nodes that report concurrently.

Two properties follow from "first-class":

- An audited change and its audit event commit together or not at all.
- Writing an event must not make unrelated requests wait for each other.

Checked on 2026-10-07:

- Packagist: `laravel-chronicle/core` 1.13.0 (2026-06-20, MIT). First release 0.1.0 on 2026-03-04, 30 releases in under four months, none since June. About 16,900 installs and 127 stars. Requires `ext-sodium`, `ext-openssl` and `symfony/process`.
- Chronicle's source and docs in the local checkout at commit 74d4d4d, eight housekeeping commits after the 1.13.0 tag: 14,287 lines in `src`, 174 test files, PHPStan level 10 with a seven-line baseline. About 430 of roughly 460 commits are by one author.
- One behaviour reproduced on PostgreSQL 18.6 (below). It was reproduced as SQL in two `psql` sessions, not through the package.

## Options considered

1. **`laravel-chronicle/core`.** A hash-chained ledger with signed checkpoints, external anchoring (RFC 3161, S3), signed exports, per-subject encryption with key destruction, legal holds, pruning, a query API and test fakes. It is far more complete than anything we would write, and the code is careful. The case against it for Hostmesh today:
   - **Concurrent writes fail on PostgreSQL.** `ChainHashEntry` reads the chain head with `ORDER BY sequence DESC LIMIT 1 FOR UPDATE`. Under PostgreSQL's default isolation, a second writer waits for that lock and is then handed the same, now stale, head row. It computes the same next `sequence` and fails on the unique index. The chain stays intact, but the write, and the business transaction around it, is lost. Nothing retries. The package's CI runs on SQLite only, where this cannot appear.
   - **One chain is one global lock.** Even with the lock fixed, a synchronous write inside our transaction holds the chain head until that transaction commits. Every audited transaction in the control plane then runs one at a time.
   - **The queued driver is the documented way around that**, and it needs a single worker on a Redis queue with `tries = 1`. An entry is lost if the job fails or Redis loses it, and the entry and our change no longer commit together. That puts Redis in the correctness path and breaks atomicity.
   - **Erasure is keyed by subject, not by actor.** Encryption wraps `metadata`, `context` and `diff` under the key of the entry's subject. When a customer deletes a website, the subject is the website and the customer's IP address sits in `context` under the website's key. Destroying the customer's key does not reach it.
   - Storage: the payload is stored twice (the hashed JSON and denormalised columns), in `json` rather than `jsonb`, with two unique and five other indexes.
   - It records PHP class names as actor and subject types in rows that can never be rewritten.
   - Scope: 20 console commands, a Blade UI and routes ship with it. Hostmesh serves no UI from Laravel.
   - Immutability is enforced in the Eloquent model, not in the database.
   - It is young and has one maintainer, who is also the only Hostmesh developer. A defect in it would stop Slice 0 work twice over, and Hostmesh's needs would start to steer the package's roadmap.

2. **Our own table with hash chaining.** It has the same global-lock problem and we would have to solve it ourselves. A bare chain is also weaker than it sounds: anyone who can write to the database can recompute it. It only becomes evidence with signed checkpoints and an anchor outside the machine, which is the difficult part and the part Chronicle has already built. We would own security-sensitive code that duplicates the owner's package, in a worse form. Rejected.

3. **A plain append-only table.** Chosen for v0.1. It meets section 21 as written. It gives no tamper evidence, and this record says so plainly rather than implying otherwise.

## Decision

- **`App\Domain\Audit\AuditLog`** is the interface, with one method that records an `AuditEvent` value object. Application-layer commands call it inside the transaction that makes the change. If the event cannot be written, the action fails. An in-memory fake serves the tests.
- **Table `audit_events`:** `id` (UUID), `actor_type`, `actor_id` (nullable), `action`, `resource`, `resource_id`, `metadata` (`jsonb`), `ip_address` (nullable), `created_at`. `actor_type` is a short stable name (`administrator`, `user`, `node`, `system`), never a class name. Indexes on actor, on resource and on `created_at`.
- **Append-only in the database:** a trigger rejects `UPDATE` and `DELETE`, with two exceptions it encodes itself: clearing `ip_address`, and deleting rows older than the retention floor. This guards against bugs. It does not stop someone who controls the database.
- **What goes into an event:**
  - Identifiers only: UUIDs of the actor and the resource. No names, email addresses or other copies of personal data. The read API resolves display names at read time and shows a placeholder when the account is gone.
  - `metadata` holds what changed. For personal fields it records that the field changed, not its values.
  - Never passwords, TOTP secrets, recovery codes, tokens or private keys.
  - A failed login for an unknown address has no actor. The submitted address is not stored (people type passwords into that field). A keyed hash of it is stored so that attempts against one account can be correlated.
- **Erasure:** deleting a customer removes the `users` row. Their events remain, attributed to a UUID that no longer resolves, which pseudonymises them by construction. `ip_address` is cleared immediately for an erased account.
- **Retention:** events are kept for 24 months and `ip_address` for 90 days. Both are configurable defaults, applied by a scheduled job. The trigger's floor for deletion is 12 months and is not configurable, so an operator can shorten retention but a bug cannot remove recent history.
- **Reconciliation:** the audit log records decisions and state changes together with their cause. Reconcile attempts stay in `jobs` (section 6). A reconcile that changes nothing writes no audit event.
- **Read API:** admin realm only, cursor paginated, filtered by actor, resource, action and time, and guarded by a policy on a dedicated permission (decision 0012).
- **Tamper evidence is not part of v0.1.** If it is wanted, the path is a single worker that copies committed events, in order, into a ledger. The table is then the outbox, PostgreSQL stays authoritative, and a single writer never meets the lock problem above. Chronicle is the natural ledger for that.

## Consequences

- No new dependency, one table, one interface. Write cost is one insert in a transaction that is already open, with no lock shared between requests.
- An operator with database access can alter the log undetected. That is the price of this option.
- **Cost of changing our mind.** Low towards a ledger: callers depend on `AuditLog` and events are already structured, so a ledger is added behind the interface or fed from the table, and existing rows can be imported in order. History from before that day cannot be made tamper-evident retroactively: it is only as trustworthy as the table was. Moving away from Chronicle after adopting it would be harder, because its checkpoints, signatures and key material have no equivalent elsewhere.
- **What would change the recommendation towards Chronicle:** a head lock that is correct on PostgreSQL and tested under concurrency there, a write path that is atomic with the caller's transaction without holding that lock and without Redis, and erasure that covers the actor's data. These are upstream changes and should not be driven by the Slice 0 deadline.
- The questions of section 21 are answerable: who and what from the actor and resource columns, why from `metadata`, and the initiating administrator from the event that created the operation.
- Nothing here is legal advice. Whether a dangling identifier is still personal data, and how long an IP address may be kept, is for a data-protection adviser. Backups keep erased data until they expire.
- The retention periods are engineering defaults, not legal findings. A data-protection adviser should confirm them before the beta gate.
- **Follow-ups:**
  - A `spec-change` issue (#69) updates section 21: `actor` becomes `actor_type` and `actor_id`, a keyed hash of the submitted login address is stored for failed logins, and the retention defaults are recorded.
  - Tamper evidence goes to the v0.2 backlog (section 38), together with the upstream Chronicle work listed above.
