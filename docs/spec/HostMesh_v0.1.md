# Hostmesh - v0.1 Architecture & MVP Specification

**Status:** Frozen baseline (revision 13)
**Version:** v0.1
**Purpose:** Open-source hosting infrastructure, control-plane, and billing platform

## Revision history

| Rev  | Date       | Change                                                                                                                                                                                                                                                                                                                                                                             |
|------|------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| 0    | -          | Original frozen baseline                                                                                                                                                                                                                                                                                                                                                           |
| 1    | 2026-09-29 | Merged: outbound-only agent (no inbound listener, FastAPI removed); generation-based desired state; scope trimming and v0.2 backlog; API-first reworded; mandatory mTLS with built-in CA and signed payloads; test-driven development methodology (Pest 5 / pytest)                                                                                                                |
| 2    | 2026-09-29 | Frontend: Livewire removed; Laravel exposes only the API; two Vue 3 SPAs (admin, portal) in `web/`; OpenAPI generation and typed client pulled into v0.1; Sanctum SPA auth; Vitest and Playwright added                                                                                                                                                                            |
| 3    | 2026-09-30 | Provisioning: condition-based operation state machines (create, update, suspend, delete, detach domain); failure classes and `waiting` / `degraded` statuses; temporary hostnames with a shared wildcard certificate; separate domain lifecycle with ownership verification and ACME gating; quarantine on delete                                                                  |
| 4    | 2026-09-30 | Roadmap re-sliced into eight vertical slices with alpha and beta gates, exit tests and a planning calendar for one full-time developer; node migration moved to the v0.2 backlog; open items ordered by when they are needed                                                                                                                                                       |
| 5    | 2026-09-30 | Frontend details settled: shadcn-vue component library; three-layer theming (tokens, branding configuration, theme folder); hostname layout with per-app subdomains and admin/portal session isolation                                                                                                                                                                             |
| 6    | 2026-09-30 | Scheduler settled: hosting account as the unit of placement (plan limits per account); reserved-versus-used capacity model with per-node overcommit; transactional reservation in PostgreSQL; filter-then-score with spread and binpack; node states; Redis no longer used for scheduler correctness                                                                               |
| 7    | 2026-09-30 | PowerDNS topology settled: dedicated DNS nodes (at least two in production) reconciled by the agent into local PowerDNS (SQLite backend), no zone transfers, DNS-01 waits for convergence on all active DNS nodes; DNS-node work moved into Slice 2                                                                                                                                |
| 8    | 2026-09-30 | Hosting essentials settled: sandbox baseline (account slice, one PHP-FPM master per account and PHP version, unit sandboxing, quotas, egress rule); key-only SFTP with an optional SSH shell; cron as systemd timers; root-owned logs with a read-only bind mount and portal viewer; typed per-site PHP settings; PHP versions from a pinned Sury repository. No open items remain |
| 9    | 2026-10-01 | Licensing decided: Apache-2.0, DCO sign-off, trademark policy; business model recorded; add-on mechanism added to the v0.2 backlog                                                                                                                                                                                                                                                 |
| 10   | 2026-10-12 | Implementation findings of Slice 0 recorded: `job_history` table, its row statuses and the retry details (§6, §8); audit event model, content rules and retention (§21); Redis limited to queues, caching and rate limiting (§24); `admin` and `customer` route prefixes, the Origin rule, branding details and the first admin roles (§28); two v0.2 backlog items (§38)          |
| 11   | 2026-10-13 | Slice 0 review: console commands as an entry point (§25); `web/e2e/`, `infrastructure/nginx/` and `infrastructure/hygiene/` in the repository tree (§27); the health endpoint and how the apps find the API (§28); the two-node principle starts with Slice 1 (§33); where the Playwright tests run and where their data comes from (§37); abilities for navigation recorded as an open item (§39) |
| 12   | 2026-10-13 | One coverage target, 95% for the control plane and the agent, replaces 85% overall and 95% for three modules (§37)                                                                                                                                                                                                                                                                 |
| 13   | 2026-10-13 | The web workspace gets the coverage target of 95%, on statements, branches, functions and lines, without the components generated by the shadcn-vue CLI (§37)                                                                                                                                                                                                                      |

---

## 1. Product Definition

The product is an **open-source hosting infrastructure platform**, rather than simply another cPanel/WHM/Plesk-style control panel.

The core idea is to combine:

- Multi-server hosting management
- Infrastructure orchestration
- Hosting control
- Automatic provisioning
- Native products and subscriptions
- Billing and invoicing
- Payment integration
- API-first automation

The key differentiator is that **multi-server infrastructure and billing are first-class concepts from the beginning**.

The platform should allow a hosting provider to operate multiple Linux servers as one logical environment.

---

# 2. Core Architecture

The platform consists of two primary layers:

1. **Control Plane**
2. **Node Agent**

High-level architecture:

```text
                         ┌─────────────────────────────┐
                         │       Control Plane         │
                         │                             │
                         │ Laravel 13                  │
                         │ Sanctum auth                │
                         │ REST API                    │
                         │ PostgreSQL                  │
                         │ Redis / Queues              │
                         │ Billing                     │
                         │ Provisioning Engine         │
                         │ Scheduler                   │
                         └──────────────┬──────────────┘
                                        │
                              HTTPS / mTLS API
                                        │
             ┌──────────────────────────┼──────────────────────────┐
             │                          │                          │
             ▼                          ▼                          ▼
     ┌───────────────┐          ┌───────────────┐          ┌───────────────┐
     │ Hosting Node 1│          │ Hosting Node 2│          │ Hosting Node 3│
     │               │          │               │          │               │
     │ Python Agent  │          │ Python Agent  │          │ Python Agent  │
     │ Nginx         │          │ Nginx         │          │ Nginx         │
     │ PHP-FPM       │          │ PHP-FPM       │          │ PHP-FPM       │
     │ MariaDB       │          │ MariaDB       │          │ MariaDB       │
     │ DNS           │          │ DNS           │          │               │
     └───────────────┘          └───────────────┘          └───────────────┘
```

All connections between the control plane and the nodes are initiated by the agent (see §5).

The admin and customer user interfaces are separate Vue single-page applications that consume the REST API (§29). Laravel serves no application UI.

---

# 3. Control Plane

The Laravel control plane owns the platform's business and desired state.

Responsibilities:

- Authentication
- Authorization
- Customers
- Users
- Products
- Plans
- Prices
- Subscriptions
- Invoices
- Payments
- Websites
- Domains
- Databases
- DNS
- Certificates
- Nodes
- Clusters
- Resources
- Provisioning
- Scheduling
- Jobs
- Events
- Audit logs
- API
- Admin API and Customer API endpoints (consumed by the Vue apps, §29)

The control plane should **not directly manipulate Linux servers**.

---

# 4. Node Agent

Every managed server runs a Python-based agent.

Example:

```text
hosting-agent
```

The agent is responsible for translating desired state into actual Linux configuration.

Responsibilities:

- Linux users
- Filesystems
- Permissions
- cgroups
- systemd
- Nginx
- PHP-FPM
- MariaDB
- PostgreSQL later
- DNS
- Certificates
- Firewall
- Resource limits
- Backups later
- Service status
- Metrics
- Reconciliation

Example:

```text
Laravel
   │
   │ "Create website"
   ▼
Python Agent
   │
   ├── create Linux user
   ├── create directories
   ├── configure PHP-FPM
   ├── configure Nginx
   ├── create database
   ├── serve on temporary hostname (shared wildcard certificate)
   └── report result
```

---

# 5. Control Plane ↔ Agent Communication

The agent is a **pure outbound HTTPS client**. It runs **no inbound network listener**, and no hosting node exposes a management port to the public Internet.

Conceptually:

```text
Agent
  │
  ├── HTTPS (mTLS) -> control.example.com
  │
  ├── long-poll for desired-state changes
  │
  └── report observed state and results
```

### Transport

- HTTPS **long-poll**. The agent issues a request and the control plane holds it open (default ~30 seconds) until something changes or the timeout elapses. This gives near-push latency without opening ports.
- HTTP client: `httpx` (async). There is no server framework in the agent.
- Local debugging and operations use a CLI and/or unix socket. Neither is exposed on the network.

### Invariant

A running site must keep working if the control plane is unreachable. The agent persists the last applied desired state locally and never tears down resources because it cannot reach the control plane.

### Authentication

mTLS is **mandatory** for all agent-to-control-plane traffic. The control plane operates a **built-in private CA**.

**Enrollment**

1. An administrator generates a single-use **bootstrap token** for a new node (expires after ~1 hour).
2. The agent generates a keypair locally and submits a CSR with the token.
3. The control plane validates the token, signs a client certificate with the **node UUID as identity**, and returns it together with the control plane's payload-signing public key.
4. The token is consumed and cannot be reused.

**Rotation and revocation**

- Client certificates are short-lived (~30 days). The agent renews using its current valid certificate when the remaining lifetime drops below ~10 days.
- Marking a node `revoked` in PostgreSQL causes the mTLS-terminating layer to reject it. A revoked node cannot renew.
- The CA sits behind a `NodeCertificateAuthority` interface so an external CA (for example step-ca) can be swapped in later.

**Deployment requirement**

The agent endpoint is served on its own hostname and must **not** sit behind a CDN or proxy that terminates TLS (for example Cloudflare proxying), because that breaks mTLS.

**Payload signing**

