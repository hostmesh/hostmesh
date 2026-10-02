# 0006. The OpenAPI specification is generated with Scramble

- Status: accepted
- Date: 2026-10-02
- Issue: Generate the OpenAPI spec from Laravel and commit it (#19)

## Context

The API is the contract (spec sections 28 and 37): the OpenAPI specification is generated from the Laravel code and committed to `contracts/api/`, the TypeScript client is generated from it, and CI fails when either is stale. The spec names Scramble as an example, so the generator still had to be chosen, and the committed file has to be byte-identical wherever it is generated.

Checked on 2026-10-02: `dedoc/scramble` v0.13.47 (MIT, released 2026-09-30) requires `php ^8.1` and `illuminate/contracts ^10.0|^11.0|^12.0|^13.0`, and installs cleanly into the control plane (Laravel 13.34, PHP 8.5), adding only `spatie/laravel-package-tools`. It infers the document from routes, controllers, enums and resources, so the code stays the single source.

## Options considered

- **Scramble as a development dependency.** Production carries no generator. But controllers carry Scramble attributes, which would then name classes that do not exist in production, and the service provider would need `class_exists()` guards.
- **Scramble as a runtime dependency, with its routes disabled.** One more inert package in production. No guards, and the attribute classes always exist.
- **A hand-written or annotation-only specification.** Rejected by the spec: the contract must be generated from the code.

## Decision

Scramble is a runtime dependency of the control plane (`"dedoc/scramble": "^0.13.47"` in `require`). `composer openapi` (Scramble's `scramble:export`) writes `contracts/api/openapi.json`.

- Its documentation routes (`/docs/api`, `/docs/api.json`) and browser dev tools are switched off. The control plane serves no UI, and a test pins the three URLs at 404.
- The document does not depend on the machine: the server URL is the relative `/api/v1` (Scramble builds an absolute one from `APP_URL`), the title (`Hostmesh API`) and version (`v1`, the URL version) are fixed in `config/scramble.php`, and paths are sorted alphabetically.
- A Pest test fails when the committed file is missing or differs from the generated one. CI regenerates it and fails on any diff.

## Consequences

- Every API change regenerates the specification in the same pull request. A stale file fails locally and in CI.
- Scramble is a 0.x package. `^0.13.47` allows only 0.13 patch releases. An upgrade can change the generated output; the drift test reports it, and the upgrade pull request regenerates the file.
- `info.version` stays `v1` until a `/api/v2` exists. Releases of Hostmesh do not change the contract.
- No security schemes are documented yet. They arrive with authentication.
- If API documentation is ever to be served, that is a new decision.
