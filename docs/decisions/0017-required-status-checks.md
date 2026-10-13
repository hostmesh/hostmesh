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

## Amendment, 2026-10-13 (issue #105)

Option 2 is adopted, and the gap named under "Consequences" is closed.

- `control-plane.yml`, `agent.yml`, `web.yml`, `dev-environment.yml` and `integration.yml` have no path filter on `pull_request` any more. Each starts with a `changes` job, which lists the files the pull request changes (`git diff --name-only HEAD^1 HEAD` on the merge commit) and asks `.github/scripts/changed.sh` whether any of them is one the suite covers. The suite's jobs run only when it answers `true`. The patterns are the former path filters, plus `.github/scripts/**`.
- Each of the five ends with a gate job named after the workflow: `Control plane`, `Agent`, `Web`, `Development environment` and `Integration tests`. It always runs, and `.github/scripts/gate.sh` passes it only when the change detection succeeded and every suite job has the result the detection asked for: `success` when something changed, `skipped` when nothing did. The ruleset requires the five gates, next to the five checks it required before.
- The gates are required, not the suite jobs, because GitHub counts a skipped job as passing, also when it was skipped because the job it needs failed. With the suite jobs required, a broken change detection would let every pull request through.
- The virtual-machine tests moved to `integration-vm.yml`. They also start when a label is added, and such a run skips the container jobs on purpose: in the same workflow its gate would report success and replace the failure of an earlier run on the same commit.
- `changed.sh` understands a file name and `directory/**` and refuses anything else, so that a pattern cannot match nothing silently. Both scripts have tests (`.github/scripts/test.sh`), which the `Hygiene` workflow runs.

Consequences:

- A pull request cannot merge while any pipeline it touches is red, which is what spec section 37 asks for.
- Every pull request starts six workflows instead of one to three. On a pull request that touches none of a suite's paths, that workflow costs two short jobs and runs no suite.
- A pull request that changes `.github/scripts/` runs every suite.
- A new suite follows the same shape: no path filter, a `changes` job, the suite behind it, a gate to require.
