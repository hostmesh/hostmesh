# Hostmesh node agent

The Python agent that runs on every managed node. It is a pure outbound HTTPS client: it long-polls the control plane for desired state, reconciles it with idempotent `ensure_*` operations and reports what it observed. See `docs/spec/HostMesh_v0.1.md`, sections 4 to 7, 26 and 37.

This is the skeleton. It has no client, no reconcile loop and no real Linux implementation yet.

## Layout

| Path                                                                        | Contents                                                               |
|-----------------------------------------------------------------------------|------------------------------------------------------------------------|
| `agent/services/`                                                           | Linux operations, one package per subsystem, each behind an interface. |
| `agent/reconciliation/`                                                     | Idempotent `ensure_*` operations, built on the service interfaces.     |
| `agent/client/`, `auth/`, `jobs/`, `resources/`, `monitoring/`, `security/` | Empty until their issues land.                                         |
| `tests/unit/`                                                               | Run against fakes. No real system calls.                               |
| `tests/contracts/`                                                          | Validate the JSON Schemas in `contracts/agent-protocol/` (from #39).   |
| `tests/integration/`                                                        | Need a real Debian 13 node. Not run by `make check`.                   |
| `tests/support/`                                                            | Test helpers and reusable contract classes.                            |

## Commands

uv runs on the host and installs Python 3.13 itself. From the repository root:

```bash
make -C agent install
make -C agent check
```

| Target              | What it does                                                             |
|---------------------|--------------------------------------------------------------------------|
| `check`             | `lint`, `typecheck` and `test-coverage`. Run it before every push.       |
| `install`           | `uv sync --locked`: create the environment from `uv.lock`.               |
| `lint` / `lint-fix` | ruff: check or fix lint rules and formatting.                            |
| `typecheck`         | mypy in strict mode over `agent/` and `tests/`.                          |
| `test`              | pytest: unit and contract tests.                                         |
| `test-coverage`     | The same with branch coverage; fails below 85%.                          |

One test: `uv run --directory agent pytest -k <name>`.

## Requirements

uv 0.12. Python 3.13 is the minimum, because it is what Debian 13 ships. See [decision 0005](../docs/decisions/0005-python-3-13-and-uv-for-the-agent.md).
