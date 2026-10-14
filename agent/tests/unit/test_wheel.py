# SPDX-License-Identifier: Apache-2.0
"""The wheel holds the agent and none of its test doubles."""

import zipfile
from pathlib import Path, PurePosixPath

import pytest
from hatchling.builders.wheel import WheelBuilder

PROJECT = Path(__file__).resolve().parents[2]


@pytest.fixture(scope="module")
def wheel_files(tmp_path_factory: pytest.TempPathFactory) -> list[str]:
    """Build the wheel with the project's own backend and list what it holds."""
    directory = tmp_path_factory.mktemp("wheel")
    builder = WheelBuilder(str(PROJECT))
    (wheel,) = builder.build(directory=str(directory), versions=["standard"])
    with zipfile.ZipFile(wheel) as archive:
        return archive.namelist()


def test_the_wheel_holds_the_agent_package_and_its_metadata_only(wheel_files: list[str]) -> None:
    top_level = {PurePosixPath(name).parts[0] for name in wheel_files}

    assert "agent" in top_level
    assert {part for part in top_level if not part.endswith(".dist-info")} == {"agent"}
    assert "agent/services/filesystem/interface.py" in wheel_files


def test_the_wheel_holds_no_fake_and_no_journal(wheel_files: list[str]) -> None:
    doubles = [
        name
        for name in wheel_files
        if PurePosixPath(name).name.startswith("fake") or name == "agent/services/journal.py"
    ]

    assert doubles == []
