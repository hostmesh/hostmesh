# Test nodes

Two disposable Debian 13 nodes for the agent's integration tests (spec section 37), on [Incus](https://linuxcontainers.org/incus/), as [decision 0015](../../docs/decisions/0015-debian-test-harness.md) chose. `hostmesh_testing` is the Python module that creates, resets and destroys them and runs commands on them.

| | |
|---|---|
| Names | `hostmesh-node-1`, `hostmesh-node-2` |
| Image | `images:debian/13`, a daily build |
| Size | 2 GiB of RAM and 2 vCPUs each |
| Type | System containers by default; virtual machines with `HOSTMESH_NODE_TYPE=vm` |
| Capabilities | Both carry `dns` (spec section 9), as the Incus config key `user.hostmesh.capabilities` |
| Clean state | A snapshot named `clean`, taken stopped after the first boot. A reset restores it |

Containers run everything except filesystem user quotas and use the kernel of the host. Virtual machines run Debian's own kernel; the isolation suite and anything that needs a quota belong there.

## Host setup

Fedora 44:

    sudo dnf install -y incus incus-agent
    echo "root:1000000:1000000000" | sudo tee -a /etc/subuid /etc/subgid
    sudo systemctl enable --now incus.socket
    sudo usermod -aG incus-admin "$USER"
    sudo incus admin init --minimal
    sudo firewall-cmd --zone=trusted --change-interface=incusbr0 --permanent
    sudo firewall-cmd --reload

Ubuntu 24.04, as the CI workflow does it:

    sudo apt-get install -y incus            # add qemu-system for virtual machines
    sudo incus admin init --minimal
    sudo usermod -aG incus-admin "$USER"

Install Incus with its recommended packages: without them `incus admin init` fails for want of `dnsmasq`.

- **Membership of `incus-admin` is equivalent to root on your machine.** Anyone in that group can start a privileged instance and mount the host's filesystem in it.
- Log out and in for the group to apply, or run one command at once with `sg incus-admin -c '<command>'`.
- Where firewalld is active, the two `firewall-cmd` lines are needed: without them a node gets no IPv4 address and cannot resolve names.
- Virtual machines need a writable `/dev/kvm`.
- uv 0.12.18 or later, below 0.14, as for the agent. Tested with Incus 6.0.0 and 6.23.
- macOS is not supported yet (decision 0015).

## Commands

From the repository root:

| Command                                                                                                         | Effect                                                                                                                 |
|-----------------------------------------------------------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------|
| `make -C infrastructure/testing up`                                                                             | Create the nodes that are missing and reset the others. Prints `TIME image`, `TIME create` and `TIME reset` in seconds |
| `make -C infrastructure/testing reset`                                                                          | Restore both nodes to `clean`                                                                                          |
| `make -C infrastructure/testing status`                                                                         | List them                                                                                                              |
| `make -C infrastructure/testing destroy`                                                                        | Delete both                                                                                                            |
| `uv run --directory infrastructure/testing python -m hostmesh_testing exec hostmesh-node-1 -- systemctl status` | Run a command on a node                                                                                                |
| `make -C infrastructure/testing check`                                                                          | ruff, mypy and the harness's unit tests. Needs no Incus                                                                |
| `make -C agent test-integration`                                                                                | The agent's integration tests, on the nodes                                                                            |

| Variable              | Default            | Meaning                   |
|-----------------------|--------------------|---------------------------|
| `HOSTMESH_NODE_TYPE`  | `container`        | `container` or `vm`       |
| `HOSTMESH_NODE_IMAGE` | `images:debian/13` | Any Incus image reference |

The harness refuses to reuse nodes of another type or image: run `destroy` first, with the variables the nodes were created with.

`destroy` keeps the downloaded image (`incus image list` shows it as `hostmesh-<type>-<image>`). It is not refreshed: two machines may therefore run different builds of Debian 13. `incus image delete <alias>` makes the next `up` download the current build.

## In a test

The agent's `dev` dependencies include this package. `agent/tests/integration/conftest.py` provides:

| Fixture   | Scope    | Gives                                                                           |
|-----------|----------|---------------------------------------------------------------------------------|
| `harness` | session  | A `NodeHarness`, after `up()`. The nodes are left running at the end of the run |
| `nodes`   | session  | Both `Node` objects                                                             |
| `node`    | function | One `Node`; the test runs once on each                                          |

```python
import pytest
from hostmesh_testing import Node, NodeHarness

pytestmark = pytest.mark.integration


def test_nginx_is_not_installed_on_a_clean_node(node: Node) -> None:
    assert node.run(["which", "nginx"], check=False).returncode == 1


def test_something_that_dirties_the_nodes(harness: NodeHarness) -> None:
    harness.reset()
    ...
```

- `node.run(argv, check=True, stdin=None, timeout=120.0)` runs a command as root and returns `returncode`, `stdout` and `stderr`. `argv` is a list, never a shell line. With `check=True` a non-zero exit raises `CommandFailed`.
- `node.capabilities` is read from Incus each time. `node.address()` is the node's IPv4 address on the Incus bridge.
- `harness.reset()` takes about 4 seconds for two containers and 18 for two virtual machines on a developer machine (decision 0015). Call it at the start of a test group that needs clean nodes, not after every test.
- Mark every such test `integration`. `make -C agent check` does not run them.

## In CI

`.github/workflows/integration.yml` runs the integration tests on two containers on every pull request that touches the agent or this directory. It starts on every pull request, decides in its `changes` job whether the tests have anything to do, and reports the result as `Integration tests`, a required status check (decision 0017). `.github/workflows/integration-vm.yml` runs them on two virtual machines every night, on manual dispatch, and on a pull request that carries the label `vm-tests`.
