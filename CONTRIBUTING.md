# Contributing to Hostmesh

## Workflow

1. Pick an issue from the current milestone on the project board. Slices are worked in order, and a slice's scope is frozen once it starts.
2. Create a branch named `<issue-number>-<short-slug>` from `main`.
3. **Write the failing test first** (see below), then the implementation, then refactor.
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

## Security

Do not open public issues for vulnerabilities. See `SECURITY.md`.
