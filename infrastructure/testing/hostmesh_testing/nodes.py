# SPDX-License-Identifier: Apache-2.0
"""Create, reset, destroy and run commands on the two test nodes."""

import os
import re
import time
from collections.abc import Callable, Mapping, Sequence
from dataclasses import dataclass
from typing import Literal, Self

from hostmesh_testing.incus import HarnessError, Result, Runner

NodeType = Literal["container", "vm"]

NODE_NAMES: tuple[str, ...] = ("hostmesh-node-1", "hostmesh-node-2")
SNAPSHOT = "clean"
CAPABILITIES_KEY = "user.hostmesh.capabilities"
IMAGE_KEY = "user.hostmesh.image"
DEFAULT_IMAGE = "images:debian/13"

_NODE_TYPES: dict[str, NodeType] = {"container": "container", "vm": "vm"}
# As `incus list -c t` prints them.
_INCUS_TYPES: dict[NodeType, str] = {"container": "CONTAINER", "vm": "VIRTUAL-MACHINE"}
# systemd has finished booting, whatever the outcome.
_BOOTED = frozenset({"running", "degraded"})


def _incus(runner: Runner, args: Sequence[str], *, timeout: float = 120.0) -> Result:
    """Runs an incus command that must succeed."""
    result = runner.run(args, timeout=timeout)
    if result.returncode != 0:
        raise HarnessError(
            f"incus {' '.join(args)} failed: {result.stderr.strip() or result.stdout.strip()}"
        )
    return result


class CommandFailed(HarnessError):  # noqa: N818 - reads as a statement, like the result it carries
    """A command on a node exited non-zero."""

    def __init__(self, node: str, argv: Sequence[str], result: Result) -> None:
        super().__init__(
            f"{node}: {' '.join(argv)} exited {result.returncode}: "
            f"{result.stderr.strip() or result.stdout.strip()}"
        )
        self.result = result


@dataclass(frozen=True, slots=True)
class Config:
    """What the nodes are made of. Decision 0015 fixes the defaults."""

    node_type: NodeType = "container"
    image: str = DEFAULT_IMAGE
    memory: str = "2GiB"
    cpus: int = 2
    boot_timeout: float = 180.0
    capabilities: tuple[str, ...] = ("dns",)

    @classmethod
    def from_environment(cls, environ: Mapping[str, str]) -> Self:
        """Reads HOSTMESH_NODE_TYPE and HOSTMESH_NODE_IMAGE."""
        wanted = environ.get("HOSTMESH_NODE_TYPE", "container")
        node_type = _NODE_TYPES.get(wanted)
        if node_type is None:
            raise HarnessError(f"HOSTMESH_NODE_TYPE must be 'container' or 'vm', got '{wanted}'")
        return cls(node_type=node_type, image=environ.get("HOSTMESH_NODE_IMAGE", DEFAULT_IMAGE))


@dataclass(frozen=True, slots=True)
class Node:
    """One test node."""

    name: str
    node_type: NodeType
    runner: Runner

    def run(
        self,
        argv: Sequence[str],
        *,
        check: bool = True,
        stdin: str | None = None,
        timeout: float = 120.0,
    ) -> Result:
        """Runs a command on the node as root. `argv` is a list, never a shell line."""
        result = self.runner.run(["exec", self.name, "--", *argv], stdin=stdin, timeout=timeout)
        if check and result.returncode != 0:
            raise CommandFailed(self.name, argv, result)
        return result

    @property
    def capabilities(self) -> frozenset[str]:
        """The node's capabilities (spec section 9), as recorded on the instance."""
        value = _incus(self.runner, ["config", "get", self.name, CAPABILITIES_KEY]).stdout
        return frozenset(part for part in value.strip().split(",") if part)

    def address(self, *, timeout: float = 30.0) -> str:
        """The node's IPv4 address on the Incus bridge."""
        deadline = time.monotonic() + timeout
        while True:
            listed = _incus(self.runner, ["list", f"^{self.name}$", "-f", "csv", "-c", "4"]).stdout
            # One address prints as `10.0.0.2 (eth0)`, several as a quoted, multi-line cell.
            words = listed.replace('"', " ").split()
            if words:
                return words[0]
            if time.monotonic() >= deadline:
                raise HarnessError(
                    f"{self.name} has no IPv4 address after {timeout:g}s. "
                    "Where firewalld is active, is incusbr0 in the trusted zone?"
                )
            time.sleep(1.0)


def _kvm_writable() -> bool:
    return os.access("/dev/kvm", os.W_OK)


def _silent(_line: str) -> None:
    return None