- The control plane signs desired-state and job payloads with **Ed25519**. The agent verifies them against the public key pinned at enrollment and rejects unsigned or invalid payloads.
- The signing private key is stored **outside the database** (secret store or protected file), so a database compromise alone cannot forge payloads.
- Signing key rotation must be supported (the agent accepts a key set with identifiers).

**Agent input handling**

- The agent validates every input against strict schemas. **No shell command strings** are accepted from the control plane, only typed operations.
- The node agent must never receive control-plane database credentials.

---

# 6. Desired-State Model

The platform should be built around **desired state**, rather than collections of shell commands.

Example:

```text
HostingAccount
────────────────────
plan: Business Hosting
cpu: 2
memory: 2GB
storage: 50GB

Website (inside the account)
────────────────────
domain: example.com
php: 8.4
ssl: enabled
database: enabled
```

The control plane stores the desired state.

The agent reconciles actual state against desired state.

```text
             Desired State
                  │
                  ▼
          Provisioning Engine
                  │
                  ▼
             Node Agent
                  │
                  ▼
             Actual State
```

This allows safe retries and eventual automatic recovery.

**Desired state is authoritative.** Jobs are not the source of truth for what should exist.

### Resource status model

Every managed resource (website, database, DNS zone, certificate, and so on) carries:

```text
generation            integer, incremented on every desired-state change (control plane)
observed_generation   integer, last generation the agent fully applied (reported by agent)
status                pending | reconciling | waiting | ready | degraded | suspended | failed | deleting   (see §8)
conditions[]          type, status, reason, message, last_transition_at
```

### Agent loop

1. Long-poll for resources on this node where `generation > observed_generation`, or that are marked for deletion.
2. Reconcile each resource using idempotent `ensure_*` operations (§7).
3. Report `observed_generation`, `status`, `conditions`, and any error.
4. Persist the last applied state locally (see the invariant in §5).

### Rules

- **Suspend and unsuspend are desired state** (`suspended: true|false`), not commands.
- **Deletion is a tombstone** (`deleted_at` set, `status = deleting`). The control plane hard-deletes the row only after the agent confirms the tombstone's generation, which for data-bearing resources means the data is in quarantine (§8).
- **Payload versioning:** every desired-state payload carries `schema_version`. The agent reports its `agent_version` and supported schema versions on each poll. The control plane must not send a schema the agent does not support.

### Jobs

- The `job_history` table is the **reconcile history and audit record**: what was attempted, for which generation, on which node, with what result. It is not named `jobs`, because that is the name of Laravel's database-queue table.
- It holds **one row per operation, updated in place**, with an attempt counter and the last message. Columns: `type`, `resource_type`, `resource_id`, `generation`, `node_id`, `status`, `failure_class`, `message`, `attempts`, `started_at`, `finished_at`.
- Row statuses: `pending`, `running`, `waiting`, `retrying`, `succeeded`, `failed`.
- True jobs are reserved for **one-shot actions that are not state**: backup now, restart service, force certificate renewal, collect diagnostics.

---

# 7. Reconciliation

Operations should be expressed as "ensure" operations.

Examples:

```text
ensure_user()
ensure_website()
ensure_database()
ensure_dns_zone()
ensure_nginx_config()
ensure_php_pool()
ensure_ssl()
ensure_ssh_keys()
ensure_cron_job()
ensure_log_config()
```

Operations must be **idempotent**.

Running the same operation twice should produce the same desired result rather than duplicate or corrupt resources.

### Idempotency key

The idempotency key for a reconcile is `(resource_id, generation)`. Re-delivery of a generation that is already applied must be a no-op.

### Periodic resync

The agent performs a full resync (default every 10 minutes): it compares actual state to desired state for every resource on the node and reports drift. Drift is corrected automatically unless the resource is flagged `manual_hold`.

---

# 8. Provisioning and Operation State Machines

Provisioning must not be a single large job, and it is not a stored linear enum either. State is expressed as **conditions** reported by the agent while it reconciles a resource (§6). The sequences below are **dependency orders and operation rules**.

### Statuses

| Status        | Meaning                                                                                 |
|---------------|-----------------------------------------------------------------------------------------|
| `pending`     | Desired state recorded, the agent has not started                                       |
| `reconciling` | The agent is applying the current generation                                            |
| `waiting`     | Blocked on an external condition (for example DNS propagation). Not a failure           |
| `ready`       | Current generation fully applied and healthy                                            |
| `degraded`    | Running on the last-known-good configuration because applying a newer generation failed |
| `suspended`   | Suspended per desired state                                                             |
| `failed`      | Terminal, or retries exhausted. Needs a new generation or an administrator retry        |
| `deleting`    | Tombstone being processed                                                               |

### Failure classes

| Class     | Examples                                               | Behavior                                                                                                  |
|-----------|--------------------------------------------------------|-----------------------------------------------------------------------------------------------------------|
| Retryable | service timeout, ACME 5xx, network error               | Exponential backoff from 10 s with jitter, capped at 10 min. After 8 attempts the status becomes `failed` |
| Waiting   | DNS not propagated, ACME retry-after                   | Status `waiting`, scheduled rechecks (see §10). Never counts toward the attempt limit                     |
| Terminal  | invalid input, quota exceeded, unsupported PHP version | `failed` immediately                                                                                      |

A `failed` resource is retried when its generation changes or when an administrator triggers the one-shot job `retry_reconcile`. All numbers are defaults and must be configurable.

- A failure that carries no class is treated as retryable.
- **Jitter** shortens a delay by a random share of up to 50%, so the cap always holds.
- The default recheck interval for `waiting` is 2 minutes.
- A run that was interrupted (worker killed, timeout) counts as one retryable attempt.
- Attempts are counted in PostgreSQL (`job_history`, §6), not by the queue.

### Conditions

Hosting account (§12): `UserReady`, `StorageReady`, `LimitsReady`, `AccessReady` (SFTP chroot and authorized keys applied), and the aggregate `Ready`.

Website: `RuntimeReady`, `WebServerReady`, `DatabaseReady`, `HostnameReady`, `TlsReady`, and the aggregate `Ready`. A website requires its hosting account to be `Ready`, shown as `AccountReady` in the create sequence below.

Domain (§10): `OwnershipVerified`, `RoutingVerified`, `CertificateReady`, and the aggregate `Active`.

### Create

Dependency order:

```text
AccountReady        hosting account: Linux user, storage, cgroup limits
      │
      ▼
RuntimeReady        PHP-FPM pool, systemd / cgroup limits
      │
      ▼
WebServerReady      Nginx vhost
      │
      ▼
DatabaseReady       database and database user
      │
      ▼
HostnameReady       temporary hostname published (control plane, §15)
      │
      ▼
TlsReady            shared wildcard certificate installed and serving
      │
      ▼
READY
```

- Every step is an idempotent `ensure_*` operation.
- The hosting account is created once per subscription, before its first website. Later websites skip that step.
- Creation only rolls forward. A partially created website is completed by the next reconcile, or removed by a normal delete.
- `HostnameReady` is published by the control plane's DNS service as soon as a node is selected, so it can proceed in parallel with the agent steps.
- Customer domains are not part of website creation. They follow the domain lifecycle (§10) and never delay `READY`.

### Update

Applies to PHP version, resource limits, attached domains and similar changes. The agent uses a safe-apply sequence:

1. Render the new configuration.
2. Validate it (for example `nginx -t`, `php-fpm -t`).
3. Swap it in atomically and reload.
4. Health-check the site.

On any failure the agent restores the last-known-good configuration, reports `degraded` with the reason, and does **not** advance `observed_generation`. An update must never take a running site down.

### Suspend and unsuspend

Driven by the desired-state flag (§6). On suspend the agent:

- stops the PHP-FPM pool,
- serves a suspension page from Nginx,
- locks database logins,
- pauses cron.

Data, DNS and certificate renewal are untouched. Unsuspend reverses every step. The resource reports `suspended` while the flag is set.

### Delete

1. **Tombstone.** The control plane sets `deleted_at` and a `destroy_after` timestamp. It sets `destroy_after` when the subscription reaches `TERMINATED` after its retention period (§19). The agent refuses destructive steps when `destroy_after` is absent or in the future.
2. **Stop serving.** The agent removes the vhost, stops the pool and revokes database logins.
3. **Quarantine.** Site files are moved, and databases are dumped, into a quarantine directory on the node. The agent then reports the tombstone as confirmed.
4. **Cleanup.** The control plane hard-deletes the resource row, its DNS records and its per-domain certificates.
5. **Purge.** The node purges the quarantine after the quarantine window (default 7 days). The purge is local and audited.

Quarantine exists so that a control-plane bug cannot irreversibly destroy customer data. Backups (later) do not replace it.

### Detach domain

A domain removal is its own small operation: the agent removes the `server_name` through the safe-apply sequence above, the per-domain certificate is removed after a grace period, and in hosted mode the zone records are removed. The domain then moves to `removed`.

---

# 9. Infrastructure Domain

Initial infrastructure entities:

```text
clusters
nodes
node_capabilities
node_services
node_metrics
node_capacity
```

A node should expose capabilities rather than relying on hardcoded server types.

Example:

```text
Node
 ├── capability: web
 ├── capability: php
 ├── capability: mysql
 └── capability: dns
```

This allows future support for different server types without changing the core domain model.

### Node states

| State         | Meaning                                              |
|---------------|------------------------------------------------------|
| `active`      | Eligible for new placements                          |
| `cordoned`    | No new placements. Existing accounts keep running    |
| `maintenance` | Operator maintenance in progress. No new placements  |
| `offline`     | Heartbeat lost (default: no heartbeat for 2 minutes) |

