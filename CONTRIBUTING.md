# Contributing to Hostmesh

## Workflow

1. Pick an issue from the current milestone on the project board. Slices are worked in order, and a slice's scope is frozen once it starts.
2. Create a branch named `<issue-number>-<short-slug>` from `main`.
3. **Write the failing test first** (see below), then the implementation, then refactor. Sign off every commit (`git commit -s`).
4. Open a pull request that links the issue (`Closes #123`). Squash merge is the only merge method.

## Test-driven development

- Control plane: Pest 5. Feature tests through the application layer and API, real PostgreSQL, `arch()` tests for layering.
- Agent: pytest with pytest-asyncio, respx and Hypothesis. Every `ensure_*` operation needs tests for create, converged no-op, drift repair, apply twice, and resume after interruption.
- Frontend: Vitest for components and stores, Playwright for critical flows.
- Bugs start with a test that reproduces them.

## The specification

The spec in `docs/spec/` is the source of truth. If implementation shows the spec is wrong or incomplete:

1. Open an issue with the `spec-change` label describing the problem.
2. Change the spec in the same pull request as the code, and add a row to its revision history.
3. New ideas that are out of scope go to the v0.2 backlog section of the spec, not into the current slice.

## Decisions

Significant decisions are recorded as short documents in `docs/decisions/` (copy `0000-template.md`).

## Commits and pull requests

- Conventional Commits style for the pull request title (`feat:`, `fix:`, `test:`, `docs:`, `chore:`), since squash merges use it.
- The pull request template lists what must be true before merging.

## License and sign-off

- Hostmesh is licensed under Apache-2.0. By contributing, you agree your contribution is licensed under it.
- Every commit must carry a `Signed-off-by: Name <email>` line (use `git commit -s`). It certifies the [Developer Certificate of Origin 1.1](https://developercertificate.org/). A check on pull requests enforces it.
- New source files start with an SPDX identifier, for example `// SPDX-License-Identifier: Apache-2.0` (use the comment style of the language), within the first 10 lines. A check on pull requests enforces it: `make -C infrastructure/hygiene spdx`. Vendor, generated, lock and third-party-derived files are exempt and keep their own header; the exemptions and their reasons are in `infrastructure/hygiene/spdx.toml`. A file that combines Hostmesh's code with MIT code it was derived from says `Apache-2.0 AND MIT` and names the origin.
- Dependencies must carry a license on the allowlist. A check on pull requests enforces it; see `infrastructure/hygiene/README.md` for the list and for how an exception is recorded.
- The name "Hostmesh" and its logos are not licensed by Apache-2.0.

## Security

Do not open public issues for vulnerabilities. See `SECURITY.md`.
