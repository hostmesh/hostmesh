# SPDX-License-Identifier: Apache-2.0
"""The hostmesh-agent command line: a version command, and nothing else yet."""

from importlib.metadata import entry_points, version

import pytest

from agent.cli import main


def test_version_prints_the_distribution_version(capsys: pytest.CaptureFixture[str]) -> None:
    exit_code = main(["version"])

    assert exit_code == 0
    assert capsys.readouterr().out == f"hostmesh-agent {version('hostmesh-agent')}\n"


@pytest.mark.parametrize("argv", [[], ["run"], ["--bogus"]], ids=["nothing", "unknown", "flag"])
def test_anything_else_is_a_usage_error(
    argv: list[str], capsys: pytest.CaptureFixture[str]
) -> None:
    with pytest.raises(SystemExit) as raised:
        main(argv)

    assert raised.value.code == 2
    assert "usage: hostmesh-agent" in capsys.readouterr().err


def test_the_console_script_points_at_the_cli() -> None:
    (script,) = entry_points(group="console_scripts", name="hostmesh-agent")

    assert script.value == "agent.cli:main"
    assert script.load() is main
