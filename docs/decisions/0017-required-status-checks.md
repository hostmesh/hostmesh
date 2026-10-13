# 0017. Required status checks: only jobs that report on every pull request

- Status: accepted
- Date: 2026-10-13
- Issue: Repository conventions: branch protection, required checks, merge rules (#10)

## Context

Spec section 37 says "a pull request cannot merge with failing tests". On GitHub that is a ruleset with required status checks.

A required check must report on every pull request. A workflow with a `paths:` filter does not start on a pull request that touches none of its paths, its jobs never report, and a pull request that requires them waits for good. The follow-ups of #55, #56, #57 and #64 each noted this for their workflow.

Until 2026-10-12 nothing could be required: the repository was private on the Free plan, and the API refused rulesets (#81). The ruleset `main` was created that day, after the repository became public.

## Options considered

1. **Require every pipeline, and remove the path filters.** Every pull request runs every suite, including the two-node integration tests, for a change to one README.
2. **Require every pipeline, and keep the filters behind a gate job.** A change-detection job always runs and reports success for the suites it skips. Correct, and more workflow code to write and to test. Named as a follow-up in #81.
3. **Require only the jobs whose workflow has no path filter**, and leave the filtered pipelines advisory for now.

## Decision

Option 3, as a first step.

- The ruleset `main` requires `Signed-off-by check` (`dco.yml`) and `Hygiene tool checks`, `License scan` and `SPDX identifiers` (`hygiene.yml`), which was written without a path filter for this reason (#81). Since #32 it also requires `End-to-end (Playwright)` (`e2e.yml`, decision 0016).
- Not required, because their workflows are path-filtered: `Pint, Larastan and Pest` (`control-plane.yml`), `Ruff, mypy and pytest` (`agent.yml`), `ESLint, vue-tsc, Vitest and builds` (`web.yml`), `Smoke test` (`dev-environment.yml`), and the four jobs of `integration.yml`.
- A check is added to the ruleset by its job name, with GitHub Actions as its source, and only after its workflow has run on a pull request.
- The same ruleset allows squash merges only, requires a pull request and a linear history, and blocks force pushes and deletion. No approval is required while there is one maintainer, who cannot approve their own pull request.

## Consequences

- A pull request can merge while a filtered pipeline is red. Section 37 is therefore met for the required checks only; for the others it rests on the maintainer reading the checks. This is the gap that option 2 closes.
- The end-to-end suite is required and drives the built apps against the real control plane, so a change that breaks login, two-factor, branding or session isolation cannot merge, whatever the state of the unit pipelines.
- A new workflow that is meant to be required must have no path filter, or a job that always reports.
- A required check that is renamed or misspelled blocks every pull request: the name in the ruleset must equal the job's `name` exactly. This happened once, for a few minutes, when the end-to-end check was added.