class NodeHarness:
    """The two nodes, as one unit."""

    def __init__(
        self,
        runner: Runner,
        config: Config | None = None,
        *,
        clock: Callable[[], float] = time.monotonic,
        sleep: Callable[[float], None] = time.sleep,
        kvm_writable: Callable[[], bool] = _kvm_writable,
        report: Callable[[str], None] = _silent,
    ) -> None:
        self._runner = runner
        self._config = config or Config()
        self._clock = clock
        self._sleep = sleep
        self._kvm_writable = kvm_writable
        self._report = report

    @property
    def nodes(self) -> tuple[Node, ...]:
        return tuple(Node(name, self._config.node_type, self._runner) for name in NODE_NAMES)

    def node(self, name: str) -> Node:
        if name not in NODE_NAMES:
            raise HarnessError(f"unknown node '{name}': the nodes are {', '.join(NODE_NAMES)}")
        return Node(name, self._config.node_type, self._runner)

    def up(self) -> None:
        """Ends with two clean, running nodes: creates what is missing, resets the rest."""
        self._check_host()
        missing, present = self._survey(recreate=True)
        if missing:
            self._timed("image", self._fetch_image)
            self._timed("create", lambda: self._create(missing))
        if present:
            self._timed("reset", lambda: self._reset(present))

    def create(self) -> None:
        """Creates the nodes that are missing. Existing nodes are left as they are."""
        self._check_host()
        missing, _ = self._survey(recreate=True)
        if missing:
            self._fetch_image()
            self._create(missing)

    def reset(self) -> None:
        """Restores both nodes to their clean snapshot."""
        missing, present = self._survey(recreate=False)
        if missing:
            raise HarnessError(
                f"{', '.join(missing)} cannot be reset: missing, or without a "
                f"'{SNAPSHOT}' snapshot. Run 'up' first."
            )
        self._reset(present)

    def destroy(self) -> None:
        """Deletes both nodes. The downloaded image is kept."""
        for name in NODE_NAMES:
            if self._describe(name) is not None:
                _incus(self._runner, ["delete", "--force", name])

    def _check_host(self) -> None:
        if self._config.node_type == "vm" and not self._kvm_writable():
            raise HarnessError(
                "/dev/kvm is missing or not writable: virtual machines need KVM. "
                "Use HOSTMESH_NODE_TYPE=container."
            )

    def _timed(self, phase: str, action: Callable[[], None]) -> None:
        start = self._clock()
        action()
        self._report(f"TIME {phase} {self._clock() - start:.0f}")

    def _describe(self, name: str) -> tuple[str, str] | None:
        """The instance's type and state as Incus prints them, or None when it does not exist."""
        listed = _incus(self._runner, ["list", f"^{name}$", "-f", "csv", "-c", "ts"]).stdout.strip()
        if not listed:
            return None
        incus_type, _, state = listed.partition(",")
        return incus_type, state

    def _survey(self, *, recreate: bool) -> tuple[list[str], list[str]]:
        """Sorts the node names into those to create and those to reset."""
        missing: list[str] = []
        present: list[str] = []
        wanted = _INCUS_TYPES[self._config.node_type]
        for name in NODE_NAMES:
            described = self._describe(name)
            if described is None:
                missing.append(name)
                continue
            if not described[0].startswith(wanted):
                raise HarnessError(
                    f"{name} exists as {described[0]}, not {wanted}. Run 'destroy' first."
                )
            image = _incus(self._runner, ["config", "get", name, IMAGE_KEY]).stdout.strip()
            if image != self._config.image:
                raise HarnessError(
                    f"{name} was created from '{image}', not '{self._config.image}'. "
                    "Run 'destroy' first."
                )
            if self._runner.run(["snapshot", "show", name, SNAPSHOT]).returncode != 0:
                # An earlier run was interrupted before the snapshot: there is no
                # clean state to go back to, so the node is made again.
                if recreate:
                    _incus(self._runner, ["delete", "--force", name])
                missing.append(name)
                continue
            present.append(name)
        return missing, present

    def _image_alias(self) -> str:
        """The local copy of the image: one per node type and image name."""
        return (
            f"hostmesh-{self._config.node_type}-{re.sub(r'[^A-Za-z0-9]', '-', self._config.image)}"
        )

    def _type_flags(self) -> list[str]:
        return ["--vm"] if self._config.node_type == "vm" else []

    def _fetch_image(self) -> None:
        # Kept under a name of its own and not refreshed, so that creating a
        # node never waits for a download: delete the alias to get a newer build.
        alias = self._image_alias()
        if self._runner.run(["image", "info", alias]).returncode == 0:
            return
        _incus(
            self._runner,
            [
                "image",
                "copy",
                "--quiet",
                self._config.image,
                "local:",
                "--alias",
                alias,
                *self._type_flags(),
                "--auto-update=false",
            ],
            timeout=900.0,
        )

    def _create(self, names: Sequence[str]) -> None:
        config = self._config
        for name in names:
            _incus(
                self._runner,
                [
                    "launch",
                    "--quiet",
                    self._image_alias(),
                    name,
                    *self._type_flags(),
                    "-c",
                    f"limits.memory={config.memory}",
                    "-c",
                    f"limits.cpu={config.cpus}",
                    "-c",
                    f"{IMAGE_KEY}={config.image}",
                    "-c",
                    f"{CAPABILITIES_KEY}={','.join(config.capabilities)}",
                ],
                timeout=300.0,
            )
        for name in names:
            self._wait_until_booted(name)
            # The snapshot is taken with the node stopped: a restore then
            # starts from a clean boot and not from a frozen moment.
            _incus(self._runner, ["stop", name])
            _incus(self._runner, ["snapshot", "create", name, SNAPSHOT])
            _incus(self._runner, ["start", name])
        for name in names:
            self._wait_until_booted(name)

    def _reset(self, names: Sequence[str]) -> None:
        for name in names:
            described = self._describe(name)
            if described is not None and described[1] == "RUNNING":
                _incus(self._runner, ["stop", "--force", name])
            _incus(self._runner, ["snapshot", "restore", name, SNAPSHOT])
            _incus(self._runner, ["start", name])
        for name in names:
            self._wait_until_booted(name)

    def _wait_until_booted(self, name: str) -> None:
        """Waits until commands run on the node and systemd has finished booting."""
        timeout = self._config.boot_timeout
        deadline = self._clock() + timeout
        state = ""
        while self._clock() < deadline:
            # A virtual machine's agent starts after its kernel: no answer yet is not an error.
            answer = self._runner.run(["exec", name, "--", "systemctl", "is-system-running"])
            state = answer.stdout.strip()
            if state in _BOOTED:
                return
            self._sleep(1.0)
        raise HarnessError(
            f"{name} did not finish booting within {timeout:g}s "
            f"(last state: '{state or 'no answer'}')"
        )
