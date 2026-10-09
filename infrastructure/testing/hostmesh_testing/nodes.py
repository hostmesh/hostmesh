# SPDX-License-Identifier: Apache-2.0
"""Create, reset, destroy and run commands on the two test nodes."""

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
        raise NotImplementedError


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
        raise NotImplementedError

    @property
    def capabilities(self) -> frozenset[str]:
        raise NotImplementedError

    def address(self, *, timeout: float = 30.0) -> str:
        raise NotImplementedError


def _kvm_writable() -> bool:
    raise NotImplementedError


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
        raise NotImplementedError

    def node(self, name: str) -> Node:
        raise NotImplementedError

    def up(self) -> None:
        raise NotImplementedError

    def create(self) -> None:
        raise NotImplementedError

    def reset(self) -> None:
        raise NotImplementedError

    def destroy(self) -> None:
        raise NotImplementedError
