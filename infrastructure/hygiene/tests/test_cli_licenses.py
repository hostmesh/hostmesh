# SPDX-License-Identifier: Apache-2.0
"""`python -m hostmesh_hygiene licenses`, against a miniature repository."""

import json
from pathlib import Path

import pytest

from hostmesh_hygiene.__main__ import main

POLICY = """
allowed = ["MIT", "BSD-3-Clause"]

[[exceptions]]
ecosystem = "python"
package = "typing-extensions"
license = "PSF-2.0"
reason = "Permissive."
"""


def _dist(repo: Path, project: str, name: str, license_expression: str) -> None:
    directory = repo / project / ".venv/lib/python3.13/site-packages" / f"{name}-1.0.dist-info"
    directory.mkdir(parents=True)
    (directory / "METADATA").write_text(
        f"Metadata-Version: 2.4\nName: {name}\nVersion: 1.0\n"
        f"License-Expression: {license_expression}\n",
        encoding="utf-8",
    )


@pytest.fixture
def repo(tmp_path: Path) -> Path:
    inputs = tmp_path / "inputs"
    inputs.mkdir()
    composer = {"dependencies": {"brick/math": {"version": "1.0.0", "license": ["MIT"]}}}
    pnpm = {"MIT": [{"name": "vue", "versions": ["3.5.43"], "paths": [], "license": "MIT"}]}
    for name, data in (
        ("composer.json", composer),
        ("composer-prod.json", composer),
        ("pnpm.json", pnpm),
        ("pnpm-prod.json", pnpm),
    ):
        (inputs / name).write_text(json.dumps(data), encoding="utf-8")
    (inputs / "python-prod.txt").write_text("httpx==1.0\n", encoding="utf-8")
    (tmp_path / "licenses.toml").write_text(POLICY, encoding="utf-8")
    _dist(tmp_path, "agent", "httpx", "BSD-3-Clause")
    _dist(tmp_path, "agent", "typing_extensions", "PSF-2.0")
    _dist(tmp_path, "infrastructure/testing", "pytest", "MIT")
    _dist(tmp_path, "infrastructure/hygiene", "pytest", "MIT")
    return tmp_path


def _run(repo: Path) -> int:
    return main(
        [
            "licenses",
            "--policy",
            str(repo / "licenses.toml"),
            "--inputs",
            str(repo / "inputs"),
            "--repo-root",
            str(repo),
        ]
    )


def test_a_clean_tree_passes_and_says_how_much_it_checked(
    repo: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    assert _run(repo) == 0
    assert "5 packages" in capsys.readouterr().out


def test_a_disallowed_dependency_fails_and_is_named(
    repo: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    _dist(repo, "infrastructure/hygiene", "pylint", "GPL-2.0-or-later")

    assert _run(repo) == 1
    error = capsys.readouterr().err
    assert "python pylint 1.0: license 'GPL-2.0-or-later' is not on the allowlist" in error
    assert "infrastructure/hygiene/README.md" in error


def test_an_unused_exception_fails_and_is_named(
    repo: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    policy = repo / "licenses.toml"
    policy.write_text(
        policy.read_text(encoding="utf-8")
        + '\n[[exceptions]]\necosystem = "npm"\npackage = "left-pad"\n'
        'license = "WTFPL"\nreason = "Gone."\n',
        encoding="utf-8",
    )

    assert _run(repo) == 1
    assert "unused exception: npm left-pad (WTFPL)" in capsys.readouterr().err


@pytest.mark.parametrize(
    "missing", ["inputs/pnpm.json", "inputs/composer.json", "agent/.venv", "licenses.toml"]
)
def test_main_fails_when_an_input_is_missing(
    repo: Path, missing: str, capsys: pytest.CaptureFixture[str]
) -> None:
    target = repo / missing
    if target.is_dir():
        target.rename(repo / "moved-away")
    else:
        target.unlink()

    assert _run(repo) == 2
    assert Path(missing).name in capsys.readouterr().err
