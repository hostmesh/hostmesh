# SPDX-License-Identifier: Apache-2.0
"""The SPDX identifier check over a list of tracked files."""

import subprocess
from pathlib import Path

import pytest

from hostmesh_hygiene.__main__ import main
from hostmesh_hygiene.spdx import (
    Exclusion,
    SpdxConfig,
    SpdxConfigError,
    check,
    load_config,
    tracked_files,
)

APACHE = "SPDX-License-Identifier: Apache-2.0"

CONFIG = SpdxConfig(
    extensions=frozenset({".py", ".php", ".vue", ".css", ".yml"}),
    filenames=frozenset({"Makefile"}),
    identifiers=frozenset({"Apache-2.0", "Apache-2.0 AND MIT"}),
    exclusions=(
        Exclusion("vendor/*", "third party"),
        Exclusion("web/pnpm-lock.yml", "lock file"),
    ),
)


def _tree(root: Path, files: dict[str, str]) -> list[str]:
    for name, text in files.items():
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text, encoding="utf-8")
    return sorted(files)


def _base() -> dict[str, str]:
    """A tree in which every exclusion of CONFIG matches something."""
    return {"vendor/lib.py": "x = 1\n", "web/pnpm-lock.yml": "lockfileVersion: 9\n"}


@pytest.mark.parametrize(
    ("name", "text"),
    [
        ("a.py", f"# {APACHE}\nx = 1\n"),
        ("a.php", f"<?php\n\n// {APACHE}\n\ndeclare(strict_types=1);\n"),
        ("a.vue", f"<!-- {APACHE} -->\n<template />\n"),
        ("a.vue", "<!--\n  SPDX-License-Identifier: Apache-2.0 AND MIT\n  Copyright x\n-->\n"),
        ("a.css", f"/*\n * {APACHE}\n */\n"),
        ("a.css", f"/* {APACHE} */\n"),
        ("Makefile", f"# {APACHE}\n"),
        ("deep/down/Makefile", f"# {APACHE}\r\n"),
        ("a.py", f"#!/usr/bin/env python3\n# {APACHE}   \n"),
    ],
)
def test_an_identifier_in_any_comment_style_is_found(tmp_path: Path, name: str, text: str) -> None:
    files = _tree(tmp_path, _base() | {name: text})

    assert check(files, tmp_path, CONFIG) == []


def test_a_source_file_without_an_identifier_is_reported(tmp_path: Path) -> None:
    files = _tree(tmp_path, _base() | {"src/a.py": "x = 1\n", "src/b.py": f"# {APACHE}\n"})

    assert check(files, tmp_path, CONFIG) == [
        "src/a.py: no SPDX-License-Identifier in the first 10 lines"
    ]


def test_an_identifier_below_the_tenth_line_does_not_count(tmp_path: Path) -> None:
    files = _tree(tmp_path, _base() | {"a.py": "\n" * 10 + f"# {APACHE}\n"})

    assert len(check(files, tmp_path, CONFIG)) == 1


def test_an_identifier_that_is_not_configured_is_reported(tmp_path: Path) -> None:
    files = _tree(tmp_path, _base() | {"a.py": "# SPDX-License-Identifier: GPL-3.0-only\n"})

    assert check(files, tmp_path, CONFIG) == [
        "a.py: SPDX-License-Identifier is 'GPL-3.0-only', expected one of: "
        "Apache-2.0, Apache-2.0 AND MIT"
    ]


def test_files_of_other_types_need_nothing(tmp_path: Path) -> None:
    files = _tree(
        tmp_path, _base() | {"README.md": "# x\n", "data.json": "{}\n", ".gitignore": "x\n"}
    )

    assert check(files, tmp_path, CONFIG) == []


def test_excluded_files_need_nothing_and_may_keep_their_own_identifier(tmp_path: Path) -> None:
    files = _tree(
        tmp_path,
        _base() | {"vendor/deep/mit.py": "# SPDX-License-Identifier: MIT\n"},
    )

    assert check(files, tmp_path, CONFIG) == []


