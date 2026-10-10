# SPDX-License-Identifier: Apache-2.0
"""The command line, against the in-memory Incus."""

import pytest

from hostmesh_testing.__main__ import main
from hostmesh_testing.incus import Result
from hostmesh_testing.nodes import NODE_NAMES
from tests.fake_incus import FakeIncus

NODE_1 = NODE_NAMES[0]


def test_up_creates_the_nodes_and_prints_the_timings(capsys: pytest.CaptureFixture[str]) -> None:
    fake = FakeIncus()

    assert main(["up"], runner=fake) == 0

    assert sorted(fake.instances) == list(NODE_NAMES)
    assert [line.split()[:2] for line in capsys.readouterr().out.splitlines()] == [
        ["TIME", "image"],
        ["TIME", "create"],
    ]


def test_destroy_removes_them() -> None:
    fake = FakeIncus()
    main(["up"], runner=fake)

    assert main(["destroy"], runner=fake) == 0
    assert fake.instances == {}


def test_exec_passes_the_command_its_output_and_its_exit_code(
    capsys: pytest.CaptureFixture[str],
) -> None:
    fake = FakeIncus()
    fake.exec_result = Result(4, "to stdout\n", "to stderr\n")

    assert main(["exec", NODE_1, "--", "ls", "-l", "/nope"], runner=fake) == 4

    assert fake.calls == [("exec", NODE_1, "--", "ls", "-l", "/nope")]
    captured = capsys.readouterr()
    assert (captured.out, captured.err) == ("to stdout\n", "to stderr\n")


def test_status_prints_what_incus_lists(capsys: pytest.CaptureFixture[str]) -> None:
    assert main(["status"], runner=FakeIncus()) == 0
    assert capsys.readouterr().out == "NAME  STATE\n"


def test_a_harness_error_is_one_line_on_stderr_and_exit_code_1(
    capsys: pytest.CaptureFixture[str],
) -> None:
    assert main(["reset"], runner=FakeIncus()) == 1

    error = capsys.readouterr().err
    assert error.startswith("hostmesh_testing: hostmesh-node-1, hostmesh-node-2 cannot be reset")
    assert "Traceback" not in error