Draining with automatic migration is a v0.2 item (§38).

### DNS nodes

A node with the `dns` capability runs authoritative PowerDNS (§15). In production these are **dedicated nodes**: hosting nodes never run PowerDNS, and at least **two** DNS nodes are required, on different networks. A single node carrying both capabilities is allowed for development, tests and very small installs.

---

# 10. Hosting Domain

Initial hosting entities:

```text
customers
products
plans
subscriptions

hosting_accounts
placements
ssh_keys
cron_jobs

websites
domains
databases
database_users
dns_zones
certificates
storage_volumes
```

Conceptually:

```text
Customer
   │
   └── Subscription
           │
           └── Product / Plan
                 │
                 ├── CPU
                 ├── Memory
                 ├── Storage
                 ├── Domains
                 └── Databases
```

A subscription owns the resources created for the customer. Those resources live inside the subscription's **hosting account** (§12), on the account's node.

### Temporary hostnames

Every website receives a temporary hostname, `<id>.<temp-domain>`, on a platform-owned domain.

- The control plane's DNS service publishes a per-website A/AAAA record in the temporary zone, pointing at the node the scheduler selected (§15).
- TLS uses one shared wildcard certificate for the temporary zone (§16).
- A website is `READY` once it serves valid HTTPS on its temporary hostname.
- The temporary domain must be a **dedicated domain**, not the main brand domain, and should be on the Public Suffix List for cookie isolation between customers.

### Domains

A customer's own domain is a separate `domain` resource attached to a website, with its own lifecycle. It never blocks or fails the website.

Model:

```text
domains
├── hostname
├── website_id
├── mode                    hosted | external
├── ownership_token
├── status
├── ownership_verified_at
├── routing_verified_at
├── verification_deadline
└── last_checked_at
```

Modes:

- **hosted:** the customer delegates NS to the platform's PowerDNS (§15).
- **external:** DNS stays elsewhere and the customer points A/AAAA at the platform.

Lifecycle:

```text
PENDING_OWNERSHIP ─proven─▶ PENDING_ROUTING ─points to us─▶ ISSUING_CERT ─▶ ACTIVE
        │                          │
        └────── deadline passes ───┴──▶ EXPIRED (customer can restart)
```

Rules:

- **Ownership proof.** External mode uses a TXT record at `_hostmesh-verify.<domain>` holding the token. Hosted mode uses an NS delegation check.
- **Claims.** A pending claim never serves traffic and never blocks another claim. Only one *active* claim per hostname may exist platform-wide, and the first to verify wins. The Nginx `server_name` is added only after ownership is proven.
- **Routing.** Once ownership is proven, the domain must resolve to the platform. Domains behind a proxy or CDN are a v0.2 item (§38).
- **Who checks.** The control plane performs all DNS checks against public resolvers. It sets `certificate.issue_allowed` in desired state only when ownership and routing both pass. The agent never attempts certificate issuance speculatively (§16).
- **Waiting.** While `PENDING_OWNERSHIP` or `PENDING_ROUTING`, the status is `waiting`. The control plane rechecks every 2 minutes for the first hour, then every 30 minutes, until `verification_deadline` (default 7 days), then the domain becomes `EXPIRED`. The customer can trigger an immediate recheck (rate-limited) and can restart an expired domain.
- **Certificate issuance.** `ISSUING_CERT` follows the retry and waiting rules in §8 and §16.

---

# 11. Products and Plans

Products should describe what the customer purchases.

They should **not** hardcode a physical server.

Example:

```text
Product:
    Business Hosting

Plan:
    2 CPU
    2 GB RAM
    50 GB storage
    10 domains
    5 databases
    €9.99/month
```

The scheduler determines where the resources are provisioned.

Plan limits (CPU, memory, storage) apply per **hosting account**, that is per subscription, not per website. Counts such as domains and databases are also plan-level and are enforced by the application layer.

### Plan features

SFTP is included with every plan. The following are plan-level settings (defaults shown are initial values, configurable by the operator):

| Feature              | Meaning                             | Default   |
|----------------------|-------------------------------------|-----------|
| `shell_access`       | SSH shell in addition to SFTP (§14) | off       |
| `max_cron_jobs`      | Cron jobs per account               | 10        |
| `max_ssh_keys`       | SSH keys per account                | 5         |
| `log_retention_days` | Days of rotated logs kept           | 14        |

---

# 12. Scheduler

The scheduler selects the node for a new **hosting account**. It is the only placement decision in the platform: websites, databases and domains inherit the account's node.

### Hosting account

The hosting account is the unit of placement and isolation.

- It belongs to one subscription.
- It is one Linux user, one cgroup slice, and the plan's CPU, memory and storage limits.
- Websites, databases and domains are children of the account and always live on its node. Co-location of a site, its databases and its storage therefore holds by construction, since MariaDB is node-local.
- The scheduler decides once, at account creation. Adding a second website never triggers scheduling.
- The account stores a **snapshot of the plan limits**, so later plan edits do not silently change reservations.

### Capacity model

Each node has `allocatable` and `reserved` values per dimension: CPU (millicores), memory and storage, plus a maximum account count.

- `allocatable = physical capacity × overcommit factor − system reserve`
- `reserved` is the sum of the plan limits of the accounts placed on the node, tracked in PostgreSQL.
- **Observed usage** comes from agent metrics. It is a guardrail (for example, placement is refused when observed disk or memory utilization exceeds a threshold, default 85%) but never the accounting basis.
- Default overcommit factors, configurable per node: **CPU 4×, memory 2×, storage 1×**. Storage stays at 1× because quotas are hard limits.
- The scheduler reads only the latest status sample per node, not the metrics history.

### Reservation

Placement runs in a single PostgreSQL transaction. It picks the best candidate, then reserves capacity with one guarded update:

```text
UPDATE node_capacity
SET reserved_* = reserved_* + request
WHERE node_id = :id
  AND reserved_* + request <= allocatable_*   (every dimension)
```

Zero affected rows means the candidate filled up concurrently, so the scheduler tries the next candidate. The `placements` row is written in the same transaction. No explicit locks are used and Redis is not involved.

Placement is **sticky**: a retry reuses the account's existing placement and never re-decides.

### Filtering and scoring

Hard filters:

- node state is `active`
- heartbeat is fresh
- required capabilities are present (for example web, PHP version, MySQL)
- the region or label constraint matches, if one is set
- headroom on every dimension
- observed-usage guardrails pass
- the agent supports the payload `schema_version`

Scoring:

```text
eligible nodes
      ↓
spread: minimize the node's dominant reserved share after placement
      ↓
tie-break: fewer accounts, then node ID
```

**Spread** is the default because it limits the blast radius of a node failure. `binpack` (maximize the same measure) is a per-cluster option. Tie-breaks make placement deterministic, which keeps tests stable.

### Lifecycle interactions

- **Suspend** keeps the reservation, so unsuspend can always succeed.
- **Delete** releases CPU and memory when the tombstone is processed. Quarantined data still uses disk and appears in observed usage until purge. The storage reservation is released at hard-delete.
- **Terminal creation failure** releases the reservation automatically.
- **Plan change** resizes the reservation with the same guarded update, applied to the delta. If the node lacks headroom, v0.1 rejects the change with a clear reason, because moving accounts is a v0.2 item.

### Data model

```text
node_capacity
├── node_id
├── allocatable_cpu_millicores / _memory_mb / _storage_gb
├── overcommit_cpu / _memory / _storage
├── reserved_cpu_millicores / _memory_mb / _storage_gb
├── max_accounts
├── account_count
└── updated_at

placements
├── id
├── account_id            (unique)
├── node_id
├── cpu_millicores / memory_mb / storage_gb
├── status                reserved | active | releasing | released
├── created_at
└── released_at
```

### Invariants

- `reserved` never exceeds `allocatable` in any dimension.
- `reserved` equals the sum of live placements. A nightly job verifies this and alerts on any difference.
- Each account has at most one live placement.

### Future criteria

Availability zones, affinity and anti-affinity, weighted scoring, and rebalancing with account migration.

---

# 13. Resource Isolation

v0.1 uses native Linux mechanisms rather than attempting to reproduce CloudLinux LVE. The isolation baseline is built in Slice 2, before any customer code runs.

### Mechanisms

- Linux users
- Filesystem permissions and user quotas
- systemd slices and unit sandboxing
- cgroups v2
- nftables egress rules

### Account slice

Each hosting account (§12) gets its own systemd slice carrying the plan's CPU, memory, task and I/O limits.

```text
hostmesh-<account>.slice      limits from the plan snapshot
   ├── PHP-FPM master, PHP 8.4    systemd unit, sandboxed
   ├── PHP-FPM master, PHP 8.3    only if a site uses it
   └── cron jobs                  systemd services, same sandbox
```

- There is **one PHP-FPM master per account and PHP version**, as a templated systemd unit inside the account's slice. Workers of a shared master would live in the master's cgroup, so per-account limits would not cover them.
- Pools use on-demand spawning to keep idle cost low. The trade-off is more master processes per node.

### Unit sandboxing

PHP-FPM units and cron services share one sandbox definition:

- private `/tmp`
- a private mount namespace exposing only the account's own directories
- read-only system paths
- no new privileges and an empty capability set
- restricted address families

`open_basedir` remains enabled as defense in depth.

### Filesystem layout

```text
<account root>/           root-owned, not writable by the account (SFTP chroot base)
├── sites/<website>/      account-owned
├── tmp/                  account-owned
└── logs/                 read-only bind mount of the account's log directory (§14)
```

### Quotas

