# SPDX-License-Identifier: Apache-2.0
"""IncusCli against a stand-in `incus` executable on PATH."""

import os
import stat
from pathlib import Path

import pytest

from hostmesh_testing.incus import HarnessError, IncusCli


def _install(directory: Path, script: str, monkeypatch: pytest.MonkeyPatch) -> None:
    executable = directory / "incus"
    executable.write_text(f"#!/bin/sh\n{script}\n")
    executable.chmod(executable.stat().st_mode | stat.S_IXUSR)
    monkeypatch.setenv("PATH", f"{directory}{os.pathsep}{os.environ['PATH']}")


def test_it_returns_the_exit_code_and_both_streams(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    _install(tmp_path, 'echo "out: $*"; echo "err" >&2; exit 3', monkeypatch)

    result = IncusCli().run(["list", "--format", "csv"])

    assert (result.returncode, result.stdout, result.stderr) == (
        3,
        "out: list --format csv\n",
        "err\n",
    )


def test_the_command_gets_a_closed_standard_input(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    # `cat` returns at once only when its standard input is closed or empty.
    # A child process inherits file descriptor 0, so put a pipe that never
    # ends there: without a closed standard input the command would hang
    # until the timeout.
    _install(tmp_path, "cat; echo done", monkeypatch)
    read_end, write_end = os.pipe()
    saved = os.dup(0)
    os.dup2(read_end, 0)

    try:
        result = IncusCli().run(["launch"], timeout=5.0)
    finally:
        os.dup2(saved, 0)
        for descriptor in (saved, read_end, write_end):
            os.close(descriptor)

    assert result.stdout == "done\n"


def test_text_given_as_stdin_reaches_the_command(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    _install(tmp_path, "cat", monkeypatch)

    assert IncusCli().run(["exec"], stdin="hello").stdout == "hello"


def test_a_command_that_runs_too_long_is_reported(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    _install(tmp_path, "sleep 5", monkeypatch)

    with pytest.raises(HarnessError, match=r"incus start node did not finish within 0\.2s"):
        IncusCli().run(["start", "node"], timeout=0.2)


def test_a_missing_incus_says_where_to_look(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setenv("PATH", str(tmp_path))

    with pytest.raises(
        HarnessError, match=r"incus is not installed.*infrastructure/testing/README\.md"
    ):
        IncusCli()
