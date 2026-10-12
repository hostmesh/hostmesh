# SPDX-License-Identifier: Apache-2.0
"""`python -m hostmesh_hygiene notices`: production dependencies only, and the drift check."""

import json
from pathlib import Path

import pytest

from hostmesh_hygiene.__main__ import main

POLICY = """
allowed = ["MIT", "BSD-3-Clause"]

[[vendored]]
name = "shadcn-vue"
license = "MIT"
source = "https://github.com/unovue/shadcn-vue"
paths = ["web/packages/ui/src/components/ui/"]
license_file = "web/packages/ui/src/components/ui/LICENSE"
"""


def _dist(repo: Path, name: str) -> None:
    directory = repo / "agent/.venv/lib/python3.13/site-packages" / f"{name}-1.0.dist-info"
    (directory / "licenses").mkdir(parents=True)
    (directory / "METADATA").write_text(
        f"Metadata-Version: 2.4\nName: {name}\nVersion: 1.0\nLicense-Expression: MIT\n",
        encoding="utf-8",
    )
    (directory / "licenses/LICENSE").write_text(f"License of {name}\n", encoding="utf-8")


@pytest.fixture
def repo(tmp_path: Path) -> Path:
    inputs = tmp_path / "inputs"
    inputs.mkdir()
    (inputs / "composer-prod.json").write_text(
        json.dumps({"dependencies": {"brick/math": {"version": "1.0.0", "license": ["MIT"]}}}),
        encoding="utf-8",
    )
    (inputs / "pnpm-prod.json").write_text(
        json.dumps(
            {
                "MIT": [
                    {
                        "name": "vue",
                        "versions": ["3.5.43"],
                        "paths": ["/elsewhere/node_modules/.pnpm/vue@3.5.43/node_modules/vue"],
                        "license": "MIT",
                    }
                ]
            }
        ),
        encoding="utf-8",
    )
    (inputs / "python-prod.txt").write_text("httpx==1.0\n", encoding="utf-8")
    (tmp_path / "licenses.toml").write_text(POLICY, encoding="utf-8")

    math = tmp_path / "control-plane/vendor/brick/math"
    math.mkdir(parents=True)
    (math / "LICENSE").write_text("License of brick/math\n", encoding="utf-8")
    vue = tmp_path / "web/node_modules/.pnpm/vue@3.5.43/node_modules/vue"
    vue.mkdir(parents=True)
    (vue / "LICENSE").write_text("License of vue\n", encoding="utf-8")
    ui = tmp_path / "web/packages/ui/src/components/ui"
    ui.mkdir(parents=True)
    (ui / "LICENSE").write_text("License of shadcn-vue\n", encoding="utf-8")
    _dist(tmp_path, "httpx")
    _dist(tmp_path, "pytest")
    return tmp_path


def _run(repo: Path, *extra: str) -> int:
    return main(
        [
            "notices",
            "--policy",
            str(repo / "licenses.toml"),
            "--inputs",
            str(repo / "inputs"),
            "--repo-root",
            str(repo),
            "--output",
            str(repo / "THIRD_PARTY_NOTICES"),
            *extra,
        ]
    )


def test_it_writes_the_production_dependencies_and_the_vendored_source(repo: Path) -> None:
    assert _run(repo) == 0

    text = (repo / "THIRD_PARTY_NOTICES").read_text(encoding="utf-8")
    for expected in (
        "License of shadcn-vue",
        "License of brick/math",
        "License of vue",
        "License of httpx",
    ):
        assert expected in text
    # pytest is installed in the agent's environment, but the agent does not ship it.
    assert "License of pytest" not in text


def test_check_passes_on_a_current_file_and_writes_nothing(repo: Path) -> None:
    _run(repo)
    output = repo / "THIRD_PARTY_NOTICES"
    before = output.stat().st_mtime_ns

    assert _run(repo, "--check") == 0
    assert output.stat().st_mtime_ns == before


def test_check_fails_on_drift_and_leaves_the_file_alone(
    repo: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    _run(repo)
    output = repo / "THIRD_PARTY_NOTICES"
    output.write_text("edited by hand\n", encoding="utf-8")

    assert _run(repo, "--check") == 1
    assert output.read_text(encoding="utf-8") == "edited by hand\n"
    assert "make -C infrastructure/hygiene notices" in capsys.readouterr().err


def test_check_fails_when_the_file_does_not_exist(repo: Path) -> None:
    assert _run(repo, "--check") == 1


def test_a_production_package_that_is_not_installed_is_an_error(
    repo: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    (repo / "inputs/python-prod.txt").write_text("httpx==1.0\nanyio==4.0\n", encoding="utf-8")

    assert _run(repo) == 2
    assert "anyio" in capsys.readouterr().err