- **Files:** filesystem user quotas, enforced hard.
- **Databases:** database size is tracked by the agent and enforced softly with alerts, because the MariaDB data directory is not user-owned. Hard enforcement is a v0.2 item (§38).

### Egress

Outbound TCP port 25 is blocked for account UIDs with nftables. Ports 465 and 587 remain open so applications can use external mail providers.

### PHP function policy

The operator configures a `disable_functions` list for PHP-FPM. Defaults: `exec`, `passthru`, `shell_exec`, `system`, `popen`, `proc_open`. Customers cannot change it. PHP CLI and cron are unaffected.

### Nginx configuration policy

**No arbitrary Nginx configuration snippets in v0.1**, because config injection would defeat isolation. Websites use typed options only:

- app preset: Laravel, WordPress, generic front controller, or static
- docroot subpath
- force HTTPS
- canonical `www` behavior
- maximum request body size

Dotfiles are denied except `.well-known`. `.htaccess` is not supported.

Advanced isolation can be added later.

---

# 14. Supported Hosting Stack - v0.1

The reference node configuration should intentionally be narrow.

### Operating System

- Debian 13

### Web

- Nginx
- PHP-FPM

### PHP

Initially:

- PHP 8.3
- PHP 8.4
- PHP 8.5

### Database

- MariaDB

### DNS

- PowerDNS

### SSL

- ACME / Let's Encrypt

The platform should initially focus on one highly reliable reference stack instead of supporting every Linux distribution and web server.

### PHP versions and source

Debian 13 ships PHP 8.4 by default, so PHP 8.3 and 8.5 require a third-party repository. **All three versions come from the Sury repository**, pinned through apt preferences with a verified signing key, and operators may mirror it. No PHP packages are taken from Debian main, because mixing distribution and Sury packages has caused dependency conflicts.

### Per-site PHP settings

Settings are a typed whitelist, not raw `php.ini`. Bounds come from the plan and the account's limits. The defaults below are initial values, configurable by the operator.

| Setting               | Default    | Bounds                             |
|-----------------------|------------|------------------------------------|
| `memory_limit`        | 256M       | at most the account's memory limit |
| `upload_max_filesize` | 64M        | up to 512M                         |
| `post_max_size`       | 64M        | at least `upload_max_filesize`     |
| `max_execution_time`  | 60 s       | up to 300 s                        |
| `max_input_vars`      | 3000       | up to 10000                        |
| `display_errors`      | off        | on or off                          |
| error reporting       | production | production or development preset   |
| `date.timezone`       | UTC        | any valid timezone                 |
| opcache               | on         | on or off                          |

- Extensions are toggled from a curated list. A standard set is on by default, and optional ones (for example `imagick`, `soap`, `apcu`) can be enabled per website.
- FPM sizing (maximum children, idle timeout) is computed from the plan and is not user-editable in v0.1.

### SFTP and SSH

- **Key-only authentication** (ed25519, ECDSA, or RSA of at least 3072 bits). Password authentication is disabled.
- Keys are desired state (`ssh_keys`). The agent writes them to root-owned `authorized_keys` files outside the account root, so customers cannot alter them. Adding and removing keys is audited.
- **SFTP is available for every account**, chrooted to the account root with `internal-sftp`. TCP and X11 forwarding are disabled for accounts.
- **SSH shell is a plan feature, off by default** (`shell_access`). A login shell cannot receive the mount-namespace sandbox that PHP and cron units get, so its isolation is weaker. Shell sessions are not chrooted, and rely on strict permissions between accounts, `hidepid=2` on `/proc`, and limits applied to the user slice. Operators enable it knowingly.
- Customers connect through the website's temporary hostname or an attached domain.

### Cron

- One systemd timer and one oneshot service per job (`cron_jobs`), running in the account slice with the sandbox from §13.
- Customers enter a validated 5-field cron expression (plus `@hourly`, `@daily` and similar). The agent translates it to a timer calendar specification, and tests prove both fire at the same times.
- Jobs do **not overlap**: if a run is still in progress, the next tick is skipped. A runtime cap (default 1 hour, limited by the plan) stops runaway jobs.
- Commands run through `/bin/sh -c` from the website's directory. A job may name a PHP version, which selects the matching CLI binary.
- Output goes to the journal and is viewable through logs. The agent reports last run, exit code and next run.
- Plan limits: `max_cron_jobs` (default 10). The minimum interval is 1 minute, because the Laravel scheduler needs it.

### Logs

- Access and error logs are written by root-controlled processes into a **root-owned directory outside the customer's writable space**. A customer symlink in a writable log directory could redirect a root writer to arbitrary files, which is a known class of Nginx log-directory vulnerability. The directory appears read-only inside the account root as `logs/` through a bind mount.
- Rotation is daily, with `log_retention_days` (default 14) retained, compressed, and a size cap per log.
- The portal offers a bounded viewer through the one-shot job `fetch_logs` (log type: access, error, PHP error, or a cron job). It returns at most the last 500 lines or 1 MB, whichever is smaller, uploaded by the agent over its outbound channel and discarded after an hour. **Live tailing remains a non-goal.**

---

# 15. DNS

PowerDNS is the authoritative DNS implementation. DNS follows the same model as the rest of the platform: the control plane stores desired state, and agents reconcile it.

```text
Control Plane
(desired state: dns_zones, dns_records)
        │
        │  outbound long-poll (§5)
        ▼
Agent on each DNS node
        │
        │  PowerDNS API, localhost only
        ▼
PowerDNS (authoritative only)
```

### DNS nodes

- DNS nodes are ordinary nodes with the `dns` capability (§9). In production there are at least two, dedicated, on different networks.
- **The control plane never connects to PowerDNS.** All changes travel over the agent's outbound long-poll. PowerDNS listens on port 53 publicly and exposes its API on localhost only, protected by an API key.

### Reconciliation

- **No zone transfers and no shared database.** Each DNS node is independently primary for every zone.
- The local PowerDNS store uses the **SQLite backend**. It is a disposable cache: a new DNS node enrolls and converges from desired state. LMDB remains an option later.
- The control plane assigns each zone's **SOA serial** in desired state, so all DNS nodes serve identical serials.
- Records are reconciled as individual rrsets with their own generation (§6). The temporary zone, with one record per website, therefore never resends the whole zone on a change. The periodic full resync (§7) repairs drift.

### Zone and record model

```text
dns_zones
├── id
├── name
├── kind                  temp | hosted
├── soa_serial
├── generation
└── status

dns_records
├── id
├── zone_id
├── name
├── type
├── ttl
├── content
├── managed               platform-managed and protected
├── generation
└── status
```

