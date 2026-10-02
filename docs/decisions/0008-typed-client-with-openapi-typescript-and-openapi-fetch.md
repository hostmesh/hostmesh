# 0008. The typed client is generated with openapi-typescript and openapi-fetch

- Status: accepted
- Date: 2026-10-02
- Issue: Generate the typed TypeScript client (#20)

## Context

Both Vue apps reach the API only through a client generated from `contracts/api/openapi.json` (spec sections 28, 29 and 37). CI regenerates it and fails on any difference, and frontend test mocks are typed from it, so a contract change breaks the build. The generator and the shape of the generated package had to be chosen.

Checked on 2026-10-02: openapi-typescript 7.13.0 (MIT) reads OpenAPI 3.1 and writes one declaration file of types. openapi-fetch 0.17.0 (MIT) is a small wrapper around `fetch` that takes those types. See decision 0007 for the TypeScript 6 peer exception.

## Options considered

- **openapi-typescript with openapi-fetch.** The generated output is types only, with no runtime code to review or to keep in step. Calls name the path and method (`client.GET('/health')`), which the compiler checks against the specification.
- **A generator that writes one function per operation** (for example Hey API or Orval). Call sites read like an SDK, but function names come from operation IDs, so a renamed ID changes every call site, and the generated runtime code is much larger.
- **Hand-written types.** Rejected by the spec: the client must be generated.

## Decision

`pnpm generate:api` runs openapi-typescript over `contracts/api/openapi.json` and writes `web/packages/api-client/src/generated/schema.d.ts`. Only that directory is generated; it is committed and never edited by hand.

`src/index.ts` is authored: `createApiClient(options)` returns an openapi-fetch client typed by the generated `paths`, and the generated types are re-exported. The package reads no environment; the caller passes the base URL.

A type-level test pins the health endpoint's types to the specification, and CI regenerates the client and fails on any difference.

## Consequences

- Every API change regenerates the specification and then the client in the same pull request.
- Operation IDs appear only as keys of the generated `operations` type. Renaming them (issue #62) changes the generated file and no call site.
- openapi-fetch is a 0.x package. Its API is confined to `src/index.ts`, and ESLint forbids importing it in the apps.
- The generator's version decides the output. An upgrade that changes it shows up in the drift check, and the upgrade pull request regenerates the file.
- Public SDKs remain out of scope (spec section 32).
