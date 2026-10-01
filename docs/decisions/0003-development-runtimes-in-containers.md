# 0003. Development runtimes run in containers

- Status: accepted
- Date: 2026-10-01
- Issue: Local development environment (#11)

## Context

The local development environment needs PostgreSQL, Redis and a reverse proxy for the production hostname layout (spec section 28). It also has to settle where the PHP and Node runtimes for the control plane and the Vue apps run, because that decides how the proxy reaches them and whether the databases need host ports.

## Options considered

- **Runtimes on the host, Docker for services only.** Fast file watching and the smallest Compose file. Every developer must install matching PHP, extension and Node versions, and PostgreSQL and Redis must be published on host ports, which collide with other local stacks.
- **Runtimes in containers.** Versions and extensions are pinned in the repository and nothing but Docker is needed on the host. Commands run through `docker compose exec`, and images must be built and maintained.

## Decision

PHP and Node run as services in the Compose project in `infrastructure/development/`.

The services are added when there is something to run, not before: the control plane skeleton (#12) adds `php`, and the Vue workspace (#21) adds `node`. Each chooses its image, version and extensions from its real requirements. Until then Caddy serves a placeholder on every hostname.

## Consequences

- PostgreSQL and Redis are not published to the host. Application containers reach them as `postgres:5432` and `redis:6379`.
- When a runtime service lands, its Caddy site block changes from `respond` to `reverse_proxy` with the service name as upstream, and the smoke test's check for that hostname is pointed at a real endpoint.
- Development commands for the control plane and the web workspace are documented as `docker compose exec` invocations.
- CI for the control plane and the web workspace is not bound by this decision. It may run the runtimes directly on the runner.