- Supported record types in v0.1: **A, AAAA, CNAME, MX, TXT, SRV, CAA**.
- The application layer validates syntax, CNAME exclusivity and ownership, and enforces a per-zone record limit (default 500).
- Platform-managed records (the apex and `www` pointing at the account's node, and temporary hostname records) are marked `managed` and protected. Customers cannot edit them, nor the apex NS and SOA records. Managed records follow the website's node.

### Modes

- **Hosted zones:** the customer delegates NS to the platform. The control plane stores the zone records as desired state and the DNS nodes reconcile them.
- **External DNS:** the platform does not manage the customer's records. The portal shows the exact records to create (A/AAAA targets and the ownership TXT).

### Temporary zone

The platform owns a dedicated temporary domain (§10). The control plane publishes one A/AAAA record per website in the temporary zone, pointing at the website's node. Keep the TTL low. `HostnameReady` (§8) becomes true when the record's publication status is `ready`.

### Nameservers and glue

Nameservers are `ns1.<brand-domain>`, `ns2.<brand-domain>` and so on, one per DNS node. Each needs a static public IPv4 address (and IPv6 where available). The operator registers **glue records** at the registrar. Both the temporary domain and hosted customer zones delegate to these names. The installer includes a preflight check (Slice 7) that verifies port 53 is reachable from outside and that delegation is correct.

### DNS-01 and propagation

For the wildcard certificate (§16), the control plane writes the `_acme-challenge` TXT record as an ordinary record. It then waits until **every active DNS node** reports the record's generation as observed before asking ACME to validate, because the ACME server may query any nameserver. It removes the record afterward.

### Publication status

A record is `pending` until one DNS node converges, `partial` while some active nodes are behind, and `ready` when all active DNS nodes have converged. Offline nodes are excluded and catch up when they return. Resolvers fall back to the remaining nameserver in the meantime.

### Hardening

Authoritative-only (no recursion), zone transfers disabled (nothing uses them), response rate limiting, and systemd sandboxing for `pdns`. The Slice 7 security review covers DNS. DNSSEC is a v0.2 item (§38).

### Verification

DNS verification for customer domains (ownership and routing) is performed by the control plane using public resolvers, not by the agent (§10).

### Scale

The target is thousands of zones and tens of thousands of records per node. Beyond that, revisit the backend (LMDB) or replication (`ixfrdist`).

---

# 16. SSL

Use ACME/Let's Encrypt rather than building a certificate authority system.

Certificate model:

```text
Certificate
    │
    ├── domains
    ├── issuer
    ├── expires_at
    └── status
```

The agent handles:

- Certificate issuance
- Certificate renewal
- Certificate installation
- Service reload

Automatic renewal is part of the MVP.

### Two kinds of certificates

**1. Shared wildcard certificate for the temporary zone.**

- Issued and renewed by the control plane using DNS-01. The challenge TXT record is published as ordinary DNS desired state, and validation waits until every active DNS node has converged (§15).
- Delivered to every node as a desired-state resource over the mTLS channel and installed root-only.
- The private key therefore exists on every node. This is an accepted trade-off, mitigated by using a dedicated temporary domain that is on the Public Suffix List and holds no customer credentials. The key is stored encrypted on the control plane and never logged.
- Per-site certificates on temporary hostnames are rejected because they would exhaust the per-registered-domain issuance limits.

**2. Per-domain certificates for customer domains.**

- The private key is generated on the node by the agent.
- Validation is HTTP-01 in v0.1.
- **Issuance is gated.** The agent requests a certificate only when desired state says `certificate.issue_allowed` (§10). Let's Encrypt enforces limits on authorization failures per identifier and on certificates per registered domain (at time of writing: 5 failures per identifier per hour and 50 certificates per registered domain per 7 days, so check the current documentation), and repeated failures can eventually pause issuance for an identifier. Speculative attempts are therefore forbidden.
- The agent honors `Retry-After` and backs off. An ACME rate-limit response is a `waiting` condition, not a failure.
- Renewal happens well before expiry. A failing renewal reports `degraded` and alerts administrators, while the existing certificate keeps serving until it expires.

---

# 17. Billing Domain

Billing is a first-class platform feature.

Initial entities:

```text
products
plans
prices
subscriptions
invoices
invoice_items
payments
refunds        (manual records only in v0.1)
tax_rates      (minimal in v0.1)
```

Conceptually:

```text
Product
   │
   └── Price
         │
         └── Subscription
                │
                ├── Invoice
                │     └── Invoice Items
                │
                └── Resources
```

### v0.1 billing scope

| Item                    | v0.1    | Notes                                                       |
|-------------------------|---------|-------------------------------------------------------------|
| products, plans, prices | Yes     |                                                             |
| subscriptions           | Yes     | Lifecycle per §19                                           |
| invoices, invoice_items | Yes     |                                                             |
| payments                | Yes     | Stripe and manual                                           |
| tax_rates               | Minimal | See below                                                   |
| refunds                 | Manual  | Issue in Stripe, record a manual entry. No refund workflow. |
| credits, coupons        | No      | v0.2 (§38)                                                  |

### Minimal tax

Store customer country and an optional VAT ID. Apply a single configured rate per country at invoice time, and record the rate and computed tax on the invoice. No automated OSS reporting and no rate-by-product-category. Invoice numbering must be sequential and gap-free. Invoice legal requirements must be reviewed by an accountant before public beta.

---

# 18. Payment Providers

Payment providers are integrations, not the billing database.

Initial abstraction:

```text
PaymentProvider
```

Initial implementations:

- Stripe
- Manual payments

Future providers can include:

- PayPal
- Viva Wallet
- Adyen
- Mollie

The platform owns:

- Customers
- Products
- Prices
- Subscriptions
- Invoices
- Balances
- Resources

Stripe handles payment processing and related payment events.

---

# 19. Subscription Lifecycle

Example:

```text
ACTIVE
  │
  │ payment failure
  ▼
PAST_DUE
  │
  │ grace period expires
  ▼
SUSPENDED
  │
  │ retention period expires
  ▼
TERMINATED
```

Suspension must be distinct from termination.

Payment failure must not immediately destroy customer data.

Mapping to desired state (§6, §8): `PAST_DUE` changes nothing on the node. `SUSPENDED` sets `suspended: true`. `TERMINATED`, after the retention period, creates the tombstone with `destroy_after`, which starts the quarantine sequence.

---

# 20. Billing -> Provisioning

The core automated workflow is:

```text
Customer selects product
        │
        ▼
Checkout
        │
        ▼
Payment successful
        │
        ▼
Subscription ACTIVE
        │
        ▼
Provisioning job
        │
        ▼
Scheduler
        │
        ▼
Node selected
        │
        ▼
Website provisioned
        │
        ▼
Temporary hostname + TLS
        │
        ▼
READY
```

This should be one of the defining experiences of the MVP.

`READY` means the website is live on its temporary hostname. If the customer supplied a domain at checkout, its domain lifecycle (§10) starts in parallel and does not delay `READY`.

---

# 21. Audit System

Audit logging is a first-class feature.

Initial model:

```text
audit_events

actor_type
actor_id
action
resource
resource_id
metadata
ip_address
user_agent
request_id
created_at
```

- `actor_type` is one of `administrator`, `user`, `node`, `system`. `actor_id` and `resource_id` are nullable.
- `request_id` (UUID, nullable) ties an event to one HTTP request. `user_agent` (at most 255 characters, nullable) is stored next to `ip_address`.
- Events hold **identifiers, never copies of personal data or secrets**.
- A failed login for an unknown address stores a keyed hash of the submitted address, not the address.
- **Retention:** events are kept for 24 months, `ip_address` and `user_agent` for 90 days, after which the two are cleared together. Both periods are defaults and configurable.
- Reconcile attempts are recorded in `job_history` (§6), not in `audit_events`. The audit log records state changes and their cause.

The system should be able to answer questions such as:

- Who deleted this website?
- Who changed this subscription?
- Why was this account suspended?
- What changed on this node?
- Which administrator initiated a provisioning operation?

---

# 22. Event Architecture

Use internal domain events.

Examples:

```text
SubscriptionCreated
PaymentSucceeded
PaymentFailed

WebsiteCreated
WebsiteProvisioningStarted
WebsiteProvisioned
WebsiteProvisioningFailed

NodeRegistered
NodeOffline
NodeRecovered

CertificateIssued
CertificateRenewalFailed
```

For v0.1, Laravel queues and Redis are sufficient.

No Kafka, NATS, or other distributed event infrastructure is required initially.

Provisioning events such as `WebsiteProvisioned` and `WebsiteProvisioningFailed` are emitted by the control plane when it receives the corresponding status transition from the agent.

Domain events: `DomainOwnershipVerified`, `DomainActivated` and `DomainVerificationExpired`. They drive customer notifications.

---

# 23. Database

Use:

```text
PostgreSQL
```

for the control plane.

The control-plane database is authoritative for platform state.

Node-local databases are separate.

Example:

```text
Control Plane
    PostgreSQL

Hosting Node
    MariaDB
```

---

# 24. Redis

Redis is used for:

- Laravel queues
- Caching
- Rate limiting

Redis is not the source of truth and is never used for correctness: no locks and no provisioning state.

PostgreSQL remains authoritative.

Scheduler correctness relies only on PostgreSQL (§12).

---

# 25. Laravel Structure

The application should be organized by domain rather than becoming a large collection of generic models and services.

Suggested structure:

```text
app/
├── Domain/
│   ├── Identity/
│   ├── Customers/
│   ├── Infrastructure/
│   ├── Hosting/
│   ├── DNS/
│   ├── Certificates/
│   ├── Billing/
│   ├── Provisioning/
│   ├── Monitoring/
│   └── Audit/
│
├── Application/
│   ├── Commands/
│   ├── Queries/
│   └── Services/
│
├── Infrastructure/
│   ├── Persistence/
│   ├── Payments/
│   ├── NodeAgents/
│   └── Notifications/
│
├── Http/
│   └── Api/
│       └── V1/
│           ├── Admin/       # admin endpoints
│           └── Customer/    # customer endpoints
│
└── Console/                 # console commands: an entry point like Http
```

Console commands, like controllers, hold no business logic: they call the application layer, and nothing depends on them.

The Laravel application serves no application UI. All screens are provided by the separate Vue apps in `web/` (§29).

---

# 26. Python Agent Structure

Suggested structure:

```text
agent/
├── agent/
│   ├── client/          # outbound HTTPS long-poll client (no inbound listener)
│   ├── auth/
│   ├── jobs/
│   ├── reconciliation/
│   ├── services/
│   │   ├── nginx/
│   │   ├── php/
│   │   ├── mariadb/
│   │   ├── dns/           # PowerDNS reconciler (localhost API)
│   │   ├── ssh/
│   │   ├── cron/
│   │   ├── logs/
│   │   ├── ssl/
│   │   ├── users/
│   │   ├── filesystem/
│   │   └── systemd/
│   ├── resources/
│   ├── monitoring/
│   └── security/
│
├── tests/
│   ├── unit/
│   ├── integration/      # real Debian 13 VM / system container
│   └── contracts/
└── pyproject.toml
```

Linux operations should be isolated behind interfaces so implementations can later be replaced.

---

# 27. Repository Structure

Use a monorepo initially:

```text
hosting-platform/
│
├── control-plane/
│   └── Laravel
│
├── agent/
│   └── Python
│
├── web/
│   ├── apps/
│   │   ├── admin/          # Vue 3 admin app
│   │   └── portal/         # Vue 3 customer portal (themable)
│   ├── packages/
│   │   ├── ui/             # shared components and design tokens
│   │   └── api-client/     # TypeScript client generated from OpenAPI
│   ├── e2e/                # Playwright end-to-end tests (decision 0016)
│   └── themes/             # optional operator themes, selected with THEME=<name>
│
├── contracts/
│   ├── agent-protocol/     # versioned JSON Schemas, shared by both test suites
│   └── api/                # generated OpenAPI spec (committed)
│
├── infrastructure/
│   ├── docker/
│   ├── development/
│   ├── testing/
│   ├── nginx/              # example configuration for the four hostnames
│   └── hygiene/            # license scan, third-party notices, SPDX check
│
├── docs/
│
└── .github/
```

Repositories can be split later if there is a concrete reason.

---

# 28. API

Version the public API from the beginning:

```text
/api/v1/
```

The API has two route groups, one per session realm: `/api/v1/admin` for the admin app and `/api/v1/customer` for the portal. The server must know the group from the path before it can pick the session cookie. Public endpoints stay unprefixed.

Examples:

```http
GET    /api/v1/admin/nodes
POST   /api/v1/admin/nodes

POST   /api/v1/admin/websites/{website}/suspend
POST   /api/v1/admin/websites/{website}/unsuspend

GET    /api/v1/customer/websites
POST   /api/v1/customer/websites
GET    /api/v1/customer/websites/{website}

GET    /api/v1/customer/subscriptions
POST   /api/v1/customer/subscriptions

GET    /api/v1/customer/invoices

GET    /api/v1/branding
```

The API is the only interface to the application layer. The Vue apps consume it like any other client, with no privileged path.

`GET /api/v1/health` is a public liveness endpoint. It needs no session, reports no detail and answers `{"status":"ok"}` while the application can serve requests.

### OpenAPI and typed client

The OpenAPI specification is generated from the Laravel code (for example with Scramble) and committed to `contracts/api/`. A TypeScript client is generated from it into `web/packages/api-client/`. CI regenerates both and fails if either is stale. This is internal tooling for the Vue apps; public SDKs remain out of scope (§32).

### Hostname layout

| Host             | Serves                                                        |
|------------------|---------------------------------------------------------------|
| `app.<domain>`   | Customer portal (static build)                                |
| `admin.<domain>` | Admin app (static build)                                      |
| `api.<domain>`   | Laravel API                                                   |
| `agent.<domain>` | Agent mTLS endpoint, on its own hostname and not proxied (§5) |

The temporary-hostname domain (§10) is a separate dedicated domain and is never under the brand domain. A single host with path prefixes was rejected: it is simpler to install, but a stored XSS in the customer-facing portal would then share an origin with the admin app. Separate origins are cheap defense in depth.

The apps take the API origin from the hostname they are served on: `admin.<domain>` and `app.<domain>` call `https://api.<domain>`. No address is built into an app.

### Client authentication

- The Vue apps use Laravel Sanctum's cookie-based SPA authentication. `app`, `admin` and `api` share a registrable domain. CORS allows exactly the two SPA origins, and the CSRF cookie flow is used.
- **Admin and portal sessions are isolated.** Each uses its own guard and its own session cookie name per route group. Admin requires two-factor authentication and has a shorter session lifetime.
- A request without an `Origin` header is refused in both groups until customer API tokens add a bearer path.
- Customers' programmatic access uses personal API tokens with scoped abilities.
- Two-factor authentication flows are part of the API.
- Both static apps ship a strict Content Security Policy with `connect-src` limited to the API host. `img-src` also names the API host, because it serves the branding images.

### Branding

`GET /api/v1/branding` is public and cacheable. It returns the product name, light and dark logos, favicon, brand colors, and support and legal links. An admin endpoint and admin screen edit it. The same record feeds emails and invoices. Colors are validated and a contrast warning is returned on save.

- **Colors:** `primary` and `primary_foreground`, each for light and dark, as `#rrggbb`. Null keeps the token default.
- **Links:** `support`, `terms`, `privacy`; `https` only.
- **Images:** raster only. Logos are PNG, JPEG or WebP, at most 1 MB and 2000×2000. The favicon is a square PNG, at most 256 KB and 512×512. SVG waits for a sanitization decision.
- **Contrast warning:** per mode, the foreground on the primary, and the primary on the default background and card, against WCAG AA 4.5:1. A failure is reported and never blocks the save.
- **Serving:** images are served by the API (`GET /api/v1/branding/images/{slot}?v=<version>`), not from a public directory.
- **Endpoints:** `GET` and `PUT /api/v1/admin/branding` (JSON), `POST` and `DELETE /api/v1/admin/branding/images/{slot}`.
- **Caching:** `Cache-Control: public, max-age=300`, an `ETag`, and `304` on `If-None-Match`.
- **Permissions:** `branding.view`, `branding.update`.

### Authorization

Authorization is enforced server-side by policies. Responses include the abilities the current user has on each resource, so the frontend reflects permissions without duplicating the rules.

- The admin realm starts with two roles. `owner` may do everything, including managing administrators. `read-only` may view everything and change nothing. Further roles are added in the slice that needs them.
- Roles and permissions are defined in code. Role assignment is data.
- The customer realm has no roles in v0.1. Access follows ownership.

### Live status

In v0.1 the frontend follows provisioning progress by polling (or server-sent events). WebSockets are a v0.2 item (§38).

---

# 29. User Interfaces

Laravel exposes only the API. The user interfaces are two separate **Vue 3** single-page applications that consume it like any other client.

## Customer Portal

```text
Dashboard
Websites
Domains
Databases
DNS
SSL
Backups
Billing
Invoices
Account
API
```

## Administrator Portal

```text
Dashboard
Customers
Products
Subscriptions
Invoices
Nodes
Clusters
Services
Provisioning
Jobs
Monitoring
System
Audit
Settings
```

A reseller portal is not part of v0.1.

### Frontend architecture

- **Stack:** Vue 3, TypeScript, Vite, Pinia, Vue Router and Tailwind v4, with shadcn-vue components (Reka UI primitives), TanStack Table, vee-validate with Zod, and Sonner.
- **Two apps** in `web/apps/`: `admin` and `portal`. They share components and design tokens from `web/packages/ui/`. Both are static builds, served independently of the Laravel application.
- **No privileged path.** Both apps use only the public REST API, through the generated client (§28).
- **No business rules in the frontend.** Presentation and input ergonomics only. Permissions come from the abilities the API reports.
- **Themability is a design goal.** Operators customize the customer portal by working only in the Vue app, in three layers (below). The admin app stays stock.

### Component library

The UI uses **shadcn-vue** on Reka UI and Tailwind. Components are installed into the repository, in `web/packages/ui`, so the project owns the component code rather than depending on a bundled library. Components use semantic token pairs (surface and text colors, primary, radius) that are overridden in CSS to change the look. Batteries-included kits such as PrimeVue were rejected because they bring a second theming system on top of Tailwind. The trade-off is more assembly work and following upstream Reka UI changes ourselves.

### Theming

1. **Tokens.** Semantic CSS variables live in `web/packages/ui`. Dark mode overrides the same tokens under a `.dark` selector, and the default follows the system setting.
2. **Branding configuration.** The API serves branding (§28). The portal applies brand colors as runtime token overrides, so a basic rebrand needs no rebuild.
3. **Theme folder.** `web/themes/<name>/` contains a `tokens.css` and optional replacements for a small, documented list of extension points: `AppLogo`, `AppFooter`, `LoginLayout` and `DashboardWelcome`. It is selected at build time with `THEME=<name>`, and a Vite alias falls back to the core component when a theme does not provide one. Operators never edit core files, so upgrades merge cleanly.

The extension-point list is a versioned contract, and changes are recorded in the changelog. The admin app uses tokens only for light and dark mode, plus the product name and logo from branding. Installable theme packages are a v0.2 item (§38).

---

# 30. Technology Stack

| Layer               | v0.1                                                             |
|---------------------|------------------------------------------------------------------|
| Control plane       | Laravel 13                                                       |
| Admin/customer UI   | Vue 3 + TypeScript + Vite + Tailwind v4 (two SPAs)               |
| UI components       | shadcn-vue (Reka UI), TanStack Table, vee-validate + Zod, Sonner |
| Database            | PostgreSQL                                                       |
| Queue/cache         | Redis                                                            |
| API                 | Laravel API (Sanctum auth), OpenAPI generated                    |
| Agent               | Python                                                           |
| Agent framework     | Python + httpx (asyncio)                                         |
| Agent communication | HTTPS long-poll, agent-initiated                                 |
| Authentication      | mTLS (built-in CA, short-lived certs) + signed payloads          |
| Web server          | Nginx                                                            |
| PHP                 | PHP-FPM                                                          |
| Database server     | MariaDB                                                          |
| DNS                 | PowerDNS                                                         |
| SSL                 | ACME / Let's Encrypt                                             |
| Isolation           | systemd + cgroups v2                                             |
| Payments            | Stripe                                                           |
| Jobs                | Laravel Queue                                                    |
| Documentation       | Docusaurus                                                       |
| CI                  | GitHub Actions                                                   |
| Control plane tests | Pest 5 (TDD)                                                     |
| Agent tests         | pytest (TDD)                                                     |
| Frontend tests      | Vitest + Playwright (TDD)                                        |
| API client          | TypeScript client generated from OpenAPI                         |

The stack is intentionally conservative. The goal is reliability and maintainability rather than technological novelty.

---

# 31. MVP Scope

## Included in v0.1

### Control Plane

- Laravel 13
- Vue 3 admin app and customer portal (separate SPAs)
- Branding configuration (API and admin screen)
- Generated OpenAPI spec and typed TypeScript client
- PostgreSQL
- Redis
- Authentication
- Authorization / RBAC
- Customers
- Users
- API
- Audit logging

### Infrastructure

- Node registration
- Node authentication (mTLS)
- Node enrollment and certificate rotation
- Node heartbeat
- Node capabilities
- Node health
- Node resource reporting

### Agent

- Debian 13
- Python
- Linux users
- Filesystem
- Nginx
- PHP-FPM
- MariaDB
- systemd
- cgroups/resource limits
- Sandboxing baseline (§13)
- Health reporting

### Hosting

- Websites
- Domains (hosted and external modes, ownership verification)
- Databases
- Database users
- DNS (temporary zone and hosted zones on dedicated DNS nodes)
- SSL (shared wildcard for temporary hostnames, per-domain ACME)
- PHP versions (from a pinned Sury repository)
- Per-site PHP settings
- SFTP and key-only SSH (shell as an optional plan feature)
- Cron jobs
- Logs (rotation and portal viewer)
- Suspend
- Unsuspend
- Delete

### Provisioning

- Create
- Update
- Suspend
- Unsuspend
- Delete
- Retry
- Job history
- Condition-based state machines (create, update, suspend, delete, detach domain)
- Quarantine on delete
- Idempotent operations

### Billing

- Products
- Plans
- Prices
- Customers
- Subscriptions
- Invoices
- Minimal tax (per-country rate)
- Stripe
- Manual payments
- Payment webhooks
- Payment failure handling
- Provisioning integration

---

# 32. Explicit Non-Goals for v0.1

The following are intentionally excluded:

```text
Email hosting
VPS management
Proxmox integration
Kubernetes
Docker hosting
Reseller system
Domain registrar integration
Marketplace
Apache
OpenLiteSpeed
Multiple Linux distributions
Multi-region orchestration
High-availability control plane
Advanced backup platform
CDN
Object storage
Built-in support/helpdesk system
Credits and coupons
Automated refund workflow
Public SDKs and published API client libraries (the internal generated TypeScript client is in scope)
Live log tailing / web terminal
```

These may become future features, but they must not expand the v0.1 scope.

---

# 33. Development Milestones

The roadmap is a sequence of **vertical slices**, not horizontal phases. It assumes **one full-time developer** and has no fixed deadline. All slices are developed test-first (§37).

### Principles

1. Every slice from Slice 1 on ends with an automated end-to-end test that runs against **two nodes**, so multi-node behavior is exercised from the start. Slice 0 builds the two test nodes and proves them with the harness's own integration tests; its exit test is a login through the Vue apps.
2. **UI ships with the slice that needs it.** The admin UI stays deliberately thin (generated-client CRUD screens). Portal theming and polish land in the last slice.
3. **Backups come before any real customer data, and billing before the public.**
4. **Open spec items are settled just before the slice that needs them** (§39).
5. **Slice scope is frozen once a slice starts.** New ideas go to the v0.2 backlog (§38).

### Gates

- **Alpha:** design partners host non-critical real sites. No payments. Backups and restore verified.
- **Beta:** public v0.1.

---

## Slice 0 - Foundations

- CI, the Pest and pytest harnesses (§37)
- Two Debian test nodes (both also carry the `dns` capability in tests), a local ACME test server, PostgreSQL in tests
- OpenAPI generation and typed API client pipeline
- Vue workspace: auth shell for admin and portal, design tokens, component library (shadcn-vue)
- Branding configuration (public endpoint and admin screen)
- Hostname layout and admin/portal session isolation
- Authentication, RBAC, audit logging, job infrastructure

Exit:

> The whole pipeline is green end to end, including a login through the Vue shell.

---

## Slice 1 - Walking skeleton

- Node enrollment (bootstrap token, built-in CA, mTLS)
- Long-poll protocol, signed payloads, `schema_version`
- Generations and observed state with a trivial resource
- Admin UI: nodes (enrollment and health)

Exit:

> Two nodes enroll. A desired-state change reaches the right node and its status returns. A revoked node is rejected. A node keeps its state while the control plane is down.

---

## Slice 2 - Website lifecycle

- Linux user, storage, PHP-FPM pool, Nginx, MariaDB
- Sandbox baseline (§13): account slice, one PHP-FPM master per account and PHP version, unit sandboxing, user quotas, egress rule
- DNS nodes: PowerDNS reconciled by the agent, and the temporary zone (§15)
- Temporary hostname with the shared wildcard certificate, issued through the DNS-01 convergence flow
- Hosting account as the unit of placement
- Scheduler v0 (§12): transactional reservation across both nodes, spread and binpack strategies, cordon
- Create, update (safe-apply), suspend, unsuspend, delete with quarantine
- Admin UI: websites and databases

Exit:

> An admin creates a website. It lands on the chosen node and serves HTTPS on its temporary hostname. A failed update leaves it serving as `degraded`. Delete quarantines the data.

---

## Slice 3 - Domains and TLS

- Hosted customer zones with record editing, and external DNS mode
- Ownership and routing verification, waiting schedule, expiry
- ACME gating and per-domain certificates
- Detach domain
- Portal UI: shell, websites, domains

Exit:

> A domain reaches `ACTIVE` in both modes, and the expiry path works.

---

## Slice 4 - Hosting essentials

- SFTP for every account (chrooted), key-only SSH, shell as an optional plan feature
- Cron jobs as systemd timers in the account slice
- Logs: root-owned directory, read-only bind mount, rotation, portal viewer
- Per-site PHP settings and curated extensions
- Portal UI for the above

Exit:

> A customer deploys a typical PHP application over SFTP, runs cron jobs, and reads logs. The isolation test suite passes.

---

## Slice 5 - Backup and restore

- Scheduled backups of site files and databases
- Retention
- Restore to the same or another node
- A restore drill running in CI
- Portal and admin UI for backups

Exit:

> A site is destroyed and restored to an identical state.

**Alpha gate.**

---

## Slice 6 - Billing and self-service

- Products, plans, prices
- Subscription lifecycle (§19) mapped to desired state
- Plan changes resize the account's reservation (§12)
- Invoices and minimal tax
- Manual payments first, then Stripe with webhooks and payment-failure handling
- Checkout integrated with provisioning
- Portal UI: checkout, subscriptions, invoices. Admin UI: products, plans, subscriptions, invoices

Exit:

> The full workflow in §34 runs automated end to end.

---

## Slice 7 - Beta hardening

- Security review and fixes
- Failure-recovery tests: agent killed mid-reconcile, network partitions, control-plane outage
- Agent upgrade mechanism
- Monitoring
- Documentation and API documentation
- Installer
- Theming guide and theme-folder extension points (`web/themes/`)

Exit:

> A clean installation on fresh servers succeeds from the documentation alone, and all security review findings are closed.

**Beta gate.**

---

## Planning calendar

The figures plan on the **upper end** of each estimate, with a stabilization buffer after each gate. They are rough guidance, not commitments. Recalibrate after Slice 1 using measured throughput.

| Slice                      | Planned weeks  | Cumulative                           |
|----------------------------|----------------|--------------------------------------|
| 0 Foundations              | 3              | 3                                    |
| 1 Walking skeleton         | 4              | 7                                    |
| 2 Website lifecycle        | 7              | 14                                   |
| 3 Domains and TLS          | 4              | 18                                   |
| 4 Hosting essentials       | 5              | 23                                   |
| 5 Backup and restore       | 4              | 27                                   |
| Alpha stabilization        | 2              | **29 (Alpha, about 7 months)**       |
| 6 Billing and self-service | 8              | 37                                   |
| 7 Beta hardening           | 6              | 43                                   |
| Beta stabilization         | 2              | **45 (Beta, about 10 to 11 months)** |

---

# 34. The Critical End-to-End Workflow

The first complete product demonstration should be:

```text
                         ADMIN
                           │
                           ▼
                Create "Business Hosting"
                           │
                           ▼
                     Add 3 Linux Nodes
                           │
                           ▼
                        CUSTOMER
                           │
                           ▼
                   Purchase Hosting
                           │
                           ▼
                         Stripe
                           │
                           ▼
                       Scheduler
                           │
                           ▼
                    Node Selection
                           │
                           ▼
                     Python Agent
                           │
                  ┌────────┼────────┐
                  ▼        ▼        ▼
                Nginx    PHP-FPM  MariaDB
                  │
                  ▼
        Temporary hostname + TLS
                  │
                  ▼
             WEBSITE LIVE
                  │
                  ▼
      Customer attaches own domain
                  │
                  ▼
    Ownership -> routing -> certificate
                  │
                  ▼
            DOMAIN ACTIVE
```

This workflow is the central acceptance criterion for v0.1. It requires both a website live on its temporary hostname and a customer domain reaching `ACTIVE`, in hosted and in external mode.

Every step is performed through the Vue admin app and customer portal, and is equally available through the API. This workflow is also automated as the top-level end-to-end test and is the release gate (§37).

Slices 2 to 5 automate smaller end-to-end tests (for example an admin-created website with an attached domain). The complete workflow above is first achievable at Slice 6 (§33).

---

# 35. Architectural Non-Negotiables

### 1. Control plane must not directly manipulate Linux

The agent owns server operations.

### 2. Operations must be idempotent

Retries must be safe.

### 3. Provisioning must be stateful

Every operation has a visible state and history.

### 4. Products must not be tied to physical nodes

Infrastructure selection is a scheduler concern.

### 5. Billing must be independent from payment providers

Stripe is an adapter, not the billing database.

### 6. The API is the contract

The application layer (commands and queries) is the single entry point for all behavior, and the versioned REST API is its only external interface. The admin and customer Vue apps are ordinary API clients with no privileged path.

- No business logic in controllers or in the frontend.
- Anything the UI can do, the API can do, by construction.
- Authorization is enforced server-side. The UI only reflects the permissions the API reports.

### 7. PostgreSQL is authoritative

Redis is not the source of truth.

### 8. Multi-server is fundamental

Do not retrofit multi-server support later.

### 9. Desired state is fundamental

The system should reconcile desired and actual infrastructure state.

### 10. v0.1 scope must remain frozen

New ideas should be recorded in the v0.2 backlog (§38) rather than silently added to the MVP.

### 11. Development is test-driven

No production code without a failing test that demands it. See §37.

---

# 36. Long-Term Direction

The architecture should eventually support:

```text
                         PLATFORM
                            │
             ┌──────────────┼────────────────┐
             ▼              ▼                ▼
          Hosting           VPS          Dedicated
             │              │                │
           Linux            VM            Bare Metal
             │              │                │
             └──────────────┼────────────────┘
                            │
                         Billing
                            │
                      Subscriptions
                            │
                         Payments
```

The long-term product is therefore not merely:

> "An open-source cPanel alternative."

It is:

> **An open-source hosting infrastructure, provisioning, automation, and billing platform.**

The durable value is the automation and unified infrastructure model rather than the UI itself.

### Licensing and contributions

- **License:** Apache-2.0 for the whole repository. The name and logos are not licensed by it (see the trademark policy below).
- **Contributions:** accepted under the Developer Certificate of Origin (DCO) with `Signed-off-by` on every commit. No CLA for now. This decision is revisited before the first outside contribution is merged, because a DCO does not grant relicensing rights.
- **Trademark policy:** the Hostmesh name and logos are covered by a published trademark policy, since the license grants no trademark rights and anyone may fork. Package names (Packagist, npm, PyPI, container registries) are reserved.
- **Hygiene:** SPDX identifiers (`SPDX-License-Identifier: Apache-2.0`) in source files, a `NOTICE` file, and a license scan of dependencies in CI.
- **Business model, for now:** revenue from support, custom work and add-ons on an openly licensed core. This is a decision for the current stage and may be revisited. Releases already published stay under Apache-2.0.

Add-ons are only practical if the platform has documented extension points for backend modules. v0.1 defines theming extension points only (§29). A module mechanism is in the v0.2 backlog (§38) so the architecture does not close it off.

---

# 37. Development Methodology: Test-Driven Development

Hostmesh is developed **test-first**: red, green, refactor. A failing test is written before the code that satisfies it, and every bug fix begins with a test that reproduces the bug.

### Tooling

| Component               | Framework                                                               |
|-------------------------|-------------------------------------------------------------------------|
| Control plane (Laravel) | **Pest 5**                                                              |
| Node agent (Python)     | **pytest**, with pytest-asyncio, respx (httpx mocking) and Hypothesis   |
| Frontend (Vue)          | **Vitest** (components, stores) and **Playwright** (browser end-to-end) |

### Control plane practices

- **Real PostgreSQL in tests**, not SQLite. Row locks, advisory locks and JSON columns must behave as in production. Redis is used for queue tests.
- **Application-layer and API tests.** Commands and queries are tested directly. API endpoint tests are the contract for the frontend: the Vue apps are ordinary API clients, so anything they do is covered by an API test first.
- **Fakes at the boundaries:** a `FakePaymentProvider` implementing `PaymentProvider`, and an in-memory `FakeNodeAgent` that simulates polling, generations and status reports.
- **Stripe webhooks** are tested with recorded fixtures, including duplicate and out-of-order delivery.
- **Architecture tests** (`arch()`): the Domain layer must not depend on Http or Infrastructure, and controllers must not contain business logic or write to the database directly.
- **Time control** for grace periods, retention periods and renewals.
- **Domain lifecycle:** ownership and routing checks against a fake DNS resolver; claim rules (first verified wins, pending claims never serve); deadline expiry; no issuance request before `issue_allowed`.
- **Session isolation:** a portal session cannot reach admin endpoints and vice versa, CORS allows only the two SPA origins, and admin routes require two-factor authentication.
- **Branding:** input and contrast validation, and the public endpoint's caching headers.
- **Scheduler:** a concurrency test with N parallel placements against capacity for K accounts, where exactly K succeed and none exceed `allocatable`; release symmetry; plan-change deltas; stickiness on retry; deterministic scoring for both strategies; guardrails and cordon behavior; and the nightly invariant check.
- **DNS validation:** record syntax, CNAME exclusivity, protected `managed` records, apex NS and SOA protection, and the per-zone record limit.
- **Hosting essentials validation:** PHP setting bounds against the plan and account limits; plan feature gating (`shell_access`, cron and key limits); cron expression validation and minimum interval; SSH key type and size checks.
- **Mutation testing** on Billing, Scheduler and Provisioning.

### Agent practices

- Every Linux operation sits behind an interface (§26). Unit tests run reconcilers against fakes, so the reconciliation logic is testable without touching a real system.
- Every `ensure_*` operation must have tests for: create; no-op when already converged; repair of drift; applying twice yields the same state; and an interrupted run followed by a re-run converges. Use Hypothesis property tests for idempotency where feasible.
- **Golden-file tests** for rendered Nginx, PHP-FPM and systemd configuration.
- **Client tests** with respx: long-poll behavior, retry and backoff, certificate renewal, rejected signatures, revoked node, and unsupported `schema_version`.
- **Input validation tests** with hostile values (domain names, paths, PHP settings). Injection attempts must be rejected by the typed schemas.
- **Operation machines:** create resumes correctly after a partial failure; a failed update leaves the last-known-good configuration serving, reports `degraded` and does not advance `observed_generation`; suspend and unsuspend are reversible; delete refuses destructive steps without `destroy_after`, quarantines data, and purges on schedule.
- **Failure classes:** retryable errors back off and cap; `waiting` never counts toward the attempt limit; terminal errors fail immediately.
- **ACME tests** run against a local test ACME server (for example Pebble) or Let's Encrypt staging, never production.
- **DNS reconciler** against a real local PowerDNS: rrset idempotency, drift repair, identical SOA serials across two DNS nodes, the DNS-01 wait-for-convergence logic (including an offline node), and publication status transitions.
- **Isolation suite** on a real Debian VM: account A cannot read account B's files through PHP, cron or SFTP; cannot see other accounts' processes; has its own private `/tmp`; the file quota holds; memory and task limits contain a runaway process to the account slice; outbound TCP port 25 is blocked; the log symlink attack cannot redirect log writes; SFTP chroot escape attempts fail; password authentication is refused; customers cannot modify `authorized_keys`.
- **Cron:** property tests that the cron-to-timer translation fires at the same times as the cron expression over a long window; no-overlap behavior; the runtime cap; failed-run reporting.
- **Integration tests** run against a real Debian 13 VM or system container with systemd and cgroups v2, exercising the real services. Run them at least nightly and before release, and per pull request where CI allows.

### Frontend practices

- Components, stores and composables are developed test-first with Vitest. API calls are mocked against types generated from the OpenAPI spec, so a contract change breaks the build.
- Playwright covers critical flows against the real API (with fakes for Stripe and the agent): login and two-factor, node enrollment, website provisioning progress, checkout, and suspension on payment failure. It runs against the built apps, served with the production security headers, and a database of its own. Test data comes from a console command that exists only in the end-to-end and test environments, never in production (decision 0016).
- Themability is tested: the portal renders correctly with a non-default token set and branding configuration, and theme-folder overrides resolve with fallback to the core components.

### Cross-cutting

- **Contract tests:** the JSON Schemas in `contracts/agent-protocol/` are validated by both test suites. An incompatible change requires a `schema_version` bump.
- **OpenAPI contract:** the spec is generated from Laravel and committed to `contracts/api/`. CI regenerates the spec and the TypeScript client and fails on any diff, so the backend and both Vue apps cannot drift.
- **End-to-end:** the §34 workflow is automated (fake Stripe, real agent on a Debian VM or container, driven through the Vue apps with Playwright) and is the release gate for v0.1.
- **CI (GitHub Actions):** a pull request cannot merge with failing tests. The coverage target is 95% for the control plane, for the agent and for the web workspace, enforced in CI and to be revisited after beta. In the web workspace it holds for statements, branches, functions and lines alike, and the components generated by the shadcn-vue CLI are not measured.

---

# 38. v0.2 Backlog

Ideas recorded here do not enter v0.1 scope (non-negotiable 10).

- Credits and coupons
- Automated refund workflow
- Tax: rate-by-category and OSS reporting automation
- Public SDKs (the internal generated TypeScript client is in v0.1 scope)
- Live log tailing and web terminal (agent-initiated outbound WebSocket)
- Alternative `NodeCertificateAuthority` implementations (for example step-ca)
- CA hardening: offline root with an online intermediate
- Realtime status updates over WebSocket (for example Laravel Reverb); v0.1 uses polling or SSE
- Advanced portal theming (installable theme packages, component overrides)
- Domains behind a proxy or CDN (routing-check bypass, delegated DNS-01 via CNAME)
- Node-to-node account migration, built on backup and restore (including how temporary hostnames, domains and certificates move)
- DNSSEC
- Large-scale DNS replication (LMDB backend or `ixfrdist`) beyond thousands of zones per node
- SFTP and SSH password authentication
- Custom Nginx configuration snippets
- Basic authentication, redirect rules and custom error pages
- Namespace-sandboxed interactive shell
- Hard database size quotas
- Remote database access
- Egress connection limits
- WAF and malware scanning
- Add-on mechanism: documented extension points for backend modules, so proprietary or third-party add-ons can be built without forking
- Operator-defined roles with a runtime editor
- Tamper-evident audit ledger, fed from `audit_events` by a single worker

---

# 39. Open Items (Next Revision)

One at revision 11. Design questions that arise during implementation are recorded here and settled before the slice that needs them (§33).

- **Abilities for navigation.** Responses carry the abilities of the current user on each resource (§28). Nothing reports which sections an administrator may open, so an app cannot hide a navigation entry before it has asked for the resource. To settle before the admin app gets its second section (Slice 1).