def test_an_excluded_file_must_not_claim_to_be_apache_alone(tmp_path: Path) -> None:
    files = _tree(tmp_path, _base() | {"vendor/ours.py": f"# {APACHE}\n"})

    assert check(files, tmp_path, CONFIG) == [
        "vendor/ours.py: excluded (third party) but says Apache-2.0; "
        "remove the header or the exclusion"
    ]


def test_an_exclusion_that_matches_nothing_is_reported(tmp_path: Path) -> None:
    files = _tree(tmp_path, {"vendor/lib.py": "x = 1\n"})

    assert check(files, tmp_path, CONFIG) == [
        "spdx.toml: the exclusion 'web/pnpm-lock.yml' matches no tracked file"
    ]


def test_a_file_that_is_not_utf8_does_not_crash(tmp_path: Path) -> None:
    files = _tree(tmp_path, _base())
    (tmp_path / "latin.py").write_bytes(b"# caf\xe9\nx = 1\n")
    (tmp_path / "ok.py").write_bytes(b"# " + APACHE.encode() + b"\n# caf\xe9\n")

    assert check([*files, "latin.py", "ok.py"], tmp_path, CONFIG) == [
        "latin.py: no SPDX-License-Identifier in the first 10 lines"
    ]


def test_a_tracked_file_missing_from_disk_is_skipped(tmp_path: Path) -> None:
    files = _tree(tmp_path, _base())

    assert check([*files, "deleted.py"], tmp_path, CONFIG) == []


def test_an_empty_source_file_is_reported(tmp_path: Path) -> None:
    files = _tree(tmp_path, _base() | {"pkg/__init__.py": ""})

    assert len(check(files, tmp_path, CONFIG)) == 1


def test_the_config_file_loads(tmp_path: Path) -> None:
    path = tmp_path / "spdx.toml"
    path.write_text(
        'extensions = [".py"]\nfilenames = ["Makefile"]\nidentifiers = ["Apache-2.0"]\n'
        '[[exclude]]\npattern = "vendor/*"\nreason = "third party"\n',
        encoding="utf-8",
    )

    assert load_config(path) == SpdxConfig(
        frozenset({".py"}),
        frozenset({"Makefile"}),
        frozenset({"Apache-2.0"}),
        (Exclusion("vendor/*", "third party"),),
    )


@pytest.mark.parametrize(
    ("text", "message"),
    [
        ('filenames = []\nidentifiers = ["Apache-2.0"]', "extensions"),
        ('extensions = [".py"]\nfilenames = []\nidentifiers = []', "identifiers"),
        (
            'extensions = [".py"]\nfilenames = []\nidentifiers = ["Apache-2.0"]\n'
            '[[exclude]]\npattern = "vendor/*"',
            "reason",
        ),
        (
            'extensions = ["py"]\nfilenames = []\nidentifiers = ["Apache-2.0"]',
            "start with a dot",
        ),
    ],
)
def test_a_malformed_config_is_refused(tmp_path: Path, text: str, message: str) -> None:
    path = tmp_path / "spdx.toml"
    path.write_text(text, encoding="utf-8")

    with pytest.raises(SpdxConfigError, match=message):
        load_config(path)


def test_tracked_files_lists_what_git_tracks() -> None:
    root = Path(__file__).resolve().parents[3]

    files = tracked_files(root)

    assert "LICENSE" in files
    assert "infrastructure/hygiene/pyproject.toml" in files
    assert not any(name.startswith("control-plane/vendor/") for name in files)


def test_the_command_reports_problems_and_exits_1(
    tmp_path: Path, capsys: pytest.CaptureFixture[str]
) -> None:
    (tmp_path / "a.py").write_text("x = 1\n", encoding="utf-8")
    (tmp_path / "spdx.toml").write_text(
        'extensions = [".py"]\nidentifiers = ["Apache-2.0"]\n', encoding="utf-8"
    )
    for command in (["init", "-q"], ["add", "a.py"]):
        subprocess.run(  # noqa: S603 - an argument list, never a shell
            ["git", "-C", str(tmp_path), *command],  # noqa: S607 - git from PATH
            check=True,
        )

    code = main(["spdx", "--config", str(tmp_path / "spdx.toml"), "--repo-root", str(tmp_path)])

    assert code == 1
    assert "a.py: no SPDX-License-Identifier" in capsys.readouterr().err
