# SPDX-License-Identifier: Apache-2.0
"""The one place the harness starts a process: the `incus` command line."""

from collections.abc import Sequence
from dataclasses import dataclass
from typing import Protocol


class HarnessError(RuntimeError):
    """The harness cannot do what was asked. The message says what to do next."""


@dataclass(frozen=True, slots=True)
class Result:
    """What a command left behind."""

    returncode: int
    stdout: str
    stderr: str


class Runner(Protocol):
    """Runs `incus` with the given arguments and never raises on a non-zero exit."""

    def run(
        self, args: Sequence[str], *, stdin: str | None = None, timeout: float = 120.0
    ) -> Result: ...


class IncusCli:
    """The real `incus` command."""

    def run(
        self, args: Sequence[str], *, stdin: str | None = None, timeout: float = 120.0
    ) -> Result:
        raise NotImplementedError
