# 0006. The OpenAPI specification is generated with Scramble

- Status: accepted
- Date: 2026-10-02
- Issue: Generate the OpenAPI spec from Laravel and commit it (#19)

## Context

The API is the contract (spec sections 28 and 37): the OpenAPI specification is generated from the Laravel code and committed to `contracts/api/`, the TypeScript client is generated from it, and CI fails when either is stale. The spec names Scramble as an example, so the generator still had to be chosen, and the committed file has to be byte-identical wherever it is generated.

Checked on 2026-10-02: `dedoc/scramble` v0.13.47 (MIT, released 2026-09-30) requires `php ^8.1` and `illuminate/contracts ^10.0|^11.0|^12.0|^13.0`, and installs cleanly into the control plane (Laravel 13.34, PHP 8.5). The only new package it brings is `spatie/laravel-package-tools`. It infers the document from routes, controllers, enums and resources, so the code stays the single source.

## Options considered

- **Scramble as a development dependency.** Production carries no generator. PHP loads an attribute's class only when the attribute is instantiated, so the Scramble attributes on controllers would be inert without the package, but only as long as nothing reflects on them. The service provider would need `class_exists()` guards.
- **Scramble as a runtime dependency, with its routes disabled.** No guards, and the attribute classes always exist. The cost: a 0.x package and its service provider load on every request, and `myclabs/deep-copy` and `phpstan/phpdoc-parser` become runtime packages too.
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

## Amendment, 2026-10-20 (issue #160)

Paths were not the only part that followed route order. The document transformer in `AppServiceProvider` now also keeps these stable:

- `components.responses` is sorted by name, as Scramble already sorts the schemas.
- Top-level `tags` are sorted by the weight of their `#[Group]` attribute, then by name. Scramble sorts them by weight only and keeps route order among equals.
- No path or operation gets a `servers` entry of its own. Scramble gives a route bound with `Route::domain()` a server built with the scheme of `APP_URL`; every route is on the API host, under the one relative server.

`OpenApiSpecTest` has a test for each.
