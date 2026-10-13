# Hostmesh node agent

The Python agent that runs on every managed node. It is a pure outbound HTTPS client: it long-polls the control plane for desired state, reconciles it with idempotent `ensure_*` operations and reports what it observed. See `docs/spec/HostMesh_v0.1.md`, sections 4 to 7, 26 and 37.

This is the skeleton. It has no client, no reconcile loop and no real Linux implementation yet.

## Layout

| Path                                                                        | Contents                                                                                                                                       |
|-----------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------|
| `agent/services/`                                                           | Linux operations, one package per subsystem, each behind an interface.                                                                         |
| `agent/reconciliation/`                                                     | Idempotent `ensure_*` operations, built on the service interfaces.                                                                             |
| `agent/client/`, `auth/`, `jobs/`, `resources/`, `monitoring/`, `security/` | Empty until their issues land.                                                                                                                 |
| `tests/unit/`                                                               | Run against fakes. No real system calls.                                                                                                       |
| `tests/contracts/`                                                          | Validate the JSON Schemas in `contracts/agent-protocol/` (from #39).                                                                           |
| `tests/integration/`                                                        | Marker `integration`: need the two Debian 13 test nodes. Marker `doubles`: need Pebble and the challenge test server. Not run by `make check`. |
| `tests/support/`                                                            | Test helpers and reusable contract classes.                                                                                                    |

## Commands

uv runs on the host and installs Python 3.13 itself. From the repository root:

```bash
make -C agent install
make -C agent check
```

| Target              | What it does                                                                                                                                                                                                                                     |
|---------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `check`             | `lint`, `typecheck` and `test-coverage`. Run it before every push.                                                                                                                                                                               |
| `install`           | `uv sync --locked`: create the environment from `uv.lock`.                                                                                                                                                                                       |
| `lint` / `lint-fix` | ruff: check or fix lint rules and formatting.                                                                                                                                                                                                    |
| `typecheck`         | mypy in strict mode over `agent/` and `tests/`.                                                                                                                                                                                                  |
| `test`              | pytest: unit and contract tests.                                                                                                                                                                                                                 |
| `test-coverage`     | The same with branch coverage; fails below 95%.                                                                                                                                                                                                  |
| `test-integration`  | The tests marked `integration`, on the two Debian 13 test nodes. Needs Incus: see [`infrastructure/testing/README.md`](../infrastructure/testing/README.md). `HOSTMESH_NODE_TYPE=vm` runs them on virtual machines.                              |
| `test-doubles`      | The tests marked `doubles`, against Pebble and the challenge test server. Start them with `make -C infrastructure/development doubles`: see "Test doubles" in [`infrastructure/development/README.md`](../infrastructure/development/README.md). |

One test: `uv run --directory agent pytest -k <name>`.

The agent's own command line has one command so far: `uv run --directory agent hostmesh-agent version`.

## Requirements

uv 0.12. Python 3.13 is the minimum, because it is what Debian 13 ships. See [decision 0005](../docs/decisions/0005-python-3-13-and-uv-for-the-agent.md).
The integration tests also need Incus (for `test-integration`) or Docker (for `test-doubles`). The unit and contract tests need neither.

## Rules

- **No inbound listener, ever.** `tests/unit/test_no_listener.py` starts the run loop and fails if the process holds a listening TCP socket or a bound UDP socket, on IPv4 or IPv6. Unix sockets and outbound connections are allowed. The detector in `tests/support/listeners.py` has its own tests, which open loopback sockets inside the test process; that is the only place unit tests touch real sockets.
- **Typed operations only.** No method takes a shell command string. ruff bans `os.system`, `os.popen`, `subprocess.getoutput`, `asyncio.create_subprocess_shell` and `shell=True`.
- **Every Linux operation sits behind an interface with an in-memory fake.** The Protocol is in `agent/services/<name>/interface.py` and the fake in `fake.py`. The fakes share a `Journal` that records every mutation and can fail any one of them. Behavior tests are contract classes in `tests/support/`, so the real implementation is held to the same tests as the fake. The fakes and the journal are test doubles: they are excluded from the coverage measurement.
- **Every `ensure_*` operation is tested for:** create, no-op when converged, drift repair, apply twice, and resume after an interruption at every step, plus a Hypothesis property over arbitrary starting states. `agent/reconciliation/user.py` and its tests are the example to copy.
- **Service interfaces and `ensure_*` operations are synchronous.** The reconcile loop will run them in a worker thread.
- **Integration tests never reach a production service.** ACME goes to Pebble and DNS to the challenge test server (spec section 37). `acme`, `josepy`, `cryptography` and `dnspython` are development dependencies for those tests; none of them is a choice of production library.
