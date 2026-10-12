# 0010. Session realms on top of Sanctum

- Status: accepted
- Date: 2026-10-05
- Issue: Authentication: Sanctum SPA auth with isolated admin and portal sessions (#24); Hostname layout: CORS, CSRF and CSP configuration (#30)

## Context

Spec section 28 requires Sanctum's cookie-based SPA authentication, the admin app and the portal on separate origins under one registrable domain, and isolated sessions: a separate guard and a separate session cookie name per route group, with a shorter admin lifetime.

Checked on 2026-10-05 in the source of Sanctum 4.3.3 and Laravel 13.34:

- Sanctum's `EnsureFrontendRequestsAreStateful` reads one global session configuration and matches the request against one list of stateful hosts. Its guard accepts the first guard in `sanctum.guard` that has a user. With two guards it does not isolate them.
- Laravel reads the session cookie name, table and lifetime when the session driver is built, and caches the driver, the `session.store` singleton and the guards.
- Laravel hard-codes the CSRF cookie name `XSRF-TOKEN` and scopes it like the session cookie.
- A browser sends a cookie of the API host on every same-site request, so the admin session cookie also travels on requests made by the portal.

## Options considered

1. **`auth:sanctum` with both guards in `sanctum.guard`.** No isolation: either session satisfies either route group.
2. **Two Laravel applications or two API hostnames.** Real isolation, but it doubles deployment and contradicts the single `api.<domain>` of spec section 28.
3. **One realm per route group, chosen by the Origin header on shared paths.** Matches the spec's unprefixed examples, but there are no route groups left, and the OpenAPI document cannot tell the two APIs apart.
4. **A `realm` middleware per route group that extends Sanctum's stateful middleware.** Chosen.

## Decision

- Route groups `/api/v1/admin` and `/api/v1/customer`, each starting with `realm:<name>` (`App\Http\Middleware\UseSessionRealm`). The middleware refuses any Origin but the realm's own SPA origin with 403, switches `session.cookie`, `session.table`, `session.lifetime` and `sanctum.guard` for the request, forgets the cached session driver and guards, and then runs Sanctum's pipeline.
- Two models and tables, `Administrator` and `User`, each with its own provider and session guard (`admin`, `customer`).
- Session cookies `__Host-hostmesh-admin-session` and `__Host-hostmesh-portal-session`: host-only on the API host, `Secure`, `HttpOnly`, `SameSite=Lax`. Idle lifetimes of 30 and 120 minutes.
- One session table per realm, because garbage collection deletes by the lifetime of the realm that runs it.
- One CSRF cookie per realm (`XSRF-TOKEN-ADMIN`, `XSRF-TOKEN-PORTAL`) on the parent domain, echoed in `X-XSRF-TOKEN`. CSRF verification is not skipped under test.
- `HOSTMESH_DOMAIN` is the only hostname setting. `App\Support\Hostnames` derives the hosts and origins; `HostnameServiceProvider` writes the dependent config.
- The Content Security Policy of the two static apps is defined in `config/hostmesh.php` and sent by the web server.

## Consequences

- Three independent barriers separate the realms: the Origin check, the cookie name and table, and a guard backed by a different table.
- A request without an `Origin` header is refused in both realms. Customer API tokens need a bearer path in the customer realm when they are built.
- The middleware changes configuration per request. That is safe under PHP-FPM. A long-running server (Octane) would need the same reset on every request, which the middleware already performs, but it has not been tested there.
- The route prefixes differ from the unprefixed examples in spec section 28; a `spec-change` issue (#66) records it.
- Resources carry no `abilities` until RBAC (#26). Two-factor authentication (#25) attaches to the admin realm.
- The policy has no `unsafe-inline`. A UI library that needs inline styles will fail visibly in the browser tests (#32) and must be handled there.
