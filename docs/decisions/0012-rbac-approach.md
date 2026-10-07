# 0012. RBAC with Laravel policies and roles defined in code

- Status: proposed
- Date: 2026-10-07
- Issue: RBAC: roles, permissions and policies (#26)

## Context

Spec section 28 requires that authorization is enforced server-side by policies and that responses include the abilities the current user has on each resource. Non-negotiable 6 repeats it: the UI only reflects the permissions the API reports. Section 31 lists "Authorization / RBAC" in v0.1.

What the spec does not say: which roles exist, whether an operator can edit roles at runtime, and whether a customer account has several users with different rights. The admin navigation in section 29 has no screen for roles. Resellers are a non-goal (section 32).

Decision 0010 gave the two realms separate models, tables and guards: `Administrator` behind `admin`, `User` behind `customer`. Both use UUID primary keys. So the type of the authenticated model already says which realm a request belongs to.

The two realms need different kinds of rules:

- **Admin:** role based. "May this administrator suspend websites?"
- **Customer:** ownership based. "Does this website belong to this user's customer?" A role table does not answer that. Customer API tokens (section 28) later narrow it further with Sanctum token abilities.

Checked on 2026-10-07:

- Packagist: `spatie/laravel-permission` 8.3.0 (2026-07-03, MIT, PHP ^8.3, Laravel 12 and 13, about 122 million installs). `santigarcor/laratrust` 8.5.5 and `silber/bouncer` 1.0.4 also support Laravel 13.
- Source and docs of `spatie/laravel-permission` at tag 8.3.0.
- `ntoufoudis/gatekeeper`, the local checkout at commit 437cadc (2026-04-20), because issue #26 names it.
- Laravel 13's `Authenticate` middleware calls `shouldUse($guard)`, so inside an `auth:admin` or `auth:customer` group the Gate resolves the user of that realm without extra work.

## Options considered

1. **`spatie/laravel-permission`.** The established choice, and well maintained. What it buys is roles and permissions stored as rows that can be edited at runtime, direct per-user permissions, and a `Gate::before` hook so `can()` works. Against our requirements:
   - It does not write policies or report abilities. Both remain our work with either option.
   - Guards: every role and permission row carries a `guard_name`, and a name must be created once per guard. It finds a model's guard from `auth.guards`, which matches our setup. This is workable, but it namespaces by a string what our two model types already separate.
   - It does nothing for the customer realm, where the rule is ownership.
   - UUIDs: the published migration uses `unsignedBigInteger` for the model key and must be edited by hand in two places.
   - Cache: role and permission data is cached for 24 hours in the default cache store, which is Redis here. Authorization would then read from Redis, against "Redis never for correctness". It can be pointed at the `array` store, at the cost of queries on every request.
   - Tests: roles and permissions must be seeded and the registrar cache reset in each test process (their testing docs describe the chicken-and-egg problem). Pest runs in parallel here.
   - The `HasRoles` trait goes on the models in `App\Domain\Identity`, so the Domain layer takes a package dependency.
   - Five tables, of which v0.1 would use a seeded, read-only copy of what is really a constant.

2. **Laravel policies and gates, permissions and roles defined in code, a small table for role assignment.** Chosen. See the decision.

3. **`ntoufoudis/gatekeeper`.** Not usable today, independent of who wrote it. It is not on Packagist and has no tagged release. It is 728 lines with the last commit on 2026-04-20. Its pivot tables use `unsignedBigInteger` user keys, so they cannot reference our UUID accounts. It registers nothing with the Gate yet, and the `guard` column is stored but not read by `hasPermission()`. Its planned roadmap (ABAC, hierarchy, a Blade UI, a cache layer) is larger than what Hostmesh needs. Adopting it would mean finishing a package inside Slice 0 and putting an unreleased dependency in the authorization path. Its core idea, permissions as code, is what option 2 does without a sync engine.

## Decision

- **Policies are the only place where authorization is decided.** One policy per resource, registered with the Gate. A policy method takes `Administrator|User` and branches on the type: an administrator is checked against a permission, a user against ownership. Controllers call `authorize`; they never inspect roles.
- **Permissions are a backed enum** (for example `website.suspend`). **Roles are defined in code** as a named set of permissions. Neither is a database row. They change by pull request, are checked by Larastan, and need no seeding and no cache.
- **Two roles to start:** `owner` may do everything, including managing administrators. `read-only` may view everything and change nothing. Further roles are added in the slice that first needs them.
- **Role assignment is data:** one table, `administrator_roles` (`administrator_id`, `role`). An administrator may hold several roles. `Administrator::hasPermission(Permission)` is the single seam the policies use.
- **No customer roles in v0.1.** Customer rules are ownership rules in the same policies.
- **Abilities in responses:** every API Resource carries `abilities`, a map from ability name to boolean, computed by the same Gate and policy that guard the endpoints. A contract test asserts for each resource that a reported ability and the matching endpoint agree (true means not 403, false means 403).
- Policy checks must not query per row: the permission set is resolved once per request, and ownership is read from keys that are already loaded.
- **Not built in v0.1:** a role editor, per-user grants and denials, role hierarchy, wildcard permissions, teams, attribute rules beyond ownership, and any caching layer.

## Consequences

- No new dependency. The cost is that the role and permission vocabulary is ours to maintain, and a new role needs a release.
- Tests are plain Pest: a dataset of role by ability for the admin realm and owner versus stranger for the customer realm, built with factories, with no seeding step.
- An administrator's rights can be read from the code, which helps an open-source project whose operators want to audit them.
- Every new resource needs a policy and an `abilities` block. An architecture test should fail when a Resource lacks one, in the way `SecondFactorGateTest` guards routes.
- **Cost of changing our mind.** Low in the direction of option 1: if operators need to edit roles at runtime, `hasPermission()` is reimplemented on top of database rows or `spatie/laravel-permission`, with a migration that copies the code-defined roles into rows. Policies, Resources, the API contract and the tests stay as they are. The reverse move, from a package back to code, would need a data migration out of five tables, which is why starting small is the cheaper mistake.
- **Follow-ups:**
  - The spec names no roles. A `spec-change` issue (#69) adds `owner` and `read-only` to section 28.
  - Operator-defined roles with a runtime editor go to the v0.2 backlog (section 38). If that item is pulled forward, option 1 should be reconsidered.
  - The spec is silent on whether a customer can have several users with different rights. This record assumes not for v0.1.
