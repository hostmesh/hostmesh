# SPDX-License-Identifier: Apache-2.0
"""The one place the harness starts a process: the `incus` command line."""

import shutil
import subprocess
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

    def __init__(self) -> None:
        path = shutil.which("incus")
        if path is None:
            raise HarnessError(
                "incus is not installed, or not on PATH. See infrastructure/testing/README.md."
            )
        self._path = path

    def run(
        self, args: Sequence[str], *, stdin: str | None = None, timeout: float = 120.0
    ) -> Result:
        argv = [self._path, *args]
        try:
            if stdin is None:
                # `incus launch` and `incus snapshot create` read configuration from
                # standard input when it is not a terminal, and wait for it.
                completed = subprocess.run(  # noqa: S603 - an argument list, never a shell
                    argv,
                    stdin=subprocess.DEVNULL,
                    capture_output=True,
                    text=True,
                    timeout=timeout,
                    check=False,
                )
            else:
                completed = subprocess.run(  # noqa: S603 - an argument list, never a shell
                    argv,
                    input=stdin,
                    capture_output=True,
                    text=True,
                    timeout=timeout,
                    check=False,
                )
        except subprocess.TimeoutExpired as error:
            raise HarnessError(
                f"incus {' '.join(args)} did not finish within {timeout:g}s"
            ) from error
        return Result(completed.returncode, completed.stdout, completed.stderr)
