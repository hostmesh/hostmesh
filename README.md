# Hostmesh

Open-source hosting platform: a control plane, customer and admin apps, and a node agent that provisions websites, databases, DNS and TLS, with billing built in.

> **Status: pre-alpha.** The v0.1 specification is written (see below) and implementation is starting with Slice 0.

## How it fits together

- **Control plane** (Laravel 13): exposes only a versioned REST API. It stores desired state and never manipulates servers directly.
- **Web apps** (Vue 3): a customer portal and an admin app, both plain clients of the API.
- **Node agent** (Python): an outbound-only client on each node. It long-polls for desired state, reconciles it into Nginx, PHP-FPM, MariaDB, PowerDNS and systemd, and reports what it observed.
- **Security model**: mTLS with a built-in CA, short-lived certificates and signed payloads.

## Repository layout

| Path              | Contents                                                                                    |
|-------------------|---------------------------------------------------------------------------------------------|
| `control-plane/`  | Laravel application (created in Slice 0)                                                    |
| `agent/`          | Python node agent (created in Slice 0)                                                      |
| `web/`            | Vue workspace: `apps/admin`, `apps/portal`, `packages/ui`, `packages/api-client`, `themes/` |
| `contracts/`      | `agent-protocol/` JSON Schemas and the generated `api/openapi.json`                         |
| `infrastructure/` | `development/`, `testing/` and `docker/`                                                    |
| `docs/`           | `spec/` (the specification) and `decisions/` (decision records)                             |

Directories marked "created in Slice 0" appear as their issues land.

## Specification

The full specification lives in [`docs/spec/HostMesh_v0.1.md`](docs/spec/HostMesh_v0.1.md). It is a living document with a revision history; see CONTRIBUTING for the change process.

## How development works

- **Test-driven.** Pest 5 for the control plane, pytest for the agent, Vitest and Playwright for the frontend. A failing test comes first.
- **Vertical slices.** Work is organized into eight roadmap slices, tracked as milestones and on the project board.
- **The API is the contract.** The API spec is generated from the code and committed; the TypeScript client is generated from it.

## Roadmap and tracking

Milestones, issues and the project board are on GitHub under this organization.

## License

Licensed under the [Apache License, Version 2.0](LICENSE). Contributions are accepted under the Developer Certificate of Origin (sign off your commits with `git commit -s`). The Hostmesh name and logos are not covered by the license; see the [trademark policy](TRADEMARKS.md).

Third-party software that Hostmesh contains or ships is listed, with its license texts, in [THIRD_PARTY_NOTICES](THIRD_PARTY_NOTICES); the policy for dependencies is in [infrastructure/hygiene/README.md](infrastructure/hygiene/README.md).
