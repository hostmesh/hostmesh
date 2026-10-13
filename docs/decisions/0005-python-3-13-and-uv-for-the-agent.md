# 0005. Python 3.13 and uv for the agent

- Status: accepted
- Date: 2026-10-02
- Issue: Agent skeleton (#14)

## Context

The agent runs on Debian 13 nodes (spec sections 14 and 31) and is developed test-first with pytest (section 37). Its minimum Python version, its dependency and project tool, and the place its development commands run are fixed by `pyproject.toml`, the lockfile and CI, so they have to be chosen once.

Checked on 2026-10-02: Debian 13 (trixie) ships Python 3.13.5 as `python3`. Python 3.13 receives security fixes until October 2029, and Debian 13 has long-term support until June 2030. Issue #14 first named Python 3.12, which Debian 13 does not package.

## Options considered

Python version:

- **3.12.** Not in Debian 13. Every node would need a second interpreter from outside the distribution.
- **3.13.** The interpreter every node already has.
- **3.14.** Not in Debian 13 either.

Project tool:

- **uv.** One tool installs the pinned interpreter, creates the environment, resolves a hashed cross-platform lockfile and runs commands. It reads standard metadata (PEP 621, PEP 735 dependency groups), so nothing in `pyproject.toml` is specific to it, and its GitHub Action caches dependencies in one step.
- **Poetry or PDM.** The same result, more slowly, with no advantage for this project.
- **pip-tools with venv.** Needs separate tooling for the interpreter and the environment.

Where development commands run:

- **In a container**, as decision 0003 chose for PHP and Node. Consistent, but it adds an image to build and maintain for a test suite that needs neither the database nor the proxy.
- **uv on the host.** uv pins the interpreter (`.python-version`) and the dependencies (`uv.lock`) by itself, so the environment is already reproducible.

## Decision

The agent targets Python 3.13: `requires-python = ">=3.13"` and `3.13` in `agent/.python-version`.

uv manages the project and runs on the host. `agent/uv.lock` is committed and commands use `--locked`, so a stale lockfile fails instead of being rewritten. `pyproject.toml` requires uv 0.12, and CI pins one exact version.

The build backend is hatchling. It is needed for the `hostmesh-agent` console script and needs no version ceiling.

Decision 0003 covers PHP and Node only. It does not extend to the agent.

## Consequences

- Developers need uv on the host and nothing else: uv installs Python 3.13 itself.
- Unit and contract tests run on uv's Python 3.13 build, which is newer than Debian's 3.13.5. Fidelity to Debian's own interpreter comes from the integration tests on the Debian 13 test nodes (#16, #17).
- How the agent is installed on a node is decided with the install script (#44). Any standard frontend can build the wheel.
- Raising the minimum Python version is a new decision, tied to the Debian release the nodes run.

## Amendment, 2026-10-13 (issue #117)

The three Python projects require uv `>=0.12.18,<0.14`, not 0.12 alone.

Dependabot's updater ships one exact uv version and refuses a project whose `required-version` does not hold it. With `>=0.12.22,<0.13` and Dependabot on 0.12.18, every run of its `uv` job failed and no Python dependency was ever proposed. The lower bound was only the newest release of the day the agent was started. uv 0.12.18, 0.12.24 and 0.13.0 each accept the three committed lockfiles, and a re-lock that has nothing to update leaves them unchanged. When it does update a package, a uv writes the header of the lockfile with its own `revision`: 0.12.18 writes 3 where 0.12.22 writes 5, as Dependabot's first pull request showed (#119). The package entries carry the same fields either way, and CI's uv accepts such a lockfile without rewriting it. The projects' lockfiles can therefore differ in that one number.

- The range is set by what Dependabot ships now and next, not by a feature the projects need. When uv 0.14 is out and Dependabot is about to move to it, the upper bound is raised after the same check: `uv lock --check`, and a re-lock that changes nothing.
- CI still pins one exact version (`UV_VERSION` in the workflows), which must lie in the range. Reproducible builds rest on that pin and on `--locked`, not on the range.
- A lockfile from Dependabot's uv that CI's uv does not accept fails `--locked` on its pull request, where it is seen. A lower `revision` alone is accepted.
